<?php

// SPDX-License-Identifier: MIT
// Copyright (c) 2026 Armin Frohwerk

declare(strict_types=1);

/**
 * Mammotion Konfigurator
 *
 * Listet alle Mäher des über die Cloud-Instanz angemeldeten Mammotion-Kontos
 * und legt die zugehörigen Mäher-Instanzen per Klick an.
 */
class MammotionConfigurator extends IPSModuleStrict
{
    private const CLOUD_MODULE = '{D26140D0-FC03-43F8-AAB0-1E4220D959EB}';
    private const MOWER_MODULE = '{8297B983-0C40-4D50-8376-636028226AEE}';
    private const DATA_TX = '{5F140107-E29A-41AA-9314-01891DDE02F9}';

    public function Create(): void
    {
        parent::Create();
        // IPSModuleStrict: Die Verbindung zur Cloud-Instanz übernimmt die Verwaltungskonsole (siehe GetCompatibleParents)
    }

    /**
     * IPSModuleStrict: Der Konfigurator nutzt eine vorhandene oder neue Mammotion-Cloud-Instanz.
     */
    public function GetCompatibleParents(): string
    {
        return (string) json_encode(['type' => 'connect', 'moduleIDs' => [self::CLOUD_MODULE]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
    }

    public function ReceiveData(string $JSONString): string
    {
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);

        $mowers = [];
        $error = '';
        if (!$this->HasActiveParent()) {
            $error = 'Die Cloud-Instanz ist nicht verbunden oder nicht aktiv. Bitte zuerst Client-ID und Client-Secret in "Mammotion Cloud" eintragen.';
        } else {
            try {
                $mowers = $this->RequestMowers();
            } catch (Throwable $e) {
                $error = 'Mäher konnten nicht abgerufen werden: ' . $e->getMessage();
            }
        }

        // Bereits vorhandene Mäher-Instanzen an derselben Cloud-Instanz
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::MOWER_MODULE) as $id) {
            if ($parentID > 0 && IPS_GetInstance($id)['ConnectionID'] !== $parentID) {
                continue;
            }
            $existing[$id] = (string) IPS_GetProperty($id, 'DeviceID');
        }

        $values = [];
        $multiple = count($mowers) > 1;
        foreach ($mowers as $mower) {
            $deviceID = (string) ($mower['id'] ?? '');
            if ($deviceID === '') {
                continue;
            }
            $nickname = trim((string) ($mower['nickname'] ?? ''));
            $apiName = trim((string) ($mower['name'] ?? ''));
            $title = $nickname !== '' ? $nickname : ($apiName !== '' ? $apiName : 'Mammotion ' . $deviceID);

            $instanceID = array_search($deviceID, $existing, true);
            if ($instanceID !== false) {
                unset($existing[$instanceID]);
            }

            $values[] = [
                'DeviceID'   => $deviceID,
                'Name'       => $title,
                'Model'      => (string) ($mower['model'] ?? ''),
                'Online'     => ((int) ($mower['online'] ?? 0)) === 1 ? 'Ja' : 'Nein',
                'instanceID' => $instanceID !== false ? (int) $instanceID : 0,
                'create'     => [
                    'moduleID'      => self::MOWER_MODULE,
                    'configuration' => ['DeviceID' => $deviceID],
                    'name'          => $multiple ? 'Mähroboter ' . $title : 'Mähroboter'
                ]
            ];
        }

        // Instanzen, deren Mäher im Konto nicht (mehr) gefunden wurde
        foreach ($existing as $id => $deviceID) {
            $values[] = [
                'DeviceID'   => $deviceID !== '' ? $deviceID : '(automatisch)',
                'Name'       => IPS_GetName($id),
                'Model'      => '',
                'Online'     => '',
                'instanceID' => $id
            ];
        }

        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'Mowers') {
                $action['values'] = $values;
            }
        }
        unset($action);

        if ($error !== '') {
            array_unshift($form['actions'], ['type' => 'Label', 'caption' => $error, 'bold' => true]);
        }

        return (string) json_encode($form, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function RequestMowers(): array
    {
        $raw = $this->SendDataToParent((string) json_encode(['DataID' => self::DATA_TX, 'Command' => 'GetMowers']));
        $result = json_decode($raw, true);
        if (!is_array($result)) {
            throw new RuntimeException('Keine gültige Antwort der Cloud-Instanz');
        }
        if (!($result['ok'] ?? false)) {
            throw new RuntimeException((string) ($result['error'] ?? 'Unbekannter Fehler'));
        }
        $data = $result['response']['data'] ?? [];
        return is_array($data) ? $data : [];
    }
}
