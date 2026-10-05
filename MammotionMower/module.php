<?php

// SPDX-License-Identifier: MIT
// Copyright (c) 2026 Armin Frohwerk

declare(strict_types=1);

require_once __DIR__ . '/../libs/PresentationHelper.php';

/**
 * Mammotion Mäher
 *
 * Eine Instanz je Mähroboter. Holt Status und Werte zyklisch über die
 * übergeordnete Mammotion-Cloud-Instanz und stellt Steuerbefehle bereit.
 */
class MammotionMower extends IPSModuleStrict
{
    use MammotionPresentationHelper;

    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 1;
    private const CLOUD_MODULE = '{D26140D0-FC03-43F8-AAB0-1E4220D959EB}';
    private const DATA_TX = '{5F140107-E29A-41AA-9314-01891DDE02F9}';

    // Profile früherer Versionen, werden nach der Umstellung auf Darstellungen entfernt
    private const LEGACY_PROFILES = [
        'MAMMO.OperationStatus', 'MAMMO.Online', 'MAMMO.SystemState', 'MAMMO.Control', 'MAMMO.Tasks',
        'MAMMO.Millimeter', 'MAMMO.dBm', 'MAMMO.SquareMeter', 'MAMMO.Minutes', 'MAMMO.Percent',
        'MAMMO.Wh', 'MAMMO.Hours', 'MAMMO.Kilogram', 'MAMMO.WorkResult', 'MAMMO.WorkType'
    ];
    private const LEGACY_TASK_PROFILE_PREFIX = 'MAMMO.Tasks.';

    private const MIN_INTERVAL = 30;
    private const REFRESH_LOCK_TIMEOUT = 180;
    private const RETRY_DELAYS = [5, 15];
    private const COMMAND_REFRESH_DELAY = 5;
    private const EXTRAS_INTERVAL = 900;   // Aufgaben, Statistik, Verlauf, Fehler alle 15 Minuten
    private const EXTRAS_RETRY = 300;      // nach Fehler erneut nach 5 Minuten
    private const REPORT_DAYS = 30;        // Zeitraum für Verlauf und Fehlerprotokoll
    private const UNAVAILABLE_BACKOFF = 21600; // von der API abgelehnte Zusatzdaten erst nach 6 Stunden erneut versuchen

    // Systemzustand
    private const SYS_INIT = 0;
    private const SYS_CHECKING = 1;
    private const SYS_READY = 2;
    private const SYS_PARTIAL = 3;
    private const SYS_OFFLINE = 4;
    private const SYS_ERROR = 5;
    private const SYS_DISABLED = 6;

    // Betriebsstatus
    private const OP_OFFLINE = 0;
    private const OP_READY = 1;
    private const OP_MOWING = 2;
    private const OP_PAUSED = 3;
    private const OP_CHARGING = 4;
    private const OP_RETURNING = 5;
    private const OP_DEVICE_ERROR = 6;
    private const OP_CLOUD_ERROR = 7;
    private const OP_UNKNOWN = 8;

    // Instanzstatus
    private const STATUS_ACTIVE = 102;
    private const STATUS_INACTIVE = 104;
    private const STATUS_CONFIG = 200;
    private const STATUS_API = 201;
    private const STATUS_NOT_FOUND = 202;
    private const STATUS_NO_CLOUD = 203;

    // Fehlerarten (entsprechen den Antworten der Cloud-Instanz)
    private const ERR_TRANSIENT = 1;
    private const ERR_AUTH = 2;
    private const ERR_API = 3;
    private const ERR_OFFLINE = 4;
    private const ERR_NOT_FOUND = 6;

    // Sicherheit: nur Rasterformate als Kachel-Hintergrund, kein SVG (SVG kann Skripte enthalten)
    private const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    private const ACTIONS = [1 => 'PAUSE', 2 => 'RESUME', 3 => 'STOP', 4 => 'RETURN', 5 => 'CANCEL_RETURN'];

    public function Create(): void
    {
        parent::Create();
        // IPSModuleStrict: Die Verbindung zur Cloud-Instanz übernimmt die Verwaltungskonsole (siehe GetCompatibleParents)

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->RegisterPropertyBoolean('EnableTile', true);
        $this->RegisterPropertyInteger('TileBackgroundMode', 0);   // 0 Farbverlauf, 1 Medienobjekt, 2 transparent
        $this->RegisterPropertyInteger('TileBackgroundMedia', 0);
        $this->RegisterPropertyInteger('TileBackgroundDim', 55);
        $this->RegisterPropertyBoolean('EnableReports', true);

        $this->RegisterAttributeString('ResolvedDeviceID', '');
        $this->RegisterAttributeString('DeviceNickname', '');
        $this->RegisterAttributeString('DeviceApiName', '');
        $this->RegisterAttributeString('DeviceModel', '');
        $this->RegisterAttributeString('DeviceIconURL', '');
        $this->RegisterAttributeString('TaskMap', '{}');
        $this->RegisterAttributeString('LastWorkId', '');
        $this->RegisterAttributeString('RequestVariants', '{}');
        $this->RegisterAttributeBoolean('LegacyCleanupDone', false);
        // Flüchtige Laufzeitzustände (Sperre, Wiederholungen, Takt der Zusatzdaten) liegen im Buffer:
        // Sie werden nicht bei jedem Abruf auf die Festplatte geschrieben und sind nach einem Neustart sauber leer.

        $this->RegisterTimer('UpdateTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerUpdate", 0);');
        $this->RegisterTimer('RetryTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerRetry", 0);');
        $this->RegisterTimer('DelayedRefreshTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerDelayed", 0);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy(): void
    {
        // Aufgabenprofil früherer Versionen dieser Instanz entfernen
        $legacy = self::LEGACY_TASK_PROFILE_PREFIX . $this->InstanceID;
        if (!IPS_InstanceExists($this->InstanceID) && IPS_VariableProfileExists($legacy)) {
            IPS_DeleteVariableProfile($legacy);
        }
        parent::Destroy();
    }

    /**
     * IPSModuleStrict: Mäher-Instanzen hängen an einer vorhandenen oder neuen Mammotion-Cloud-Instanz.
     */
    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'connect', 'moduleIDs' => [self::CLOUD_MODULE]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->MaintainVariables();
        $this->CleanupLegacyProfiles();
        $this->SetVisualizationType($this->ReadPropertyBoolean('EnableTile') ? 1 : 0);
        $this->UpdateMediaReference();
        $this->ReleaseRefreshLock();
        $this->ResetRetry();
        $this->SetBufferInt('NextExtrasFetch', 0);
        $this->SetBuffer('TileHash', '');

        $configured = trim($this->ReadPropertyString('DeviceID'));
        if ($configured !== '' && $configured !== $this->ReadAttributeString('ResolvedDeviceID')) {
            $this->WriteAttributeString('ResolvedDeviceID', $configured);
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

    public function ReceiveData(string $JSONString): string
    {
        // Die Cloud-Instanz sendet derzeit keine Daten aktiv an die Mäher.
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TimerUpdate':
                $this->Refresh();
                return;
            case 'TimerRetry':
                $this->SetTimerInterval('RetryTimer', 0);
                $this->RunRefresh();
                return;
            case 'TimerDelayed':
                $this->SetTimerInterval('DelayedRefreshTimer', 0);
                $this->Refresh();
                return;
            case 'Control':
                $v = (int) $Value;
                if (!isset(self::ACTIONS[$v])) {
                    throw new InvalidArgumentException('Unbekannter Steuerbefehl.');
                }
                $this->SetValue('Control', $v);
                $this->ExecuteAction(self::ACTIONS[$v]);
                return;
            case 'Task':
                $map = json_decode($this->ReadAttributeString('TaskMap'), true) ?: [];
                $key = (string) (int) $Value;
                if (!isset($map[$key])) {
                    throw new RuntimeException('Aufgabe unbekannt. Bitte zuerst aktualisieren.');
                }
                $this->SetValue('Task', (int) $Value);
                $this->StartTask((string) $map[$key]['name']);
                return;
        }
        throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
    }

    // ------------------------------------------------------------------
    // Öffentliche Funktionen (MAMMO_*)
    // ------------------------------------------------------------------

    public function Refresh(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        $this->ResetRetry();
        return $this->RunRefresh();
    }

    public function RefreshWithResult(): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return 'DEAKTIVIERT: Instanz ist deaktiviert.';
        }
        // Manueller Abruf liest alle Zusatzdaten neu, auch zuvor abgelehnte
        $this->SetBufferInt('NextExtrasFetch', 0);
        $this->SetBuffer('Unavailable', '');
        $ok = $this->Refresh();
        if ($this->GetBufferInt('RetryAttempt') > 0) {
            return 'WIEDERHOLUNG GEPLANT: ' . (string) $this->GetValue('Diagnostic');
        }
        return ($ok ? 'ERFOLG: ' : 'FEHLER: ') . (string) $this->GetValue('Diagnostic');
    }

