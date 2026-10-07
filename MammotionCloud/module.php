<?php

// SPDX-License-Identifier: MIT
// Copyright (c) 2026 Armin Frohwerk

declare(strict_types=1);

require_once __DIR__ . '/../libs/PresentationHelper.php';

/**
 * Mammotion Cloud
 *
 * Verwaltet die Anmeldung an der Mammotion Open API (OAuth2 Client-Credentials),
 * cached den Access-Token und führt alle HTTP-Anfragen für untergeordnete
 * Mäher- und Konfigurator-Instanzen aus.
 */
class MammotionCloud extends IPSModuleStrict
{
    use MammotionPresentationHelper;

    private const MODULE_VERSION = '1.2';
    private const MODULE_BUILD = 4;
    private const AUTH_URL = 'https://id.mammotion.com/oauth2/token';
    private const API_URL = 'https://api-open.mammotion.com';
    private const DATA_TX = '{5F140107-E29A-41AA-9314-01891DDE02F9}';
    private const MOWER_MODULE = '{8297B983-0C40-4D50-8376-636028226AEE}';

    private const TOKEN_SAFETY_SECONDS = 300;
    private const RECONNECT_SECONDS = 600;      // erster neuer Anmeldeversuch nach abgelehnter Anmeldung
    private const RECONNECT_MAX_SECONDS = 21600; // Wartezeit verdoppelt sich bis höchstens 6 Stunden
    private const HTTP_CONNECT_TIMEOUT = 10;
    private const HTTP_TIMEOUT = 20;
    private const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;
    private const ALLOWED_PATH = '#^/v1/[A-Za-z0-9_\-./%]+$#';

    // Profile früherer Versionen, werden nach der Umstellung auf Darstellungen entfernt
    private const LEGACY_PROFILES = ['MAMCLOUD.State'];

    private const STATE_NOT_LOGGED_IN = 0;
    private const STATE_CONNECTED = 1;
    private const STATE_DISTURBED = 2;
    private const STATE_DISABLED = 3;
    private const STATE_ERROR = 4;
    private const STATE_NOTICE = 5;

    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_CONFIG = 200;
    private const STATUS_AUTH = 201;
    private const STATUS_NOTICE = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyBoolean('NoticeAccepted', false);

        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenValidUntil', 0);
        $this->RegisterAttributeString('CredentialHash', '');
        $this->RegisterAttributeInteger('AuthFailures', 0);
        $this->RegisterAttributeBoolean('LegacyCleanupDone', false);

