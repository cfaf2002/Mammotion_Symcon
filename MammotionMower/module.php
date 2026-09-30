<?php

declare(strict_types=1);

/**
 * Mammotion Mäher
 *
 * Eine Instanz je Mähroboter. Holt Status und Werte zyklisch über die
 * übergeordnete Mammotion-Cloud-Instanz und stellt Steuerbefehle bereit.
 */
class MammotionMower extends IPSModule
{
    private const MODULE_VERSION = '2.0';
    private const CLOUD_MODULE = '{D26140D0-FC03-43F8-AAB0-1E4220D959EB}';
    private const DATA_TX = '{5F140107-E29A-41AA-9314-01891DDE02F9}';

    private const PROFILE_OPERATION = 'MAMMO.OperationStatus';
    private const PROFILE_SYSTEM = 'MAMMO.SystemState';
    private const PROFILE_CONTROL = 'MAMMO.Control';
    private const PROFILE_MM = 'MAMMO.Millimeter';
    private const PROFILE_DBM = 'MAMMO.dBm';
    private const PROFILE_TASKS_PREFIX = 'MAMMO.Tasks.';

    private const MIN_INTERVAL = 30;
    private const REFRESH_LOCK_TIMEOUT = 180;
    private const RETRY_DELAYS = [5, 15];
    private const COMMAND_REFRESH_DELAY = 5;

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

    private const ACTIONS = [1 => 'PAUSE', 2 => 'RESUME', 3 => 'STOP', 4 => 'RETURN', 5 => 'CANCEL_RETURN'];

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent(self::CLOUD_MODULE);

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->RegisterPropertyBoolean('ShowDashboard', true);

        $this->RegisterAttributeString('ResolvedDeviceID', '');
        $this->RegisterAttributeString('DeviceNickname', '');
        $this->RegisterAttributeString('DeviceApiName', '');
        $this->RegisterAttributeString('DeviceModel', '');
        $this->RegisterAttributeString('DeviceIconURL', '');
        $this->RegisterAttributeString('TaskMap', '{}');
        $this->RegisterAttributeInteger('RefreshLockSince', 0);
        $this->RegisterAttributeInteger('RetryAttempt', 0);

        $this->RegisterTimer('UpdateTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerUpdate", 0);');
        $this->RegisterTimer('RetryTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerRetry", 0);');
        $this->RegisterTimer('DelayedRefreshTimer', 0, 'IPS_RequestAction($_IPS["TARGET"], "TimerDelayed", 0);');

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function Destroy(): void
    {
        if (!IPS_InstanceExists($this->InstanceID) && IPS_VariableProfileExists($this->TaskProfile())) {
            IPS_DeleteVariableProfile($this->TaskProfile());
        }
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->EnsureProfiles();
        $this->MaintainVariables();
        $this->ReleaseRefreshLock();
        $this->ResetRetry();

        if (trim($this->ReadPropertyString('DeviceID')) !== $this->ReadAttributeString('ResolvedDeviceID')) {
            $this->WriteAttributeString('ResolvedDeviceID', trim($this->ReadPropertyString('DeviceID')));
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->Initialize();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Initialize();
        }
    }

    public function ReceiveData($JSONString): string
    {
        // Die Cloud-Instanz sendet derzeit keine Daten aktiv an die Mäher.
        return '';
    }