    public function StartTask(string $TaskName): bool
    {
        if (trim($TaskName) === '') {
            throw new InvalidArgumentException('Kein Aufgabenname angegeben.');
        }
        return $this->SendCommand('START', ['taskName' => $TaskName]);
    }

    public function Pause(): bool { return $this->ExecuteAction('PAUSE'); }
    public function Resume(): bool { return $this->ExecuteAction('RESUME'); }
    public function Stop(): bool { return $this->ExecuteAction('STOP'); }
    public function ReturnToDock(): bool { return $this->ExecuteAction('RETURN'); }
    public function CancelReturn(): bool { return $this->ExecuteAction('CANCEL_RETURN'); }

    public function ExecuteAction(string $Action): bool
    {
        $Action = strtoupper(trim($Action));
        if (!in_array($Action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Nicht erlaubter Befehl: ' . $Action);
        }
        return $this->SendCommand($Action);
    }

    public function GetTasks(): string
    {
        $map = json_decode($this->ReadAttributeString('TaskMap'), true) ?: [];
        return (string) json_encode(array_values($map), JSON_UNESCAPED_UNICODE);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $lines = [];

        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID <= 0) {
            $lines[] = 'Cloud: ❌ keine Cloud-Instanz verbunden – über „Gateway ändern“ zuordnen';
        } else {
            $parentStatus = IPS_GetInstance($parentID)['InstanceStatus'];
            $cloudTexts = [
                102 => '✅ verbunden',
                104 => '⏸ Cloud-Instanz ist deaktiviert',
                200 => '⚠️ Client-ID oder Client-Secret fehlt',
                201 => '❌ Anmeldung fehlgeschlagen',
                202 => '⚠️ Nutzungshinweis in der Cloud-Instanz noch nicht bestätigt'
            ];
            $lines[] = 'Cloud: ' . ($cloudTexts[$parentStatus] ?? '❔ Status ' . $parentStatus) . ' – ' . IPS_GetName($parentID) . ' (#' . $parentID . ')';
        }

        $model = $this->ReadAttributeString('DeviceModel');
        $nickname = $this->ReadAttributeString('DeviceNickname');
        $deviceID = $this->ReadAttributeString('ResolvedDeviceID');
        if ($deviceID !== '') {
            $lines[] = 'Mäher: ' . ($this->GetValue('Online') ? '🟢 online' : '⚪ offline') . ' – ' . ($model !== '' ? $model : 'Mammotion')
                . ' (' . ($nickname !== '' ? 'Name in der App: ' . $nickname . ', ' : '') . 'Device-ID ' . $deviceID . ')';
        } else {
            $lines[] = 'Mäher: ⏳ noch nicht ermittelt – erster Abruf folgt';
        }

        $systemTexts = ['⏳ Initialisierung', '🔄 Prüfung läuft', '✅ Betriebsbereit', '⚠️ Teilweise verfügbar', '⚪ Offline', '❌ Fehler', '⏸ Deaktiviert'];
        $lines[] = 'Systemzustand: ' . ($systemTexts[(int) $this->GetValue('SystemState')] ?? '❔');
        $diagnostic = (string) $this->GetValue('Diagnostic');
        if ($diagnostic !== '') {
            $lines[] = 'Diagnose: ' . $diagnostic;
        }
        $lastSuccess = (int) $this->GetValue('LastSuccess');
        $lines[] = 'Letzte erfolgreiche Aktualisierung: ' . ($lastSuccess > 0 ? date('d.m.Y H:i:s', $lastSuccess) : '—');
        if ($this->ReadPropertyBoolean('EnableReports')) {
            $lastWork = (int) $this->GetValue('LastWorkEnd');
            if ($lastWork > 0) {
                $results = ['unbekannt', 'läuft', 'pausiert', 'vom Nutzer gestoppt', 'unterbrochen', 'abgeschlossen'];
                $lines[] = 'Letzter Einsatz: ' . date('d.m.Y H:i', $lastWork) . ' – ' . number_format((float) $this->GetValue('LastWorkArea'), 0, ',', '.') . ' m², '
                    . (int) $this->GetValue('LastWorkDuration') . ' min, ' . ($results[(int) $this->GetValue('LastWorkResult')] ?? '?');
            }
            $errorTime = (int) $this->GetValue('LastErrorTime');
            $lines[] = 'Gerätefehler (30 Tage): ' . (int) $this->GetValue('ErrorCount30d')
                . ($errorTime > 0 ? ' – zuletzt ' . date('d.m.Y H:i', $errorTime) . ': ' . (string) $this->GetValue('LastErrorText') : '');
        }
        $lastCommand = (string) $this->GetValue('LastCommand');
        if ($lastCommand !== '') {
            $lines[] = 'Letzter Befehl: ' . $lastCommand;
        }
        $lines[] = 'Schreibbefehle: ' . ($this->ReadPropertyBoolean('EnableControl') ? '🔓 freigegeben' : '🔒 gesperrt');
        if ($this->ReadPropertyBoolean('EnableTile')) {
            $mode = $this->ReadPropertyInteger('TileBackgroundMode');
            if ($mode === 1) {
                $error = $this->BackgroundError();
                $mediaID = $this->ReadPropertyInteger('TileBackgroundMedia');
                $lines[] = 'Kachel-Hintergrund: ' . ($error === ''
                    ? '🖼 Bild „' . IPS_GetName($mediaID) . '“ (' . round((int) (IPS_GetMedia($mediaID)['MediaSize'] ?? 0) / 1024) . ' KB)'
                    : '⚠️ ' . $error . ' – Farbverlauf wird verwendet');
            } else {
                $lines[] = 'Kachel-Hintergrund: ' . ($mode === 2 ? 'transparent (Hintergrund aus der Kachel-Visualisierung)' : 'Farbverlauf');
            }
        }

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'StatusPanel') {
                $element['items'] = array_map(function ($line) {
                    return ['type' => 'Label', 'caption' => $line];
                }, $lines);
            }
        }
        unset($element);
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'VersionLabel') {
                $action['caption'] = 'Mammotion Open API v' . self::MODULE_VERSION . ' (Build ' . self::MODULE_BUILD . '). Zugangsdaten und Nutzungshinweis werden in der übergeordneten Instanz „Mammotion Cloud“ gepflegt.';
            }
        }
        unset($action);
        return (string) json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ------------------------------------------------------------------
    // Initialisierung
    // ------------------------------------------------------------------

    private function Initialize(): void
    {
        $this->SetTimerInterval('DelayedRefreshTimer', 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetValue('SystemState', self::SYS_DISABLED);
            $this->SetValue('Diagnostic', 'Instanz ist deaktiviert');
            $this->SetStatus(self::STATUS_INACTIVE);
            $this->UpdateTile();
            return;
        }

        if ($this->ReadPropertyInteger('PollInterval') < self::MIN_INTERVAL) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetValue('SystemState', self::SYS_ERROR);
            $this->SetValue('Diagnostic', 'Abfrageintervall muss mindestens ' . self::MIN_INTERVAL . ' Sekunden betragen');
            $this->SetStatus(self::STATUS_CONFIG);
            $this->UpdateTile();
            return;
        }

        $this->SetValue('SystemState', self::SYS_INIT);
        $this->SetStatus(self::STATUS_ACTIVE);
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('PollInterval') * 1000);
        $this->SetTimerInterval('DelayedRefreshTimer', 2000);
        $this->UpdateTile();
    }

    // ------------------------------------------------------------------
    // Abruf
    // ------------------------------------------------------------------

    private function RunRefresh(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return false;
        }
        if (!$this->HasActiveParent()) {
            $this->SetValue('SystemState', self::SYS_ERROR);
            $this->SetValue('Diagnostic', 'Cloud-Instanz ist nicht verbunden oder nicht aktiv');
            $this->SetStatus(self::STATUS_NO_CLOUD);
            $this->UpdateTile();
            return false;
        }
        if (!$this->AcquireRefreshLock()) {
            $this->SendDebug('Refresh', 'Übersprungen: Ein Abruf läuft bereits', 0);
            return false;
        }

        $this->SetValue('SystemState', self::SYS_CHECKING);
        $this->SetValue('LastAttempt', time());
        $steps = [];

        try {
            // Geschwindigkeit: Normalerweise genügt ein einziger Aufruf (Gerätedetail enthält Status,
            // Online-Flag, Modell, Nickname und Bild). Die Geräteliste wird nur zur Ermittlung der
            // Device-ID oder zur Prüfung bei einem Fehler abgefragt.
            $id = $this->ResolveDeviceID($steps);
            try {
                $detail = $this->Api('GET', '/v1/mower/' . rawurlencode($id));
            } catch (RuntimeException $e) {
                if ($e->getCode() !== self::ERR_API) {
                    throw $e;
                }
                // Gerät unbekannt? Mit der Geräteliste gegenprüfen und gegebenenfalls neu zuordnen
                $this->WriteAttributeIfChanged('ResolvedDeviceID', '');
                $resolved = $this->ResolveDeviceID($steps);
                if ($resolved === $id) {
                    throw $e;
                }
                $id = $resolved;
                $detail = $this->Api('GET', '/v1/mower/' . rawurlencode($id));
            }
            $data = is_array($detail['data'] ?? null) ? $detail['data'] : [];
            $this->StoreDeviceMetadata($data);
            $steps[] = 'Gerätestatus OK';

            if (((int) ($data['online'] ?? 1)) !== 1) {
                $steps[] = 'Mäher offline';
                $this->CompleteOffline($steps);
                return true;
            }
            $this->ApplyDeviceDetails($data);

            // Nach Ende eines Einsatzes Verlauf und Statistik zeitnah nachladen
            $operation = (int) $this->GetValue('OperationStatus');
            $previous = $this->GetBuffer('PreviousOperation') === '' ? -1 : (int) $this->GetBuffer('PreviousOperation');
            if (in_array($previous, [self::OP_MOWING, self::OP_RETURNING], true) && !in_array($operation, [self::OP_MOWING, self::OP_RETURNING, self::OP_PAUSED], true)) {
                $this->SetBufferInt('NextExtrasFetch', min($this->GetBufferInt('NextExtrasFetch'), time() + 120));
            }
            $this->SetBuffer('PreviousOperation', (string) $operation);

            // Aufgaben, Statistik, Verlauf und Fehlerprotokoll ändern sich selten und werden seltener abgefragt.
            // Der Endpunkt /work-params wird bewusst NICHT verwendet (siehe README, Sicherheitshinweis).
            $partial = [];
            if (time() >= $this->GetBufferInt('NextExtrasFetch')) {
                $jobs = ['Aufgaben' => 'FetchTasks'];
                if ($this->ReadPropertyBoolean('EnableReports')) {
                    $jobs += ['Statistik' => 'FetchSummary', 'Verlauf' => 'FetchLastWork', 'Fehlerprotokoll' => 'FetchErrors'];
                }
                $unavailable = json_decode($this->GetBuffer('Unavailable'), true) ?: [];
                $info = [];
                foreach ($jobs as $label => $method) {
                    if (($unavailable[$label]['until'] ?? 0) > time()) {
                        $info[] = $label . ': derzeit nicht bereitgestellt (' . $unavailable[$label]['reason'] . ', nächster Versuch ' . date('H:i', $unavailable[$label]['until']) . ')';
                        continue;
                    }
                    try {
                        $this->$method($id);
                        unset($unavailable[$label]);
                        $steps[] = $label . ' OK';
                    } catch (RuntimeException $e) {
                        if ($e->getCode() === self::ERR_AUTH) throw $e;
                        if ($e->getCode() === self::ERR_API && $label !== 'Aufgaben') {
                            // Fachliche Ablehnung durch die API: kein Dauerfehler, später erneut versuchen
                            $reason = preg_match('/Code (\d+)/', $e->getMessage(), $m) ? 'Code ' . $m[1] : 'abgelehnt';
                            $unavailable[$label] = ['until' => time() + self::UNAVAILABLE_BACKOFF, 'reason' => $reason];
                            $info[] = $label . ': derzeit nicht bereitgestellt (' . $reason . ', nächster Versuch ' . date('H:i', $unavailable[$label]['until']) . ')';
                            continue;
                        }
                        $partial[] = $label . ': ' . $e->getMessage();
                    }
                }
                $this->SetBuffer('Unavailable', (string) json_encode($unavailable));
                $steps = array_merge($steps, $info);
                $this->SetBuffer('ExtrasError', implode(' | ', $partial));
                $this->SetBufferInt('NextExtrasFetch', time() + (count($partial) > 0 ? self::EXTRAS_RETRY : self::EXTRAS_INTERVAL));
            } else {
                $next = date('H:i', $this->GetBufferInt('NextExtrasFetch'));
                $cachedError = $this->GetBuffer('ExtrasError');
                if ($cachedError !== '') {
                    $partial[] = $cachedError . ' (neuer Versuch ' . $next . ')';
                } else {
                    $steps[] = 'Zusatzdaten aus Zwischenspeicher (neu ' . $next . ')';
                }
            }

            $this->CompleteSuccess($steps, $partial);
            return true;
        } catch (Throwable $e) {
            $code = $e->getCode();
            $message = $e->getMessage();

            if ($code === self::ERR_OFFLINE) {
                $steps[] = 'Mäher offline: ' . $message;
                $this->CompleteOffline($steps);
                return true;
            }
            if ($code === self::ERR_TRANSIENT && $this->ScheduleRetry($message)) {
                return false;
            }
            $this->CompleteFailure($code, $message);
            return false;
        } finally {
            $this->ReleaseRefreshLock();
            $this->UpdateTile();
        }
    }

    /**
     * Liefert die Device-ID. Die Geräteliste wird nur abgefragt, wenn noch keine ID bekannt ist.
     */
    private function ResolveDeviceID(array &$steps): string
    {
        $known = $this->ReadAttributeString('ResolvedDeviceID');
        if ($known !== '') {
            return $known;
        }
        $list = $this->Api('GET', '/v1/mowers');
        $steps[] = 'Geräteliste OK';
        $devices = is_array($list['data'] ?? null) ? $list['data'] : [];
        $configured = trim($this->ReadPropertyString('DeviceID'));
        $device = null;
        if ($configured === '') {
            $device = $devices[0] ?? null;
            if ($device === null) {
                throw new RuntimeException('Keine Mäher im Mammotion-Konto gefunden', self::ERR_NOT_FOUND);
            }
        } else {
            foreach ($devices as $candidate) {
                if ((string) ($candidate['id'] ?? '') === $configured) {
                    $device = $candidate;
                    break;
                }
            }
            if ($device === null) {
                throw new RuntimeException('Device-ID ' . $configured . ' wurde im Mammotion-Konto nicht gefunden', self::ERR_NOT_FOUND);
            }
        }
        $id = (string) ($device['id'] ?? '');
        $this->WriteAttributeIfChanged('ResolvedDeviceID', $id);
        $this->StoreDeviceMetadata((array) $device);
        return $id;
    }

    private function CompleteSuccess(array $steps, array $partial): void
    {
        $partialState = count($partial) > 0;
        $this->SetValue('SystemState', $partialState ? self::SYS_PARTIAL : self::SYS_READY);
        $this->SetValue('Diagnostic', implode(' | ', array_merge($steps, $partial)));
        $this->SetValue('LastSuccess', time());
        $this->SetStatus(self::STATUS_ACTIVE);
        $this->ResetRetry();
    }

    private function CompleteOffline(array $steps): void
    {
        $this->SetValue('Online', false);
        $this->SetValue('Status', 'Offline');
        $this->SetValue('OperationStatus', self::OP_OFFLINE);
        $this->SetValue('SystemState', self::SYS_OFFLINE);
        $this->SetValue('Diagnostic', implode(' | ', $steps));
        $this->SetValue('LastSuccess', time());
        $this->SetStatus(self::STATUS_ACTIVE);
        $this->ResetRetry();
    }

    private function CompleteFailure(int $code, string $message): void
    {
        $this->SetValue('SystemState', self::SYS_ERROR);
        $this->SetValue('Diagnostic', $message);
        if ($code !== self::ERR_NOT_FOUND) {
            $this->SetValue('OperationStatus', self::OP_CLOUD_ERROR);
        }
        $this->SetStatus($code === self::ERR_NOT_FOUND ? self::STATUS_NOT_FOUND : self::STATUS_API);
        $this->ResetRetry();
    }

    private function ScheduleRetry(string $message): bool
    {
        $attempt = $this->GetBufferInt('RetryAttempt');
        if ($attempt >= count(self::RETRY_DELAYS)) {
            return false;
        }
        $delay = self::RETRY_DELAYS[$attempt];
        $attempt++;
        $this->SetBufferInt('RetryAttempt', $attempt);
        $this->SetValue('Diagnostic', 'Wiederholung ' . $attempt . '/' . count(self::RETRY_DELAYS) . ' in ' . $delay . ' s: ' . $message);
        $this->SetTimerInterval('RetryTimer', $delay * 1000);
        return true;
    }

    private function ResetRetry(): void
    {
        $this->SetTimerInterval('RetryTimer', 0);
        $this->SetBufferInt('RetryAttempt', 0);
    }

    // ------------------------------------------------------------------
    // Sperre gegen parallele Abrufe
    // ------------------------------------------------------------------

    private function AcquireRefreshLock(): bool
    {
        $semaphore = 'MAMMO_Refresh_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 1000)) {
            return false;
        }
        try {
            $since = $this->GetBufferInt('RefreshLockSince');
            if ($since > 0 && (time() - $since) < self::REFRESH_LOCK_TIMEOUT) {
                return false;
            }
            if ($since > 0) {
                $this->SendDebug('Refresh', 'Veraltete Sperre vom ' . date('d.m.Y H:i:s', $since) . ' übernommen', 0);
            }
            $this->SetBufferInt('RefreshLockSince', time());
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function ReleaseRefreshLock(): void
    {
        $this->SetBufferInt('RefreshLockSince', 0);
    }

    // ------------------------------------------------------------------
    // Buffer und Attribute (Geschwindigkeit: nur schreiben, was sich ändert)
    // ------------------------------------------------------------------

    private function GetBufferInt(string $name): int
    {
        return (int) $this->GetBuffer($name);
    }

    private function SetBufferInt(string $name, int $value): void
    {
        $this->SetBuffer($name, (string) $value);
    }

    private function WriteAttributeIfChanged(string $name, string $value): void
    {
        if ($this->ReadAttributeString($name) !== $value) {
            $this->WriteAttributeString($name, $value);
        }
    }

    // ------------------------------------------------------------------
    // Befehle
    // ------------------------------------------------------------------

    private function SendCommand(string $action, ?array $params = null): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            throw new RuntimeException('Instanz ist deaktiviert.');
        }
        if (!$this->ReadPropertyBoolean('EnableControl')) {
            throw new RuntimeException('Schreibbefehle sind nicht freigegeben.');
        }
        if (!$this->HasActiveParent()) {
            throw new RuntimeException('Cloud-Instanz ist nicht verbunden oder nicht aktiv.');
        }
        $id = trim($this->ReadPropertyString('DeviceID'));
        if ($id === '') {
            $id = $this->ReadAttributeString('ResolvedDeviceID');
        }
        if ($id === '') {
            throw new RuntimeException('Keine Device-ID bekannt. Bitte zuerst aktualisieren.');
        }

        $payload = ['deviceId' => $id, 'action' => $action];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        try {
            $response = $this->Api('POST', '/v1/mower/action', $payload);
        } catch (Throwable $e) {
            $this->SetValue('LastCommand', $action . ' fehlgeschlagen (' . date('H:i:s') . '): ' . $e->getMessage());
            $this->UpdateTile();
            throw $e;
        }
        $ok = (bool) ($response['data']['commandResult'] ?? true);
        $this->SetValue('LastCommand', $action . ($ok ? ' gesendet' : ' abgelehnt') . ' (' . date('d.m.Y H:i:s') . ')');
        $this->UpdateTile();
        // Status kurz nach dem Befehl neu einlesen
        $this->SetTimerInterval('DelayedRefreshTimer', self::COMMAND_REFRESH_DELAY * 1000);
        return $ok;
    }

    // ------------------------------------------------------------------
    // Kommunikation mit der Cloud-Instanz
    // ------------------------------------------------------------------

    private function Api(string $method, string $path, ?array $payload = null): array
    {
        $request = [
            'DataID'  => self::DATA_TX,
            'Command' => 'Request',
            'Method'  => $method,
            'Path'    => $path,
            'Payload' => $payload
        ];
        $raw = $this->SendDataToParent((string) json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Keine Antwort von der Cloud-Instanz', self::ERR_TRANSIENT);
        }
        $result = json_decode($raw, true);
        if (!is_array($result)) {
            throw new RuntimeException('Ungültige Antwort der Cloud-Instanz', self::ERR_TRANSIENT);
        }
        if (!($result['ok'] ?? false)) {
            $kinds = ['transient' => self::ERR_TRANSIENT, 'auth' => self::ERR_AUTH, 'offline' => self::ERR_OFFLINE, 'disabled' => self::ERR_AUTH];
            throw new RuntimeException((string) ($result['error'] ?? 'Unbekannter Fehler'), $kinds[$result['kind'] ?? ''] ?? self::ERR_API);
        }
        return is_array($result['response'] ?? null) ? $result['response'] : [];
    }

    // ------------------------------------------------------------------
    // Daten übernehmen
    // ------------------------------------------------------------------

    private function StoreDeviceMetadata(array $device): void
    {
        // Nur vorhandene Felder übernehmen und nur bei Änderung schreiben (Attribute werden sofort gespeichert)
        foreach (['DeviceNickname' => 'nickname', 'DeviceApiName' => 'name', 'DeviceModel' => 'model'] as $attribute => $field) {
            if (array_key_exists($field, $device)) {
                $this->WriteAttributeIfChanged($attribute, trim((string) $device[$field]));
            }
        }
        if (array_key_exists('icon', $device)) {
            // Sicherheit: Gerätebild nur über HTTPS laden
            $icon = trim((string) $device['icon']);
            $this->WriteAttributeIfChanged('DeviceIconURL', filter_var($icon, FILTER_VALIDATE_URL) && preg_match('#^https://#i', $icon) ? $icon : '');
        }
    }

    private function ApplyDeviceDetails(array $data): void
    {
        $network = is_array($data['network'] ?? null) ? $data['network'] : [];
        $online = ((int) ($data['online'] ?? 1)) === 1;
        $raw = (string) ($data['status'] ?? '');
        $this->SetValue('Online', $online);
        $this->SetValue('Status', $raw !== '' ? $raw : 'Unbekannt');
        $charge = (int) ($data['chargeStatus'] ?? 0);
        $operation = $this->MapOperationStatus($raw, $online);
        if ($operation === self::OP_READY && $charge !== 0) {
            // Standby mit Ladestatus ungleich 0: Mäher steht in der Ladestation
            $operation = self::OP_CHARGING;
        }
        $this->SetValue('OperationStatus', $operation);
        $this->SetValue('Battery', max(0, min(100, (int) ($data['batteryLevel'] ?? 0))));
        $this->SetValue('Firmware', (string) ($data['version'] ?? ''));
        $this->SetValue('ChargeStatus', (int) ($data['chargeStatus'] ?? 0));
        $this->SetValue('WifiRSSI', (int) ($network['wifiRssi'] ?? 0));
        $this->SetValue('WifiIP', (string) ($network['wifiIp'] ?? ''));
        $this->SetValue('CellularRSSI', (int) ($network['cellularRssi'] ?? 0));
    }

    private function MapOperationStatus(string $raw, bool $online): int
    {
        if (!$online) return self::OP_OFFLINE;
        $s = mb_strtolower(trim($raw));
        if ($s === '') return self::OP_UNKNOWN;
        if (strpos($s, 'error') !== false || strpos($s, 'fault') !== false) return self::OP_DEVICE_ERROR;
        if (strpos($s, 'pause') !== false) return self::OP_PAUSED;
        if (strpos($s, 'return') !== false || strpos($s, 'dock') !== false) return self::OP_RETURNING;
        if (strpos($s, 'charg') !== false) return self::OP_CHARGING;
        if (strpos($s, 'mow') !== false || strpos($s, 'work') !== false) return self::OP_MOWING;
        if (strpos($s, 'standby') !== false || strpos($s, 'idle') !== false || strpos($s, 'ready') !== false) return self::OP_READY;
        return self::OP_UNKNOWN;
    }

    private function UpdateTasks(array $tasks): void
    {
        $map = [];
        $i = 1;
        foreach ($tasks as $task) {
            $name = trim((string) ($task['taskName'] ?? ''));
            if ($name === '') continue;
            $map[(string) $i] = ['id' => (string) ($task['taskId'] ?? ''), 'name' => $name];
            $i++;
        }
        $json = (string) json_encode($map, JSON_UNESCAPED_UNICODE);
        if ($json === $this->ReadAttributeString('TaskMap')) {
            return; // unverändert: weder Attribut noch Darstellung neu schreiben
        }
        $this->WriteAttributeString('TaskMap', $json);
        // Die Aufgaben stehen direkt in der Darstellung der Variable, ein eigenes Profil je Instanz entfällt
        $this->MaintainVariable('Task', 'Aufgabe starten', VARIABLETYPE_INTEGER, $this->TaskPresentation(), 120, true);
    }

    private function TaskPresentation(): array
    {
        $options = [];
        foreach (json_decode($this->ReadAttributeString('TaskMap'), true) ?: [] as $value => $task) {
            $options[(int) $value] = [(string) $task['name'], 'play', -1];
        }
        return $this->PresentEnumeration('list-check', $options);
    }

    private function FetchTasks(string $id): void
    {
        $plans = $this->Api('GET', '/v1/mower/' . rawurlencode($id) . '/plan');
        $this->UpdateTasks(is_array($plans['data'] ?? null) ? $plans['data'] : []);
    }

    /**
     * Probiert mehrere gültige Anfrageformen nacheinander und merkt sich die erste, die die API akzeptiert.
     * Hintergrund: Die Work-Report-Endpunkte lehnen je nach Konto oder Gerät manche Parameter mit Code 40200 ab.
     */
    private function PostWithVariants(string $path, array $variants): array
    {
        $known = json_decode($this->ReadAttributeString('RequestVariants'), true) ?: [];
        $order = array_keys($variants);
        if (isset($known[$path]) && isset($variants[$known[$path]])) {
            $order = array_values(array_unique(array_merge([$known[$path]], $order)));
        }
        $last = null;
        foreach ($order as $index) {
            try {
                $result = $this->Api('POST', $path, $variants[$index]);
                if (($known[$path] ?? null) !== $index) {
                    $known[$path] = $index;
                    $this->WriteAttributeString('RequestVariants', (string) json_encode($known));
                    $this->SendDebug('Variant', $path . ' funktioniert mit Variante ' . $index . ': ' . json_encode($variants[$index]), 0);
                }
                return $result;
            } catch (RuntimeException $e) {
                if ($e->getCode() !== self::ERR_API) throw $e;
                $this->SendDebug('Variant', $path . ' Variante ' . $index . ' abgelehnt: ' . $e->getMessage(), 0);
                $last = $e;
            }
        }
        throw $last ?? new RuntimeException('Keine Anfragevariante verfügbar', self::ERR_API);
    }

    private function ReportRange(int $days): array
    {
        $now = time();
        return ['endWorkTimeStart' => ($now - $days * 86400) * 1000, 'endWorkTimeEnd' => $now * 1000];
    }

    private function FetchSummary(string $id): void
    {
        $r = $this->PostWithVariants('/v1/mower/work-reports/summary', [
            'id-only'   => ['deviceId' => $id],
            'paged'     => ['deviceId' => $id, 'pageNumber' => 1, 'pageSize' => 10],
            'range-30d' => ['deviceId' => $id, 'pageNumber' => 1, 'pageSize' => 10] + $this->ReportRange(30),
            'range-7d'  => ['deviceId' => $id, 'pageNumber' => 1, 'pageSize' => 10] + $this->ReportRange(7)
        ]);
        $d = is_array($r['data'] ?? null) ? $r['data'] : [];
        $this->SetValue('TotalWorkCount', (int) ($d['workCount'] ?? 0));
        $this->SetValue('TotalWorkArea', round((float) ($d['totalWorkArea'] ?? 0), 1));
        $this->SetValue('TotalSaveTime', round((float) ($d['saveTime'] ?? 0) / 60, 1));
        $this->SetValue('TotalCarbon', round((float) ($d['carbonReduction'] ?? 0) / 1000, 2));
    }

    private function FetchLastWork(string $id): void
    {
        // Die API garantiert keine Sortierung: letzte 30 Tage laden und den jüngsten Eintrag wählen
        $r = $this->PostWithVariants('/v1/mower/work-reports/search', [
            'paged'     => ['deviceId' => $id, 'pageNumber' => 1, 'pageSize' => 10],
            'range-30d' => ['deviceId' => $id, 'pageNumber' => 1, 'pageSize' => 20] + $this->ReportRange(self::REPORT_DAYS),
            'range-7d'  => ['deviceId' => $id, 'pageNumber' => 1, 'pageSize' => 10] + $this->ReportRange(7),
            'id-only'   => ['deviceId' => $id]
        ]);
        $records = is_array($r['data']['records'] ?? null) ? $r['data']['records'] : [];
        $latest = null;
        foreach ($records as $record) {
            if ($latest === null || (int) ($record['endWorkTime'] ?? 0) > (int) ($latest['endWorkTime'] ?? 0)) {
                $latest = $record;
            }
        }
        if ($latest === null) {
            return;
        }
        $this->SetValue('LastWorkEnd', intdiv((int) ($latest['endWorkTime'] ?? 0), 1000));
        $this->SetValue('LastWorkResult', (int) ($latest['workResult'] ?? 0));
        $this->SetValue('LastWorkType', (int) ($latest['workType'] ?? 0));
        $this->SetValue('LastWorkArea', round((float) ($latest['workArea'] ?? 0), 1));
        $this->SetValue('LastWorkDuration', (int) round((int) ($latest['workTimeUsed'] ?? 0) / 60));
        $this->SetValue('LastWorkProgress', (int) round((float) ($latest['workProgress'] ?? 0)));

        // Details nur laden, wenn ein neuer Einsatz dazugekommen ist
        $workId = (string) ($latest['workId'] ?? '');
        if ($workId === '' || $workId === $this->ReadAttributeString('LastWorkId')) {
            return;
        }
        $detail = $this->Api('GET', '/v1/mower/' . rawurlencode($id) . '/work-reports/' . rawurlencode($workId));
        $d = is_array($detail['data'] ?? null) ? $detail['data'] : [];
        $this->SetValue('LastWorkEnergy', round((float) ($d['energyConsume'] ?? 0), 1));
        $param = is_array($d['workParam'] ?? null) ? $d['workParam'] : [];
        if ((int) ($param['knifeHeight'] ?? 0) > 0) {
            $this->SetValue('KnifeHeight', (int) $param['knifeHeight']);
        }
        if (isset($param['speed'])) {
            $this->SetValue('Speed', (int) $param['speed']);
        }
        $this->WriteAttributeString('LastWorkId', $workId);
    }

    private function FetchErrors(string $id): void
    {
        $r = $this->Api('POST', '/v1/mower/error-codes/search', [
            'deviceId'   => $id,
            'pageNumber' => 1,
            'pageSize'   => 50,
            'startDate'  => date('Y-m-d', time() - self::REPORT_DAYS * 86400),
            'endDate'    => date('Y-m-d')
        ]);
        $records = is_array($r['data']['records'] ?? null) ? $r['data']['records'] : [];
        $this->SetValue('ErrorCount30d', (int) ($r['data']['total'] ?? count($records)));
        $latest = null;
        foreach ($records as $record) {
            if ($latest === null || (int) ($record['gmtCreate'] ?? 0) > (int) ($latest['gmtCreate'] ?? 0)) {
                $latest = $record;
            }
        }
        if ($latest === null) {
            $this->SetValue('LastErrorText', 'Keine Fehler in den letzten ' . self::REPORT_DAYS . ' Tagen');
            $this->SetValue('LastErrorTime', 0);
            return;
        }
        $text = trim((string) ($latest['implication'] ?? ''));
        $this->SetValue('LastErrorText', 'Code ' . (int) ($latest['code'] ?? 0) . ($text !== '' ? ' – ' . $text : ''));
        $this->SetValue('LastErrorTime', intdiv((int) ($latest['gmtCreate'] ?? 0), 1000));
    }

    // ------------------------------------------------------------------
    // Variablen und Profile
    // ------------------------------------------------------------------

    private function MaintainVariables(): void
    {
        $reports = $this->ReadPropertyBoolean('EnableReports');
        $green = 0x00AA00; $grey = 0x808080; $red = 0xFF0000; $orange = 0xFF8800; $yellow = 0xFFCC00; $blue = 0x3399FF;

        $operation = $this->PresentStates('robot', [
            self::OP_OFFLINE      => ['Offline', $grey, 'power-off'],
            self::OP_READY        => ['Bereit', $green, 'circle-check'],
            self::OP_MOWING       => ['Mäht', 0x10B981, 'seedling'],
            self::OP_PAUSED       => ['Pausiert', $yellow, 'pause'],
            self::OP_CHARGING     => ['In der Station', $blue, 'charging-station'],
            self::OP_RETURNING    => ['Heimfahrt', 0x8B5CF6, 'house'],
            self::OP_DEVICE_ERROR => ['Gerätefehler', $red, 'triangle-exclamation'],
            self::OP_CLOUD_ERROR  => ['API/Cloud-Fehler', $orange, 'cloud-exclamation'],
            self::OP_UNKNOWN      => ['Unbekannt', 0xAAAAAA, 'circle-question']
        ]);
        $system = $this->PresentStates('gear', [
            self::SYS_INIT     => ['Initialisierung', 0xAAAAAA, 'hourglass'],
            self::SYS_CHECKING => ['Prüfung läuft', $blue, 'arrows-rotate'],
            self::SYS_READY    => ['Betriebsbereit', $green, 'circle-check'],
            self::SYS_PARTIAL  => ['Teilweise verfügbar', $yellow, 'circle-half-stroke'],
            self::SYS_OFFLINE  => ['Offline', $grey, 'power-off'],
            self::SYS_ERROR    => ['Fehler', $red, 'circle-xmark'],
            self::SYS_DISABLED => ['Deaktiviert', 0x777777, 'circle-pause']
        ]);
        $battery = $this->PresentValue('battery-three-quarters', ' %', 0, [
            $this->Interval(0, 19, 1, '', '', null, 'battery-empty', $red),
            $this->Interval(20, 39, 1, '', '', null, 'battery-quarter', $orange),
            $this->Interval(40, 69, 1, '', '', null, 'battery-half', $green),
            $this->Interval(70, 100, 1, '', '', null, 'battery-full', $green)
        ]);
        $area = $this->PresentValue('vector-square', ' m²', 0, [
            $this->Interval(10000, 1.0E12, 10000, ' ha', '', 2, '', -1)
        ]);
        $minutes = $this->PresentValue('clock', ' min', 0, [
            $this->Interval(60, 1.0E9, 60, ' h', '', 1, '', -1)
        ]);
        $energy = $this->PresentValue('bolt', ' Wh', 0, [
            $this->Interval(1000, 1.0E12, 1000, ' kWh', '', 2, '', -1)
        ]);
        $carbon = $this->PresentValue('leaf', ' kg', 1, [
            $this->Interval(1000, 1.0E12, 1000, ' t', '', 2, '', -1)
        ]);
        $control = $this->PresentEnumeration('gamepad', [
            1 => ['Pause', 'pause', -1],
            2 => ['Fortsetzen', 'play', -1],
            3 => ['Stop', 'stop', -1],
            4 => ['Zur Ladestation', 'house', -1],
            5 => ['Heimfahrt abbrechen', 'xmark', -1]
        ]);
        $workResult = $this->PresentStates('flag-checkered', [
            0 => ['Unbekannt', 0xAAAAAA, ''], 1 => ['Läuft', 0x10B981, ''], 2 => ['Pausiert', $yellow, ''],
            3 => ['Vom Nutzer gestoppt', $orange, ''], 4 => ['Unterbrochen', $red, ''], 5 => ['Abgeschlossen', $green, '']
        ]);
        $workType = $this->PresentStates('calendar', [
            0 => ['Unbekannt', -1, ''], 1 => ['Einzeleinsatz', -1, ''], 2 => ['Zeitplan', -1, ''],
            3 => ['Punktmähen', -1, ''], 4 => ['Fortsetzung', -1, '']
        ]);

        $vars = [
            // Frühere HTMLBox-Variable entfernen, die Darstellung übernimmt die Kachel der Instanz
            ['Dashboard', 'Dashboard', VARIABLETYPE_STRING, '', 5, false],
            ['Online', 'Online', VARIABLETYPE_BOOLEAN, $this->PresentBool('wifi', 'Offline', $grey, 'Online', $green), 10, true],
            ['OperationStatus', 'Betriebsstatus', VARIABLETYPE_INTEGER, $operation, 20, true],
            ['Status', 'Status (Rohwert)', VARIABLETYPE_STRING, $this->PresentValue('code'), 21, true],
            ['Battery', 'Akku', VARIABLETYPE_INTEGER, $battery, 30, true],
            ['ChargeStatus', 'Ladestatus (Code)', VARIABLETYPE_INTEGER, $this->PresentValue('plug'), 40, true],
            ['KnifeHeight', 'Mähhöhe (letzter Einsatz)', VARIABLETYPE_INTEGER, $this->PresentValue('ruler-vertical', ' mm'), 50, true],
            ['Speed', 'Geschwindigkeit (Code)', VARIABLETYPE_INTEGER, $this->PresentValue('gauge'), 60, true],
            ['Firmware', 'Firmware', VARIABLETYPE_STRING, $this->PresentValue('microchip'), 70, true],
            ['WifiRSSI', 'WLAN RSSI', VARIABLETYPE_INTEGER, $this->PresentValue('wifi', ' dBm'), 80, true],
            ['WifiIP', 'WLAN IP', VARIABLETYPE_STRING, $this->PresentValue('network-wired'), 90, true],
            ['CellularRSSI', 'Mobilfunk RSSI', VARIABLETYPE_INTEGER, $this->PresentValue('signal', ' dBm'), 100, true],
            ['Control', 'Steuerung', VARIABLETYPE_INTEGER, $control, 110, true],
            ['Task', 'Aufgabe starten', VARIABLETYPE_INTEGER, $this->TaskPresentation(), 120, true],
            ['LastWorkEnd', 'Letzter Einsatz', VARIABLETYPE_INTEGER, $this->PresentDateTime(), 130, $reports],
            ['LastWorkResult', 'Letzter Einsatz – Ergebnis', VARIABLETYPE_INTEGER, $workResult, 131, $reports],
            ['LastWorkType', 'Letzter Einsatz – Art', VARIABLETYPE_INTEGER, $workType, 132, $reports],
            ['LastWorkArea', 'Letzter Einsatz – Fläche', VARIABLETYPE_FLOAT, $area, 133, $reports],
            ['LastWorkDuration', 'Letzter Einsatz – Dauer', VARIABLETYPE_INTEGER, $minutes, 134, $reports],
            ['LastWorkProgress', 'Letzter Einsatz – Fortschritt', VARIABLETYPE_INTEGER, $this->PresentValue('percent', ' %'), 135, $reports],
            ['LastWorkEnergy', 'Letzter Einsatz – Energie', VARIABLETYPE_FLOAT, $energy, 136, $reports],
            ['TotalWorkCount', 'Einsätze gesamt', VARIABLETYPE_INTEGER, $this->PresentValue('hashtag'), 140, $reports],
            ['TotalWorkArea', 'Gemähte Fläche gesamt', VARIABLETYPE_FLOAT, $area, 141, $reports],
            ['TotalSaveTime', 'Zeitersparnis gesamt', VARIABLETYPE_FLOAT, $this->PresentValue('hourglass-half', ' h', 1), 142, $reports],
            ['TotalCarbon', 'CO₂-Einsparung gesamt', VARIABLETYPE_FLOAT, $carbon, 143, $reports],
            ['LastErrorText', 'Letzter Gerätefehler', VARIABLETYPE_STRING, $this->PresentValue('triangle-exclamation'), 150, $reports],
            ['LastErrorTime', 'Letzter Gerätefehler – Zeitpunkt', VARIABLETYPE_INTEGER, $this->PresentDateTime(), 151, $reports],
            ['ErrorCount30d', 'Gerätefehler (30 Tage)', VARIABLETYPE_INTEGER, $this->PresentValue('list-ol'), 152, $reports],
            ['SystemState', 'Systemzustand', VARIABLETYPE_INTEGER, $system, 200, true],
            ['Diagnostic', 'Diagnose', VARIABLETYPE_STRING, $this->PresentValue('stethoscope'), 210, true],
            ['LastCommand', 'Letzter Befehl', VARIABLETYPE_STRING, $this->PresentValue('terminal'), 220, true],
            ['LastSuccess', 'Letzte erfolgreiche Aktualisierung', VARIABLETYPE_INTEGER, $this->PresentDateTime(true), 230, true],
            ['LastAttempt', 'Letzter Abrufversuch', VARIABLETYPE_INTEGER, $this->PresentDateTime(true), 240, true]
        ];
        foreach ($vars as [$ident, $name, $type, $presentation, $position, $keep]) {
            $this->MaintainVariable($ident, $name, $type, $presentation, $position, $keep);
        }
        $this->EnableAction('Control');
        $this->EnableAction('Task');
    }

    /**
     * Einmalig nach dem Update: Profile früherer Versionen löschen, sofern sie nicht mehr verwendet werden.
     */
    private function CleanupLegacyProfiles(): void
    {
        $taskProfile = self::LEGACY_TASK_PROFILE_PREFIX . $this->InstanceID;
        if (IPS_VariableProfileExists($taskProfile)) {
            IPS_DeleteVariableProfile($taskProfile);
        }
        if (!$this->ReadAttributeBoolean('LegacyCleanupDone')) {
            $this->RemoveUnusedProfiles(self::LEGACY_PROFILES);
            $this->WriteAttributeBoolean('LegacyCleanupDone', true);
        }
    }

    // ------------------------------------------------------------------
    // Kachel-Visualisierung (HTML-SDK)
    // ------------------------------------------------------------------

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/module.html');
        // Hintergrundbild nur einmal beim Laden der Kachel übertragen, nicht bei jedem Update
        if ($this->ReadPropertyInteger('TileBackgroundMode') === 1) {
            $image = $this->BuildBackgroundImage();
            if ($image['error'] === '') {
                $html = '<style>.tile{--img:url("' . $image['uri'] . '")}</style>' . $html;
            }
        }
        $state = (string) json_encode($this->BuildTileState(), JSON_UNESCAPED_UNICODE);
        return $html . '<script>handleMessage(' . json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');</script>';
    }

    /**
     * Liefert das gewählte Medienobjekt als data-URI oder eine Fehlerbeschreibung.
     */
    private function BuildBackgroundImage(): array
    {
        $error = $this->BackgroundError();
        if ($error !== '') {
            return ['uri' => '', 'error' => $error];
        }
        $mediaID = $this->ReadPropertyInteger('TileBackgroundMedia');
        $media = IPS_GetMedia($mediaID);
        $content = (string) IPS_GetMediaContent($mediaID);
        if ($content === '') {
            return ['uri' => '', 'error' => 'das Medienobjekt enthält keine Bilddaten'];
        }
        if (strlen($content) > 3 * 1024 * 1024) {
            return ['uri' => '', 'error' => 'Bild ist größer als ca. 2 MB, bitte verkleinern'];
        }
        $mime = self::IMAGE_TYPES[strtolower(pathinfo((string) $media['MediaFile'], PATHINFO_EXTENSION))];
        return ['uri' => 'data:' . $mime . ';base64,' . preg_replace('/[^A-Za-z0-9+\/=]/', '', $content), 'error' => ''];
    }

    /**
     * Schnelle Prüfung ohne die Bilddaten zu lesen (für die minütlichen Kachel-Updates).
     */
    private function BackgroundError(): string
    {
        $mediaID = $this->ReadPropertyInteger('TileBackgroundMedia');
        if ($mediaID <= 0 || !IPS_MediaExists($mediaID)) {
            return 'kein Medienobjekt ausgewählt';
        }
        $media = IPS_GetMedia($mediaID);
        if ((int) $media['MediaType'] !== MEDIATYPE_IMAGE) {
            return 'das gewählte Medienobjekt ist kein Bild';
        }
        if ((int) ($media['MediaSize'] ?? 0) > 2 * 1024 * 1024) {
            return 'Bild ist größer als ca. 2 MB, bitte verkleinern';
        }
        if (!isset(self::IMAGE_TYPES[strtolower(pathinfo((string) $media['MediaFile'], PATHINFO_EXTENSION))])) {
            return 'Bildformat nicht unterstützt (JPG, PNG, WebP oder GIF verwenden)';
        }
        return '';
    }

    /**
     * Meldet das Medienobjekt als Referenz an, damit IP-Symcon beim Löschen warnt.
     */
    private function UpdateMediaReference(): void
    {
        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        $mediaID = $this->ReadPropertyInteger('TileBackgroundMedia');
        if ($this->ReadPropertyInteger('TileBackgroundMode') === 1 && $mediaID > 0 && IPS_MediaExists($mediaID)) {
            $this->RegisterReference($mediaID);
        }
    }

    private function UpdateTile(): void
    {
        if (!$this->ReadPropertyBoolean('EnableTile')) {
            return;
        }
        $json = (string) json_encode($this->BuildTileState(), JSON_UNESCAPED_UNICODE);
        // Geschwindigkeit: nur senden, wenn sich der Inhalt geändert hat
        $hash = md5($json);
        if ($hash === $this->GetBuffer('TileHash')) {
            return;
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue($json);
    }

    private function BuildTileState(): array
    {
        // Kein App-Nickname: Symcon zeigt den Instanznamen bereits in der Kachel
        $model = $this->ReadAttributeString('DeviceModel');
        $firmware = trim((string) $this->GetValue('Firmware'));

        $tasks = [];
        foreach (json_decode($this->ReadAttributeString('TaskMap'), true) ?: [] as $value => $task) {
            $tasks[] = ['value' => (int) $value, 'name' => (string) $task['name']];
        }

        return [
            'title'       => $model !== '' ? $model : 'Mähroboter',
            'subtitle'    => $firmware !== '' ? 'Mammotion · Firmware ' . $firmware : 'Mammotion',
            'image'       => $this->ReadAttributeString('DeviceIconURL'),
            'active'      => $this->ReadPropertyBoolean('Active'),
            'control'     => $this->ReadPropertyBoolean('EnableControl'),
            'online'      => (bool) $this->GetValue('Online'),
            'op'          => (int) $this->GetValue('OperationStatus'),
            'raw'         => (string) $this->GetValue('Status'),
            'system'      => (int) $this->GetValue('SystemState'),
            'battery'     => (int) $this->GetValue('Battery'),
            'knife'       => (int) $this->GetValue('KnifeHeight'),
            'wifi'        => (int) $this->GetValue('WifiRSSI'),
            'cell'        => (int) $this->GetValue('CellularRSSI'),
            'firmware'    => (string) $this->GetValue('Firmware'),
            'lastSuccess' => (int) $this->GetValue('LastSuccess'),
            'lastCommand' => (string) $this->GetValue('LastCommand'),
            'diagnostic'  => (string) $this->GetValue('Diagnostic'),
            'tasks'       => $tasks,
            'reports'     => $this->ReadPropertyBoolean('EnableReports'),
            'last'        => $this->ReadPropertyBoolean('EnableReports') ? [
                'end'      => (int) $this->GetValue('LastWorkEnd'),
                'result'   => (int) $this->GetValue('LastWorkResult'),
                'area'     => (float) $this->GetValue('LastWorkArea'),
                'minutes'  => (int) $this->GetValue('LastWorkDuration'),
                'progress' => (int) $this->GetValue('LastWorkProgress')
            ] : null,
            'totals'      => $this->ReadPropertyBoolean('EnableReports') ? [
                'count'  => (int) $this->GetValue('TotalWorkCount'),
                'area'   => (float) $this->GetValue('TotalWorkArea'),
                'hours'  => (float) $this->GetValue('TotalSaveTime'),
                'carbon' => (float) $this->GetValue('TotalCarbon')
            ] : null,
            'error'       => $this->ReadPropertyBoolean('EnableReports') ? [
                'text' => (string) $this->GetValue('LastErrorText'),
                'time' => (int) $this->GetValue('LastErrorTime')
            ] : null,
            'version'     => self::MODULE_VERSION,
            'bg'          => [
                'mode' => $this->ReadPropertyInteger('TileBackgroundMode') === 1 && $this->BackgroundError() !== '' ? 0 : $this->ReadPropertyInteger('TileBackgroundMode'),
                'dim'  => max(0, min(90, $this->ReadPropertyInteger('TileBackgroundDim')))
            ]
        ];
    }
}
