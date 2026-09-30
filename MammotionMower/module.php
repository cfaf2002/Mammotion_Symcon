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
    private const MODULE_VERSION = '1.0';
    private const MODULE_BUILD = 1;
    private const CLOUD_MODULE = '{D26140D0-FC03-43F8-AAB0-1E4220D959EB}';
    private const DATA_TX = '{5F140107-E29A-41AA-9314-01891DDE02F9}';

    private const PROFILE_OPERATION = 'MAMMO.OperationStatus';
    private const PROFILE_ONLINE = 'MAMMO.Online';
    private const PROFILE_SYSTEM = 'MAMMO.SystemState';
    private const PROFILE_CONTROL = 'MAMMO.Control';
    private const PROFILE_MM = 'MAMMO.Millimeter';
    private const PROFILE_DBM = 'MAMMO.dBm';
    private const PROFILE_AREA = 'MAMMO.SquareMeter';
    private const PROFILE_MINUTES = 'MAMMO.Minutes';
    private const PROFILE_PERCENT = 'MAMMO.Percent';
    private const PROFILE_WH = 'MAMMO.Wh';
    private const PROFILE_HOURS = 'MAMMO.Hours';
    private const PROFILE_KG = 'MAMMO.Kilogram';
    private const PROFILE_WORK_RESULT = 'MAMMO.WorkResult';
    private const PROFILE_WORK_TYPE = 'MAMMO.WorkType';
    private const PROFILE_TASKS_PREFIX = 'MAMMO.Tasks.';

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

    private const ACTIONS = [1 => 'PAUSE', 2 => 'RESUME', 3 => 'STOP', 4 => 'RETURN', 5 => 'CANCEL_RETURN'];

    public function Create(): void
    {
        parent::Create();

        $this->ConnectParent(self::CLOUD_MODULE);

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyBoolean('EnableControl', false);
        $this->RegisterPropertyBoolean('EnableTile', true);
        $this->RegisterPropertyBoolean('EnableReports', true);

        $this->RegisterAttributeString('ResolvedDeviceID', '');
        $this->RegisterAttributeString('DeviceNickname', '');
        $this->RegisterAttributeString('DeviceApiName', '');
        $this->RegisterAttributeString('DeviceModel', '');
        $this->RegisterAttributeString('DeviceIconURL', '');
        $this->RegisterAttributeString('TaskMap', '{}');
        $this->RegisterAttributeInteger('RefreshLockSince', 0);
        $this->RegisterAttributeInteger('RetryAttempt', 0);
        $this->RegisterAttributeInteger('NextExtrasFetch', 0);
        $this->RegisterAttributeString('ExtrasError', '');
        $this->RegisterAttributeString('LastWorkId', '');
        $this->RegisterAttributeString('RequestVariants', '{}');
        $this->RegisterAttributeString('Unavailable', '{}');
        $this->RegisterAttributeInteger('PreviousOperation', -1);

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
        $this->SetVisualizationType($this->ReadPropertyBoolean('EnableTile') ? 1 : 0);
        $this->ReleaseRefreshLock();
        $this->ResetRetry();
        $this->WriteAttributeInteger('NextExtrasFetch', 0);

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
            return 'DEAKTIVIERT: Instanz ist deaktiviert.';
        }
        // Manueller Abruf liest alle Zusatzdaten neu, auch zuvor abgelehnte
        $this->WriteAttributeInteger('NextExtrasFetch', 0);
        $this->WriteAttributeString('Unavailable', '{}');
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

            // Nach Ende eines Einsatzes Verlauf und Statistik zeitnah nachladen
            $operation = (int) $this->GetValue('OperationStatus');
            $previous = $this->ReadAttributeInteger('PreviousOperation');
            if (in_array($previous, [self::OP_MOWING, self::OP_RETURNING], true) && !in_array($operation, [self::OP_MOWING, self::OP_RETURNING, self::OP_PAUSED], true)) {
                $this->WriteAttributeInteger('NextExtrasFetch', min($this->ReadAttributeInteger('NextExtrasFetch'), time() + 120));
            }
            $this->WriteAttributeInteger('PreviousOperation', $operation);

            // Aufgaben, Statistik, Verlauf und Fehlerprotokoll ändern sich selten und werden seltener abgefragt.
            // Der Endpunkt /work-params wird bewusst NICHT verwendet (siehe README, Sicherheitshinweis).
            $partial = [];
            if (time() >= $this->ReadAttributeInteger('NextExtrasFetch')) {
                $jobs = ['Aufgaben' => 'FetchTasks'];
                if ($this->ReadPropertyBoolean('EnableReports')) {
                    $jobs += ['Statistik' => 'FetchSummary', 'Verlauf' => 'FetchLastWork', 'Fehlerprotokoll' => 'FetchErrors'];
                }
                $unavailable = json_decode($this->ReadAttributeString('Unavailable'), true) ?: [];
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
                $this->WriteAttributeString('Unavailable', (string) json_encode($unavailable));
                $steps = array_merge($steps, $info);
                $this->WriteAttributeString('ExtrasError', implode(' | ', $partial));
                $this->WriteAttributeInteger('NextExtrasFetch', time() + (count($partial) > 0 ? self::EXTRAS_RETRY : self::EXTRAS_INTERVAL));
            } else {
                $next = date('H:i', $this->ReadAttributeInteger('NextExtrasFetch'));
                $cachedError = $this->ReadAttributeString('ExtrasError');
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
        $vars = [
            // Frühere HTMLBox-Variable entfernen, die Darstellung übernimmt die Kachel der Instanz
            ['Dashboard', 'Dashboard', VARIABLETYPE_STRING, '~HTMLBox', 5, false],
            ['Online', 'Online', VARIABLETYPE_BOOLEAN, self::PROFILE_ONLINE, 10, true],
            ['OperationStatus', 'Betriebsstatus', VARIABLETYPE_INTEGER, self::PROFILE_OPERATION, 20, true],
            ['Status', 'Status (Rohwert)', VARIABLETYPE_STRING, '', 21, true],
            ['Battery', 'Akku', VARIABLETYPE_INTEGER, '~Battery.100', 30, true],
            ['ChargeStatus', 'Ladestatus (Code)', VARIABLETYPE_INTEGER, '', 40, true],
            ['KnifeHeight', 'Mähhöhe (letzter Einsatz)', VARIABLETYPE_INTEGER, self::PROFILE_MM, 50, true],
            ['Speed', 'Geschwindigkeit (Code)', VARIABLETYPE_INTEGER, '', 60, true],
            ['Firmware', 'Firmware', VARIABLETYPE_STRING, '', 70, true],
            ['WifiRSSI', 'WLAN RSSI', VARIABLETYPE_INTEGER, self::PROFILE_DBM, 80, true],
            ['WifiIP', 'WLAN IP', VARIABLETYPE_STRING, '', 90, true],
            ['CellularRSSI', 'Mobilfunk RSSI', VARIABLETYPE_INTEGER, self::PROFILE_DBM, 100, true],
            ['Control', 'Steuerung', VARIABLETYPE_INTEGER, self::PROFILE_CONTROL, 110, true],
            ['Task', 'Aufgabe starten', VARIABLETYPE_INTEGER, $this->TaskProfile(), 120, true],
            ['LastWorkEnd', 'Letzter Einsatz', VARIABLETYPE_INTEGER, '~UnixTimestamp', 130, $reports],
            ['LastWorkResult', 'Letzter Einsatz – Ergebnis', VARIABLETYPE_INTEGER, self::PROFILE_WORK_RESULT, 131, $reports],
            ['LastWorkType', 'Letzter Einsatz – Art', VARIABLETYPE_INTEGER, self::PROFILE_WORK_TYPE, 132, $reports],
            ['LastWorkArea', 'Letzter Einsatz – Fläche', VARIABLETYPE_FLOAT, self::PROFILE_AREA, 133, $reports],
            ['LastWorkDuration', 'Letzter Einsatz – Dauer', VARIABLETYPE_INTEGER, self::PROFILE_MINUTES, 134, $reports],
            ['LastWorkProgress', 'Letzter Einsatz – Fortschritt', VARIABLETYPE_INTEGER, self::PROFILE_PERCENT, 135, $reports],
            ['LastWorkEnergy', 'Letzter Einsatz – Energie', VARIABLETYPE_FLOAT, self::PROFILE_WH, 136, $reports],
            ['TotalWorkCount', 'Einsätze gesamt', VARIABLETYPE_INTEGER, '', 140, $reports],
            ['TotalWorkArea', 'Gemähte Fläche gesamt', VARIABLETYPE_FLOAT, self::PROFILE_AREA, 141, $reports],
            ['TotalSaveTime', 'Zeitersparnis gesamt', VARIABLETYPE_FLOAT, self::PROFILE_HOURS, 142, $reports],
            ['TotalCarbon', 'CO₂-Einsparung gesamt', VARIABLETYPE_FLOAT, self::PROFILE_KG, 143, $reports],
            ['LastErrorText', 'Letzter Gerätefehler', VARIABLETYPE_STRING, '', 150, $reports],
            ['LastErrorTime', 'Letzter Gerätefehler – Zeitpunkt', VARIABLETYPE_INTEGER, '~UnixTimestamp', 151, $reports],
            ['ErrorCount30d', 'Gerätefehler (30 Tage)', VARIABLETYPE_INTEGER, '', 152, $reports],
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
        if (!IPS_VariableProfileExists(self::PROFILE_ONLINE)) {
            IPS_CreateVariableProfile(self::PROFILE_ONLINE, VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon(self::PROFILE_ONLINE, 'Network');
        }
        IPS_SetVariableProfileAssociation(self::PROFILE_ONLINE, 0, 'Offline', '', 0x808080);
        IPS_SetVariableProfileAssociation(self::PROFILE_ONLINE, 1, 'Online', '', 0x00AA00);
        $this->EnsureIntegerProfile(self::PROFILE_OPERATION, 'Information', '', [
            [self::OP_OFFLINE, 'Offline', 0x808080], [self::OP_READY, 'Bereit', 0x00AA00],
            [self::OP_MOWING, 'Mäht', 0x00CC66], [self::OP_PAUSED, 'Pausiert', 0xFFCC00],
            [self::OP_CHARGING, 'In der Station', 0x3399FF], [self::OP_RETURNING, 'Heimfahrt', 0x8B5CF6],
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
        $this->EnsureIntegerProfile(self::PROFILE_MINUTES, 'Clock', ' min', []);
        $this->EnsureIntegerProfile(self::PROFILE_PERCENT, 'Intensity', ' %', []);
        $this->EnsureIntegerProfile(self::PROFILE_WORK_RESULT, 'Information', '', [
            [0, 'Unbekannt', 0xAAAAAA], [1, 'Läuft', 0x00CC66], [2, 'Pausiert', 0xFFCC00],
            [3, 'Vom Nutzer gestoppt', 0xFF8800], [4, 'Unterbrochen', 0xFF0000], [5, 'Abgeschlossen', 0x00AA00]
        ]);
        $this->EnsureIntegerProfile(self::PROFILE_WORK_TYPE, 'Calendar', '', [
            [0, 'Unbekannt', -1], [1, 'Einzeleinsatz', -1], [2, 'Zeitplan', -1], [3, 'Punktmähen', -1], [4, 'Fortsetzung', -1]
        ]);
        $this->EnsureFloatProfile(self::PROFILE_AREA, 'Image', ' m²', 1);
        $this->EnsureFloatProfile(self::PROFILE_WH, 'Electricity', ' Wh', 1);
        $this->EnsureFloatProfile(self::PROFILE_HOURS, 'Clock', ' h', 1);
        $this->EnsureFloatProfile(self::PROFILE_KG, 'Leaf', ' kg', 2);
    }

    private function EnsureFloatProfile(string $name, string $icon, string $suffix, int $digits): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileIcon($name, $icon);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileDigits($name, $digits);
        }
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
    // Kachel-Visualisierung (HTML-SDK)
    // ------------------------------------------------------------------

    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/module.html');
        $state = (string) json_encode($this->BuildTileState(), JSON_UNESCAPED_UNICODE);
        return $html . '<script>handleMessage(' . json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ');</script>';
    }

    private function UpdateTile(): void
    {
        if (!$this->ReadPropertyBoolean('EnableTile')) {
            return;
        }
        $this->UpdateVisualizationValue((string) json_encode($this->BuildTileState(), JSON_UNESCAPED_UNICODE));
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
            'version'     => self::MODULE_VERSION
        ];
    }
}