    public function RequestAction($Ident, $Value): void
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
            return 'DEAKTIVIERT: Abruf ist für diesen Mäher ausgeschaltet.';
        }
        $ok = $this->Refresh();
        if ($this->ReadAttributeInteger('RetryAttempt') > 0) {
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

    // ------------------------------------------------------------------
    // Initialisierung
    // ------------------------------------------------------------------

    private function Initialize(): void
    {
        $this->SetTimerInterval('DelayedRefreshTimer', 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetValue('SystemState', self::SYS_DISABLED);
            $this->SetValue('Diagnostic', 'Abruf für diesen Mäher ist ausgeschaltet');
            $this->SetStatus(self::STATUS_INACTIVE);
            $this->UpdateDashboard();
            return;
        }

        if ($this->ReadPropertyInteger('PollInterval') < self::MIN_INTERVAL) {
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetValue('SystemState', self::SYS_ERROR);
            $this->SetValue('Diagnostic', 'Abfrageintervall muss mindestens ' . self::MIN_INTERVAL . ' Sekunden betragen');
            $this->SetStatus(self::STATUS_CONFIG);
            $this->UpdateDashboard();
            return;
        }

        $this->SetValue('SystemState', self::SYS_INIT);
        $this->SetStatus(self::STATUS_ACTIVE);
        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('PollInterval') * 1000);
        $this->SetTimerInterval('DelayedRefreshTimer', 2000);
        $this->UpdateDashboard();
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
            $this->UpdateDashboard();
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
            $list = $this->Api('GET', '/v1/mowers');
            $steps[] = 'Geräteliste OK';

            $device = $this->SelectDevice(is_array($list['data'] ?? null) ? $list['data'] : []);
            $id = (string) ($device['id'] ?? '');
            $this->WriteAttributeString('ResolvedDeviceID', $id);
            $this->StoreDeviceMetadata($device);

            if (((int) ($device['online'] ?? 0)) !== 1) {
                $steps[] = 'Mäher offline (laut Geräteliste)';
                $this->CompleteOffline($steps);
                return true;
            }

            $detail = $this->Api('GET', '/v1/mower/' . rawurlencode($id));
            $this->ApplyDeviceDetails(is_array($detail['data'] ?? null) ? $detail['data'] : []);
            $steps[] = 'Gerätestatus OK';

            $partial = [];
            try {
                $params = $this->Api('GET', '/v1/mower/' . rawurlencode($id) . '/work-params');
                $p = is_array($params['data'] ?? null) ? $params['data'] : [];
                $this->SetValue('KnifeHeight', (int) ($p['knifeHeight'] ?? 0));
                $this->SetValue('Speed', (int) ($p['speed'] ?? 0));
                $steps[] = 'Arbeitsparameter OK';
            } catch (RuntimeException $e) {
                if ($e->getCode() === self::ERR_AUTH) throw $e;
                $partial[] = 'Arbeitsparameter: ' . $e->getMessage();
            }
            try {
                $plans = $this->Api('GET', '/v1/mower/' . rawurlencode($id) . '/plan');
                $this->UpdateTasks(is_array($plans['data'] ?? null) ? $plans['data'] : []);
                $steps[] = 'Aufgaben OK';
            } catch (RuntimeException $e) {
                if ($e->getCode() === self::ERR_AUTH) throw $e;
                $partial[] = 'Aufgaben: ' . $e->getMessage();
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
            $this->UpdateDashboard();
        }
    }

    private function SelectDevice(array $devices): array
    {
        $configured = trim($this->ReadPropertyString('DeviceID'));
        if ($configured === '') {
            if (count($devices) === 0) {
                throw new RuntimeException('Keine Mäher im Mammotion-Konto gefunden', self::ERR_NOT_FOUND);
            }
            return (array) $devices[0];
        }
        foreach ($devices as $device) {
            if ((string) ($device['id'] ?? '') === $configured) {
                return (array) $device;
            }
        }
        throw new RuntimeException('Device-ID ' . $configured . ' wurde im Mammotion-Konto nicht gefunden', self::ERR_NOT_FOUND);
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
        $attempt = $this->ReadAttributeInteger('RetryAttempt');
        if ($attempt >= count(self::RETRY_DELAYS)) {
            return false;
        }
        $delay = self::RETRY_DELAYS[$attempt];
        $attempt++;
        $this->WriteAttributeInteger('RetryAttempt', $attempt);
        $this->SetValue('Diagnostic', 'Wiederholung ' . $attempt . '/' . count(self::RETRY_DELAYS) . ' in ' . $delay . ' s: ' . $message);
        $this->SetTimerInterval('RetryTimer', $delay * 1000);
        return true;
    }

    private function ResetRetry(): void
    {
        $this->SetTimerInterval('RetryTimer', 0);
        $this->WriteAttributeInteger('RetryAttempt', 0);
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
            $since = $this->ReadAttributeInteger('RefreshLockSince');
            if ($since > 0 && (time() - $since) < self::REFRESH_LOCK_TIMEOUT) {
                return false;
            }
            if ($since > 0) {
                $this->SendDebug('Refresh', 'Veraltete Sperre vom ' . date('d.m.Y H:i:s', $since) . ' übernommen', 0);
            }
            $this->WriteAttributeInteger('RefreshLockSince', time());
            return true;
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function ReleaseRefreshLock(): void
    {
        $this->WriteAttributeInteger('RefreshLockSince', 0);
    }

    // ------------------------------------------------------------------
    // Befehle
    // ------------------------------------------------------------------

    private function SendCommand(string $action, ?array $params = null): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            throw new RuntimeException('Abruf für diesen Mäher ist ausgeschaltet.');
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
            throw $e;
        }
        $ok = (bool) ($response['data']['commandResult'] ?? true);
        $this->SetValue('LastCommand', $action . ($ok ? ' gesendet' : ' abgelehnt') . ' (' . date('d.m.Y H:i:s') . ')');
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
        $this->WriteAttributeString('DeviceNickname', trim((string) ($device['nickname'] ?? '')));
        $this->WriteAttributeString('DeviceApiName', trim((string) ($device['name'] ?? '')));
        $this->WriteAttributeString('DeviceModel', trim((string) ($device['model'] ?? '')));
        $icon = trim((string) ($device['icon'] ?? ''));
        $this->WriteAttributeString('DeviceIconURL', filter_var($icon, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $icon) ? $icon : '');
    }

    private function ApplyDeviceDetails(array $data): void
    {
        $network = is_array($data['network'] ?? null) ? $data['network'] : [];
        $online = ((int) ($data['online'] ?? 1)) === 1;
        $raw = (string) ($data['status'] ?? '');
        $this->SetValue('Online', $online);
        $this->SetValue('Status', $raw !== '' ? $raw : 'Unbekannt');
        $this->SetValue('OperationStatus', $this->MapOperationStatus($raw, $online));
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
        $profile = $this->TaskProfile();
        foreach (IPS_GetVariableProfile($profile)['Associations'] as $a) {
            IPS_SetVariableProfileAssociation($profile, (int) $a['Value'], '', '', -1);
        }
        $map = [];
        $i = 1;
        foreach ($tasks as $task) {
            $name = trim((string) ($task['taskName'] ?? ''));
            if ($name === '') continue;
            $map[(string) $i] = ['id' => (string) ($task['taskId'] ?? ''), 'name' => $name];
            IPS_SetVariableProfileAssociation($profile, $i, $name, '', -1);
            $i++;
        }
        $this->WriteAttributeString('TaskMap', (string) json_encode($map, JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------
    // Variablen und Profile
    // ------------------------------------------------------------------

    private function MaintainVariables(): void
    {
        $vars = [
            ['Dashboard', 'Dashboard', VARIABLETYPE_STRING, '~HTMLBox', 5, $this->ReadPropertyBoolean('ShowDashboard')],
            ['Online', 'Online', VARIABLETYPE_BOOLEAN, '~Switch', 10, true],
            ['OperationStatus', 'Betriebsstatus', VARIABLETYPE_INTEGER, self::PROFILE_OPERATION, 20, true],
            ['Status', 'Status (Rohwert)', VARIABLETYPE_STRING, '', 21, true],
            ['Battery', 'Akku', VARIABLETYPE_INTEGER, '~Battery.100', 30, true],
            ['ChargeStatus', 'Ladestatus (Code)', VARIABLETYPE_INTEGER, '', 40, true],
            ['KnifeHeight', 'Mähhöhe', VARIABLETYPE_INTEGER, self::PROFILE_MM, 50, true],
            ['Speed', 'Geschwindigkeit (Code)', VARIABLETYPE_INTEGER, '', 60, true],
            ['Firmware', 'Firmware', VARIABLETYPE_STRING, '', 70, true],
            ['WifiRSSI', 'WLAN RSSI', VARIABLETYPE_INTEGER, self::PROFILE_DBM, 80, true],
            ['WifiIP', 'WLAN IP', VARIABLETYPE_STRING, '', 90, true],
            ['CellularRSSI', 'Mobilfunk RSSI', VARIABLETYPE_INTEGER, self::PROFILE_DBM, 100, true],
            ['Control', 'Steuerung', VARIABLETYPE_INTEGER, self::PROFILE_CONTROL, 110, true],
            ['Task', 'Aufgabe starten', VARIABLETYPE_INTEGER, $this->TaskProfile(), 120, true],
            ['SystemState', 'Systemzustand', VARIABLETYPE_INTEGER, self::PROFILE_SYSTEM, 200, true],
            ['Diagnostic', 'Diagnose', VARIABLETYPE_STRING, '', 210, true],
            ['LastCommand', 'Letzter Befehl', VARIABLETYPE_STRING, '', 220, true],
            ['LastSuccess', 'Letzte erfolgreiche Aktualisierung', VARIABLETYPE_INTEGER, '~UnixTimestamp', 230, true],
            ['LastAttempt', 'Letzter Abrufversuch', VARIABLETYPE_INTEGER, '~UnixTimestamp', 240, true]
        ];
        foreach ($vars as [$ident, $name, $type, $profile, $position, $keep]) {
            $this->MaintainVariable($ident, $name, $type, $profile, $position, $keep);
        }
        $this->EnableAction('Control');
        $this->EnableAction('Task');
    }

    private function TaskProfile(): string
    {
        return self::PROFILE_TASKS_PREFIX . $this->InstanceID;
    }

    private function EnsureProfiles(): void
    {
        $this->EnsureIntegerProfile(self::PROFILE_OPERATION, 'Information', '', [
            [self::OP_OFFLINE, 'Offline', 0x808080], [self::OP_READY, 'Bereit', 0x00AA00],
            [self::OP_MOWING, 'Mäht', 0x00CC66], [self::OP_PAUSED, 'Pausiert', 0xFFCC00],
            [self::OP_CHARGING, 'Lädt', 0x3399FF], [self::OP_RETURNING, 'Heimfahrt', 0x8B5CF6],
            [self::OP_DEVICE_ERROR, 'Gerätefehler', 0xFF0000], [self::OP_CLOUD_ERROR, 'API/Cloud-Fehler', 0xFF8800],
            [self::OP_UNKNOWN, 'Unbekannt', 0xAAAAAA]
        ]);
        $this->EnsureIntegerProfile(self::PROFILE_SYSTEM, 'Gear', '', [
            [self::SYS_INIT, 'Initialisierung', 0xAAAAAA], [self::SYS_CHECKING, 'Prüfung läuft', 0x3399FF],
            [self::SYS_READY, 'Betriebsbereit', 0x00AA00], [self::SYS_PARTIAL, 'Teilweise verfügbar', 0xFFCC00],
            [self::SYS_OFFLINE, 'Offline', 0x808080], [self::SYS_ERROR, 'Fehler', 0xFF0000],
            [self::SYS_DISABLED, 'Deaktiviert', 0x777777]
        ]);
        $this->EnsureIntegerProfile(self::PROFILE_CONTROL, 'Execute', '', [
            [1, 'Pause', -1], [2, 'Fortsetzen', -1], [3, 'Stop', -1],
            [4, 'Zur Ladestation', -1], [5, 'Heimfahrt abbrechen', -1]
        ]);
        $this->EnsureIntegerProfile(self::PROFILE_MM, 'Distance', ' mm', []);
        $this->EnsureIntegerProfile(self::PROFILE_DBM, 'Intensity', ' dBm', []);
        $this->EnsureIntegerProfile($this->TaskProfile(), 'Script', '', []);
    }

    private function EnsureIntegerProfile(string $name, string $icon, string $suffix, array $associations): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon($name, $icon);
            if ($suffix !== '') {
                IPS_SetVariableProfileText($name, '', $suffix);
            }
        }
        foreach ($associations as [$value, $caption, $color]) {
            IPS_SetVariableProfileAssociation($name, $value, $caption, '', $color);
        }
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    private function UpdateDashboard(): void
    {
        if (!$this->ReadPropertyBoolean('ShowDashboard')) {
            return;
        }
        $this->SetValue('Dashboard', $this->BuildDashboard());
    }

    private function BuildDashboard(): string
    {
        $nickname = $this->ReadAttributeString('DeviceNickname');
        $apiName = $this->ReadAttributeString('DeviceApiName');
        $model = $this->ReadAttributeString('DeviceModel');
        $iconUrl = $this->ReadAttributeString('DeviceIconURL');
        $instanceName = IPS_GetName($this->InstanceID);
        $title = $nickname !== '' ? $nickname : ($apiName !== '' ? $apiName : ($instanceName !== '' ? $instanceName : 'MAMMOTION'));

        $active = $this->ReadPropertyBoolean('Active');
        $online = (bool) $this->GetValue('Online');
        $operation = (int) $this->GetValue('OperationStatus');
        $system = (int) $this->GetValue('SystemState');
        $battery = max(0, min(100, (int) $this->GetValue('Battery')));
        $knifeHeight = (int) $this->GetValue('KnifeHeight');
        $wifi = (int) $this->GetValue('WifiRSSI');
        $firmware = trim((string) $this->GetValue('Firmware'));
        $lastSuccess = (int) $this->GetValue('LastSuccess');

        $states = [
            self::OP_OFFLINE      => ['Offline', '#64748b', 'OFFLINE'],
            self::OP_READY        => ['Bereit', '#22c55e', 'BEREIT'],
            self::OP_MOWING       => ['Mäht', '#10b981', 'AKTIV'],
            self::OP_PAUSED       => ['Pausiert', '#f59e0b', 'PAUSE'],
            self::OP_CHARGING     => ['Lädt', '#3b82f6', 'LADEN'],
            self::OP_RETURNING    => ['Heimfahrt', '#8b5cf6', 'HEIMFAHRT'],
            self::OP_DEVICE_ERROR => ['Gerätefehler', '#ef4444', 'FEHLER'],
            self::OP_CLOUD_ERROR  => ['API/Cloud-Fehler', '#f97316', 'API-FEHLER'],
            self::OP_UNKNOWN      => ['Unbekannt', '#94a3b8', 'UNBEKANNT']
        ];
        [$status, $accent, $badge] = $states[$operation] ?? $states[self::OP_UNKNOWN];

        if (!$active || $system === self::SYS_DISABLED) {
            [$status, $accent, $badge] = ['Abruf deaktiviert', '#64748b', 'DEAKTIVIERT'];
        } elseif ($system === self::SYS_INIT && $lastSuccess === 0) {
            [$status, $accent, $badge] = ['Wird initialisiert', '#94a3b8', 'START'];
        } elseif ($system === self::SYS_ERROR && $operation !== self::OP_CLOUD_ERROR) {
            [$status, $accent, $badge] = ['Systemfehler', '#ef4444', 'FEHLER'];
        } elseif (!$online && $operation !== self::OP_CLOUD_ERROR) {
            [$status, $accent, $badge] = ['Offline', '#64748b', 'OFFLINE'];
        } elseif ($system === self::SYS_PARTIAL) {
            $badge = 'TEILWEISE';
        }

        $batteryColor = $battery < 20 ? '#ef4444' : ($battery < 40 ? '#f59e0b' : '#22c55e');
        $batteryText = $battery >= 70 ? 'Sehr gut' : ($battery >= 40 ? 'Gut' : ($battery >= 20 ? 'Niedrig' : 'Kritisch'));
        $wifiValue = $wifi === 0 ? 'Keine Daten' : $wifi . ' dBm';
        $wifiQuality = $wifi === 0 ? 'Unbekannt' : ($wifi >= -55 ? 'Sehr gut' : ($wifi >= -67 ? 'Gut' : ($wifi >= -75 ? 'Mittel' : 'Schwach')));
        $update = $lastSuccess > 0 ? date('d.m.Y · H:i', $lastSuccess) : 'Noch keine Aktualisierung';
        $model = $model !== '' ? $model : 'Mammotion Mäher';
        $firmware = $firmware !== '' ? $firmware : 'Unbekannt';
        $knife = $knifeHeight > 0 ? $knifeHeight . ' mm' : '–';

        $e = function (string $v): string {
            return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        };
        $visual = $iconUrl !== ''
            ? '<img class="mower-img" src="' . $e($iconUrl) . '" alt="">'
            : '<div class="mower-fallback">M</div>';

        $css = '.mcard{box-sizing:border-box;width:100%;min-height:330px;padding:22px;border-radius:26px;color:#f8fafc;'
            . 'background:radial-gradient(circle at 88% 5%,color-mix(in srgb,var(--a) 22%,transparent),transparent 34%),linear-gradient(145deg,#182235 0%,#0c1423 62%,#060a12 100%);'
            . 'font-family:Inter,Segoe UI,Arial,sans-serif;box-shadow:0 20px 55px rgba(2,6,23,.44);overflow:hidden}'
            . '.mcard *{box-sizing:border-box}.top{display:flex;justify-content:space-between;gap:14px;align-items:flex-start}'
            . '.identity{display:flex;gap:14px;align-items:center;min-width:0}'
            . '.device-visual{width:76px;height:76px;display:grid;place-items:center;border-radius:21px;background:linear-gradient(145deg,rgba(255,255,255,.11),rgba(255,255,255,.025));border:1px solid rgba(255,255,255,.11);overflow:hidden;flex:0 0 auto}'
            . '.mower-img{display:block;width:100%;height:100%;object-fit:contain;padding:6px;filter:drop-shadow(0 8px 12px rgba(0,0,0,.32))}'
            . '.mower-fallback{font-size:34px;font-weight:900;color:var(--a)}'
            . '.name{font-size:25px;font-weight:850;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.model{margin-top:5px;color:#94a3b8;font-size:12px}'
            . '.status-dot{display:inline-block;width:7px;height:7px;margin-right:6px;border-radius:50%;background:var(--a);box-shadow:0 0 12px var(--a)}'
            . '.badge{padding:7px 10px;border-radius:999px;background:color-mix(in srgb,var(--a) 18%,transparent);border:1px solid color-mix(in srgb,var(--a) 58%,transparent);color:var(--a);font-size:10px;font-weight:850;letter-spacing:.1em;white-space:nowrap}'
            . '.hero{display:grid;grid-template-columns:125px 1fr;gap:22px;align-items:center;margin-top:22px}'
            . '.ring{width:122px;height:122px;display:grid;place-items:center;border-radius:50%;position:relative;background:conic-gradient(var(--b) var(--p),rgba(148,163,184,.14) 0)}'
            . '.ring:before{content:"";position:absolute;inset:10px;border-radius:50%;background:#0d1625;box-shadow:inset 0 0 24px rgba(0,0,0,.38)}'
            . '.ring-in{position:relative;text-align:center}.pct{font-size:31px;font-weight:900}'
            . '.small{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.15em}'
            . '.state{font-size:27px;font-weight:850;line-height:1.1}'
            . '.line{width:52px;height:4px;margin:12px 0 0;border-radius:4px;background:var(--a);box-shadow:0 0 18px color-mix(in srgb,var(--a) 70%,transparent)}'
            . '.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:20px}'
            . '.metric{padding:13px 14px;border-radius:16px;background:rgba(15,23,42,.64);border:1px solid rgba(148,163,184,.12)}'
            . '.mn{color:#94a3b8;font-size:9px;text-transform:uppercase;letter-spacing:.12em}.mv{margin-top:6px;font-size:15px;font-weight:780}'
            . '.hint{margin-top:3px;color:#64748b;font-size:10px}'
            . '.foot{display:flex;justify-content:space-between;gap:12px;margin-top:16px;padding-top:14px;border-top:1px solid rgba(148,163,184,.12);color:#64748b;font-size:10px}'
            . '@media(max-width:520px){.mcard{padding:16px}.device-visual{width:60px;height:60px}.name{font-size:20px}.hero{grid-template-columns:95px 1fr;gap:15px}'
            . '.ring{width:94px;height:94px}.pct{font-size:24px}.state{font-size:22px}.metrics{grid-template-columns:1fr 1fr}.metric:last-child{grid-column:1/-1}.foot{flex-direction:column}}';

        return '<div class="mcard" style="--a:' . $accent . ';--b:' . $batteryColor . ';--p:' . $battery . '%">'
            . '<style>' . $css . '</style>'
            . '<div class="top"><div class="identity"><div class="device-visual">' . $visual . '</div>'
            . '<div><div class="name">' . $e($title) . '</div><div class="model">' . $e($model) . '</div></div></div>'
            . '<div class="badge"><span class="status-dot"></span>' . $e($badge) . '</div></div>'
            . '<div class="hero"><div class="ring"><div class="ring-in"><div class="pct">' . $battery . '%</div>'
            . '<div class="small">Akku · ' . $batteryText . '</div></div></div>'
            . '<div><div class="state">' . $e($status) . '</div><div class="line"></div></div></div>'
            . '<div class="metrics">'
            . '<div class="metric"><div class="mn">Mähhöhe</div><div class="mv">' . $e($knife) . '</div></div>'
            . '<div class="metric"><div class="mn">WLAN</div><div class="mv">' . $e($wifiValue) . '</div><div class="hint">' . $e($wifiQuality) . '</div></div>'
            . '<div class="metric"><div class="mn">Firmware</div><div class="mv">' . $e($firmware) . '</div></div>'
            . '</div>'
            . '<div class="foot"><span>Aktualisiert: ' . $e($update) . '</span><span>v' . self::MODULE_VERSION . '</span></div></div>';
    }
}