        $this->RegisterTimer('ReconnectTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerReconnect", 0);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariable('CloudState', 'Verbindungsstatus', VARIABLETYPE_INTEGER, $this->PresentStates('cloud', [
            self::STATE_NOT_LOGGED_IN => ['Nicht angemeldet', 0xAAAAAA, 'cloud'],
            self::STATE_CONNECTED     => ['Verbunden', 0x00AA00, 'cloud-check'],
            self::STATE_DISTURBED     => ['Gestört', 0xFF8800, 'cloud-exclamation'],
            self::STATE_DISABLED      => ['Deaktiviert', 0x777777, 'cloud-slash'],
            self::STATE_ERROR         => ['Anmeldung fehlgeschlagen', 0xFF0000, 'cloud-xmark'],
            self::STATE_NOTICE        => ['Hinweis nicht bestätigt', 0xFFCC00, 'circle-info']
        ]), 10, true);
        $this->MaintainVariable('TokenValidUntil', 'Token gültig bis', VARIABLETYPE_INTEGER, $this->PresentDateTime(), 20, true);
        $this->MaintainVariable('LastRequest', 'Letzte erfolgreiche Anfrage', VARIABLETYPE_INTEGER, $this->PresentDateTime(true), 30, true);
        $this->MaintainVariable('LastError', 'Letzter Fehler', VARIABLETYPE_STRING, $this->PresentValue('triangle-exclamation'), 40, true);

        if (!$this->ReadAttributeBoolean('LegacyCleanupDone')) {
            $this->RemoveUnusedProfiles(self::LEGACY_PROFILES);
            $this->WriteAttributeBoolean('LegacyCleanupDone', true);
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->Initialize();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Initialize();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TimerReconnect':
                $this->TryReconnect();
                return;
        }
        throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
    }

    /**
     * Datenaustausch mit untergeordneten Instanzen (Mäher, Konfigurator).
     * Antwortet immer mit JSON: {"ok":true,"response":{...}} oder {"ok":false,"kind":"...","error":"..."}
     */
    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return $this->EncodeResult(['ok' => false, 'kind' => 'api', 'error' => 'Ungültige Anfrage an die Cloud-Instanz']);
        }

        try {
            $this->RequireReady();
            switch ((string) ($data['Command'] ?? '')) {
                case 'Request':
                    $method = strtoupper((string) ($data['Method'] ?? 'GET'));
                    $path = (string) ($data['Path'] ?? '');
                    $payload = is_array($data['Payload'] ?? null) ? $data['Payload'] : null;
                    $response = $this->ApiRequest($method, $path, $payload);
                    break;
                case 'GetMowers':
                    $response = $this->ApiRequest('GET', '/v1/mowers');
                    break;
                default:
                    throw new MammotionCloudException('Unbekannter Befehl an die Cloud-Instanz', MammotionCloudException::API);
            }
            return $this->EncodeResult(['ok' => true, 'response' => $response]);
        } catch (MammotionCloudException $e) {
            return $this->EncodeResult(['ok' => false, 'kind' => $e->Kind(), 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->RegisterError($e->getMessage(), self::STATE_DISTURBED);
            return $this->EncodeResult(['ok' => false, 'kind' => 'transient', 'error' => $e->getMessage()]);
        }
    }

    public function TestConnection(): string
    {
        try {
            $this->RequireReady();
            $response = $this->ApiRequest('GET', '/v1/mowers');
            $mowers = is_array($response['data'] ?? null) ? $response['data'] : [];
            $names = [];
            foreach ($mowers as $mower) {
                $name = trim((string) ($mower['nickname'] ?? ''));
                if ($name === '') {
                    $name = trim((string) ($mower['name'] ?? ($mower['id'] ?? '?')));
                }
                $names[] = $name;
            }
            return 'ERFOLG: ' . count($mowers) . ' Mäher gefunden' . (count($names) > 0 ? ' (' . implode(', ', $names) . ')' : '');
        } catch (Throwable $e) {
            return 'FEHLER: ' . $e->getMessage();
        }
    }

    public function RenewToken(): string
    {
        try {
            $this->RequireReady();
            $this->ClearTokenCache();
            $this->GetAccessToken(true);
            return 'ERFOLG: Token gültig bis ' . date('d.m.Y H:i:s', $this->ReadAttributeInteger('TokenValidUntil'));
        } catch (Throwable $e) {
            return 'FEHLER: ' . $e->getMessage();
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $lines = [];

        if (!$this->ReadPropertyBoolean('Active')) {
            $lines[] = 'Cloud: ⏸ Instanz ist deaktiviert';
        } elseif (!$this->ReadPropertyBoolean('NoticeAccepted')) {
            $lines[] = 'Cloud: ⚠️ Nutzungshinweis noch nicht bestätigt – es werden keine Anfragen gesendet';
        } elseif (!$this->HasCredentials()) {
            $lines[] = 'Cloud: ⚠️ Client-ID oder Client-Secret fehlt';
        } else {
            $state = (int) $this->GetValue('CloudState');
            $texts = [
                self::STATE_NOT_LOGGED_IN => '⏳ noch nicht angemeldet – erste Anfrage folgt mit dem nächsten Abruf',
                self::STATE_CONNECTED     => '✅ verbunden',
                self::STATE_DISTURBED     => '⚠️ gestört – vorübergehender Fehler, Mäher wiederholen selbstständig',
                self::STATE_ERROR         => '❌ Anmeldung fehlgeschlagen – neuer Versuch nach 10 Minuten, danach immer seltener (höchstens alle 6 Stunden)'
            ];
            $lines[] = 'Cloud: ' . ($texts[$state] ?? '❔ unbekannt');
            $mowers = count(array_filter(IPS_GetInstanceListByModuleID(self::MOWER_MODULE), function ($id) {
                return IPS_GetInstance($id)['ConnectionID'] === $this->InstanceID;
            }));
            $lines[] = 'Verbundene Mäher-Instanzen: ' . $mowers;
        }
        $validUntil = $this->ReadAttributeInteger('TokenValidUntil');
        $lines[] = 'Token: ' . ($validUntil > time() ? '🔒 gültig bis ' . date('d.m.Y H:i', $validUntil) : '— kein gültiger Token');
        $lastRequest = (int) $this->GetValue('LastRequest');
        $lines[] = 'Letzte erfolgreiche Anfrage: ' . ($lastRequest > 0 ? date('d.m.Y H:i:s', $lastRequest) : '—');
        $lastError = (string) $this->GetValue('LastError');
        if ($lastError !== '') {
            $lines[] = 'Letzter Fehler: ' . $lastError;
        }

        $this->FillPanel($form['elements'], 'StatusPanel', $lines);
        $this->ReplaceCaption($form['actions'], 'VersionLabel', 'Mammotion Open API v' . self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . '). Mäher über den „Mammotion Konfigurator“ anlegen.');
        return (string) json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function FillPanel(array &$elements, string $name, array $lines): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element['items'] = array_map(function ($line) {
                    return ['type' => 'Label', 'caption' => $line];
                }, $lines);
            }
        }
        unset($element);
    }

    private function ReplaceCaption(array &$elements, string $name, string $caption): void
    {
        foreach ($elements as &$element) {
            if (($element['name'] ?? '') === $name) {
                $element['caption'] = $caption;
            }
        }
        unset($element);
    }

    // ------------------------------------------------------------------
    // Initialisierung und Zustand
    // ------------------------------------------------------------------

    private function Initialize(): void
    {
        $this->SetTimerInterval('ReconnectTimer', 0);
        // „Übernehmen“ startet die Wartezeit nach abgelehnter Anmeldung neu
        $this->ResetAuthFailures();

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetValue('CloudState', self::STATE_DISABLED);
            $this->SetStatus(self::STATUS_INACTIVE);
            return;
        }

        if (!$this->ReadPropertyBoolean('NoticeAccepted')) {
            $this->SetValue('CloudState', self::STATE_NOTICE);
            $this->SetStatus(self::STATUS_NOTICE);
            return;
        }

        if (!$this->HasCredentials()) {
            $this->SetValue('CloudState', self::STATE_ERROR);
            $this->SetValue('LastError', 'Client-ID oder Client-Secret fehlt');
            $this->SetStatus(self::STATUS_CONFIG);
            return;
        }

        $hash = hash('sha256', $this->ReadPropertyString('ClientID') . '|' . $this->ReadPropertyString('ClientSecret'));
        if ($hash !== $this->ReadAttributeString('CredentialHash')) {
            $this->ClearTokenCache();
            $this->WriteAttributeString('CredentialHash', $hash);
        }

        $this->SetStatus(self::STATUS_ACTIVE);
        $this->SetValue('LastError', '');
        $this->SetValue('CloudState', $this->HasValidToken() ? self::STATE_CONNECTED : self::STATE_NOT_LOGGED_IN);
        $this->SetValue('TokenValidUntil', $this->ReadAttributeInteger('TokenValidUntil'));
    }

    private function TryReconnect(): void
    {
        if (!$this->ReadPropertyBoolean('Active') || !$this->ReadPropertyBoolean('NoticeAccepted') || !$this->HasCredentials()) {
            $this->SetTimerInterval('ReconnectTimer', 0);
            return;
        }
        try {
            $this->ClearTokenCache();
            $this->GetAccessToken(true);
        } catch (Throwable $e) {
            $this->SendDebug('Reconnect', $e->getMessage(), 0);
        }
    }

    private function RequireReady(): void
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            throw new MammotionCloudException('Cloud-Instanz ist deaktiviert', MammotionCloudException::DISABLED);
        }
        if (!$this->ReadPropertyBoolean('NoticeAccepted')) {
            throw new MammotionCloudException('Nutzungshinweis in der Cloud-Instanz ist noch nicht bestätigt', MammotionCloudException::DISABLED);
        }
        if (!$this->HasCredentials()) {
            throw new MammotionCloudException('Client-ID oder Client-Secret fehlt', MammotionCloudException::AUTH);
        }
    }

    private function HasCredentials(): bool
    {
        return trim($this->ReadPropertyString('ClientID')) !== '' && trim($this->ReadPropertyString('ClientSecret')) !== '';
    }

    private function HasValidToken(): bool
    {
        return $this->ReadAttributeString('AccessToken') !== ''
            && time() < ($this->ReadAttributeInteger('TokenValidUntil') - self::TOKEN_SAFETY_SECONDS);
    }

    private function RegisterSuccess(): void
    {
        if ($this->GetValue('CloudState') !== self::STATE_CONNECTED) {
            // Verbindung hat sich erholt: alte Fehlermeldung entfernen
            if ($this->GetValue('LastError') !== '') {
                $this->SetValue('LastError', '');
            }
            $this->SetValue('CloudState', self::STATE_CONNECTED);
        }
        $this->SetValue('LastRequest', time());
        if ($this->GetStatus() !== self::STATUS_ACTIVE) {
            $this->SetStatus(self::STATUS_ACTIVE);
        }
        $this->SetTimerInterval('ReconnectTimer', 0);
        $this->ResetAuthFailures();
    }

    private function ResetAuthFailures(): void
    {
        if ($this->ReadAttributeInteger('AuthFailures') !== 0) {
            $this->WriteAttributeInteger('AuthFailures', 0);
        }
    }

    private function RegisterError(string $message, int $state): void
    {
        $this->SetValue('CloudState', $state);
        $this->SetValue('LastError', date('d.m.Y H:i:s') . ': ' . $message);
        if ($state === self::STATE_ERROR) {
            // Anmeldung abgelehnt: Kinder pausieren und mit wachsendem Abstand neu versuchen
            // (10 Minuten, 20, 40 … bis höchstens 6 Stunden), damit das Konto nicht gesperrt wird
            $failures = $this->ReadAttributeInteger('AuthFailures') + 1;
            $this->WriteAttributeInteger('AuthFailures', $failures);
            $wait = (int) min(self::RECONNECT_MAX_SECONDS, self::RECONNECT_SECONDS * 2 ** min(10, $failures - 1));
            $this->SetStatus(self::STATUS_AUTH);
            $this->SetTimerInterval('ReconnectTimer', $wait * 1000);
            $this->SendDebug('Reconnect', sprintf('Anmeldung %d-mal abgelehnt – nächster Versuch in %d Minuten', $failures, $wait / 60), 0);
        }
    }

    // ------------------------------------------------------------------
    // Token
    // ------------------------------------------------------------------

    private function GetAccessToken(bool $force = false): string
    {
        if (!$force && $this->HasValidToken()) {
            return $this->ReadAttributeString('AccessToken');
        }

        $semaphore = 'MAMCLOUD_Token_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 30000)) {
            throw new MammotionCloudException('Token-Abruf blockiert (Zeitüberschreitung)', MammotionCloudException::TRANSIENT);
        }
        try {
            // Ein paralleler Aufruf kann den Token inzwischen erneuert haben
            if (!$force && $this->HasValidToken()) {
                return $this->ReadAttributeString('AccessToken');
            }
            $refresh = $this->ReadAttributeString('RefreshToken');
            if (!$force && $refresh !== '') {
                try {
                    return $this->RequestToken('refresh_token', $refresh);
                } catch (Throwable $e) {
                    $this->SendDebug('Token', 'Refresh-Token abgelehnt, Fallback auf Client-Credentials', 0);
                }
            }
            return $this->RequestToken('client_credentials');
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function RequestToken(string $grantType, string $refresh = ''): string
    {
        $fields = [
            'client_id'     => $this->ReadPropertyString('ClientID'),
            'client_secret' => $this->ReadPropertyString('ClientSecret'),
            'grant_type'    => $grantType
        ];
        if ($grantType === 'refresh_token') {
            $fields['refresh_token'] = $refresh;
        }

        $http = $this->HttpRequest(
            self::AUTH_URL,
            'POST',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($fields)
        );
        $json = json_decode($http['body'], true);
        $payload = is_array($json['data'] ?? null) ? $json['data'] : $json;

        // Nur eine echte Ablehnung (400/401/403 oder Antwort ohne Token) gilt als Anmeldefehler;
        // Serverfehler, Ratenlimit und andere Codes sind vorübergehend und legen die Mäher nicht still
        $rejected = in_array($http['status'], [400, 401, 403], true) || ($http['status'] >= 200 && $http['status'] < 300);
        if (!$rejected) {
            throw new MammotionCloudException('Anmeldedienst vorübergehend nicht erreichbar (HTTP ' . $http['status'] . ')', MammotionCloudException::TRANSIENT);
        }
        if ($http['status'] < 200 || $http['status'] >= 300 || !is_array($payload) || empty($payload['access_token'])) {
            $message = 'Anmeldung fehlgeschlagen: ' . $this->SafeApiMessage($json);
            if ($grantType === 'client_credentials') {
                $this->RegisterError($message, self::STATE_ERROR);
            }
            throw new MammotionCloudException($message, MammotionCloudException::AUTH);
        }

        $token = (string) $payload['access_token'];
        $validUntil = time() + max(300, (int) ($payload['expires_in'] ?? 3600));
        $this->WriteAttributeString('AccessToken', $token);
        $this->WriteAttributeString('RefreshToken', (string) ($payload['refresh_token'] ?? $refresh));
        $this->WriteAttributeInteger('TokenValidUntil', $validUntil);
        $this->SetValue('TokenValidUntil', $validUntil);
        $this->RegisterSuccess();
        $this->SendDebug('Token', 'Neuer Token (' . $grantType . ') gültig bis ' . date('d.m.Y H:i:s', $validUntil), 0);
        return $token;
    }

    private function ClearTokenCache(): void
    {
        $this->WriteAttributeString('AccessToken', '');
        $this->WriteAttributeString('RefreshToken', '');
        $this->WriteAttributeInteger('TokenValidUntil', 0);
        $this->SetValue('TokenValidUntil', 0);
        $this->SetValue('CloudState', self::STATE_NOT_LOGGED_IN);
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    private function ApiRequest(string $method, string $path, ?array $payload = null, bool $retryOn401 = true): array
    {
        // Nur lesende und befehlende Aufrufe der Mower-API, keine fremden Hosts oder Pfad-Tricks
        if (!in_array($method, ['GET', 'POST'], true) || !preg_match(self::ALLOWED_PATH, $path) || str_contains($path, '..')) {
            throw new MammotionCloudException('Nicht erlaubte Anfrage: ' . $method . ' ' . $path, MammotionCloudException::API);
        }

        $headers = [
            'Authorization: Bearer ' . $this->GetAccessToken(),
            'Accept: application/json',
            'Accept-Language: de-DE'
        ];
        $body = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->SendDebug('Request', $method . ' ' . $path . ($body !== null ? ' ' . $body : ''), 0);
        $http = $this->HttpRequest(self::API_URL . $path, $method, $headers, $body);
        $this->SendDebug('Response', 'HTTP ' . $http['status'] . ' ' . substr($http['body'], 0, 2000), 0);

        $json = json_decode($http['body'], true);
        $code = is_array($json) ? ($json['code'] ?? null) : null;

        if ($http['status'] === 401 || $code === 401) {
            if ($retryOn401) {
                $this->ClearTokenCache();
                $this->GetAccessToken(true);
                return $this->ApiRequest($method, $path, $payload, false);
            }
            // Der Token wurde gerade neu ausgestellt, die Anmeldung ist also in Ordnung: Der Endpunkt
            // verweigert nur diese Anfrage. Nicht die ganze Cloud-Instanz (und damit alle Mäher) stilllegen.
            throw new MammotionCloudException('Zugriff verweigert (HTTP 401) für ' . $path, MammotionCloudException::API);
        }
        if ($http['status'] >= 500 || $http['status'] === 429) {
            $message = 'Mammotion-Cloud vorübergehend nicht erreichbar (HTTP ' . $http['status'] . ')';
            $this->RegisterError($message, self::STATE_DISTURBED);
            throw new MammotionCloudException($message, MammotionCloudException::TRANSIENT);
        }
        if ($http['status'] < 200 || $http['status'] >= 300) {
            throw new MammotionCloudException('HTTP ' . $http['status'] . ': ' . $this->SafeApiMessage($json, substr($http['body'], 0, 200)), MammotionCloudException::API);
        }
        if (!is_array($json)) {
            $this->RegisterError('Ungültige JSON-Antwort', self::STATE_DISTURBED);
            throw new MammotionCloudException('Ungültige JSON-Antwort der Mammotion-Cloud', MammotionCloudException::TRANSIENT);
        }

        // Ab hier war die Kommunikation mit der Cloud erfolgreich
        $this->RegisterSuccess();

        // Die Spezifikation nennt 200 als Erfolgscode, die Live-API liefert 0: beides akzeptieren
        if ($code !== 0 && $code !== 200) {
            $message = 'Mammotion-API (Code ' . var_export($code, true) . '): ' . $this->SafeApiMessage($json);
            $kind = $this->IsOfflineMessage($message) ? MammotionCloudException::OFFLINE : MammotionCloudException::API;
            throw new MammotionCloudException($message, $kind);
        }
        return $json;
    }

    private function HttpRequest(string $url, string $method, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CUSTOMREQUEST     => $method,
            CURLOPT_HTTPHEADER        => $headers,
            CURLOPT_CONNECTTIMEOUT    => self::HTTP_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT           => self::HTTP_TIMEOUT,
            CURLOPT_USERAGENT         => 'IP-Symcon-MammotionOpenAPI/' . self::MODULE_VERSION,
            // Sicherheit: Zertifikat und Hostname immer prüfen, keine Weiterleitungen
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_MAXFILESIZE_LARGE => self::MAX_RESPONSE_BYTES,
            // Geschwindigkeit: komprimierte Antworten annehmen
            CURLOPT_ENCODING          => ''
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        }
        curl_setopt_array($ch, $options);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        // curl_close() entfällt: seit PHP 8.0 wirkungslos, ab PHP 8.5 (Symcon 9.0) veraltet
        unset($ch);

        if ($response === false) {
            $message = 'Verbindungsfehler: ' . $error;
            $this->RegisterError($message, self::STATE_DISTURBED);
            throw new MammotionCloudException($message, MammotionCloudException::TRANSIENT);
        }
        if (strlen((string) $response) > self::MAX_RESPONSE_BYTES) {
            throw new MammotionCloudException('Antwort der Mammotion-Cloud ist unerwartet groß', MammotionCloudException::API);
        }
        return ['status' => $status, 'body' => (string) $response];
    }

    private function IsOfflineMessage(string $message): bool
    {
        $m = mb_strtolower($message);
        foreach (['gerät antwortet nicht', 'geraet antwortet nicht', 'device does not respond', 'device not responding', 'device is offline', 'device offline'] as $pattern) {
            if (strpos($m, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function SafeApiMessage($json, string $fallback = 'unbekannter API-Fehler'): string
    {
        if (!is_array($json)) {
            return $fallback;
        }
        return (string) ($json['msg'] ?? $json['message'] ?? $json['error_description'] ?? $json['error'] ?? $fallback);
    }

    private function EncodeResult(array $result): string
    {
        return (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}

/**
 * Fehlerart für die Weitergabe an untergeordnete Instanzen.
 */
class MammotionCloudException extends RuntimeException
{
    public const TRANSIENT = 1; // vorübergehend, Wiederholung sinnvoll
    public const AUTH = 2;      // Anmeldung/Zugangsdaten
    public const API = 3;       // fachliche Ablehnung durch die API
    public const OFFLINE = 4;   // Mäher nicht erreichbar
    public const DISABLED = 5;  // Cloud-Instanz deaktiviert

    public function __construct(string $message, int $code)
    {
        parent::__construct($message, $code);
    }

    public function Kind(): string
    {
        switch ($this->getCode()) {
            case self::TRANSIENT: return 'transient';
            case self::AUTH: return 'auth';
            case self::OFFLINE: return 'offline';
            case self::DISABLED: return 'disabled';
            default: return 'api';
        }
    }
}
