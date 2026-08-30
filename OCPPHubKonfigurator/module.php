<?php

// ===========================================================================
// OCPPHub Konfigurator — Kind der OCPPHub-Splitter-Instanz (wie
// OCPPHub Ladepunkt), zeigt Charge-Point-Identities, die sich bereits per
// OCPP beim Splitter gemeldet haben, aber noch keine Ladepunkt-Instanz
// haben. Klick auf „Erstellen" legt eine OCPPHub-Ladepunkt-Instanz mit
// vorausgefüllter CPID an — die Symcon-Konfigurator-UI verbindet die neue
// Instanz automatisch mit demselben Splitter-Parent wie diesen Konfigurator
// (setzt voraus, dass beide Module dasselbe Interface in
// parentRequirements/implemented deklarieren, siehe module.json).
// STUFE 1 / UNGETESTET, siehe OCPPHubSplitter-Header.
// ===========================================================================

class OCPPHubKonfigurator extends IPSModule
{
    private const LADEPUNKT_GUID = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';

    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $splitterId = @IPS_GetParent($this->InstanceID);

        $existing = [];
        if ($splitterId > 0) {
            foreach (@IPS_GetChildrenIDs($splitterId) ?: [] as $childId) {
                $instance = @IPS_GetInstance($childId);
                if ($instance && $instance['ModuleInfo']['ModuleID'] === self::LADEPUNKT_GUID) {
                    $existing[@IPS_GetProperty($childId, 'CPID')] = $childId;
                }
            }
        }

        $values = [];
        if ($splitterId > 0 && function_exists('OHUB_GetSeenChargePoints')) {
            foreach (OHUB_GetSeenChargePoints($splitterId) as $cpid => $lastSeen) {
                $values[] = [
                    'name'       => $cpid,
                    'cpid'       => $cpid,
                    'lastSeen'   => date('d.m.Y H:i:s', $lastSeen),
                    'instanceID' => $existing[$cpid] ?? 0,
                    'create'     => [
                        'moduleID'      => self::LADEPUNKT_GUID,
                        'name'          => $cpid,
                        'configuration' => ['CPID' => $cpid],
                    ],
                ];
            }
        }

        return json_encode([
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '📖 Dokumentation & Hilfe',
                    'items'   => [
                        ['type' => 'Label', 'caption' => 'Zeigt Wallboxen, die sich bereits mit ihrer Charge-Point-Identity beim OCPPHub-Splitter gemeldet haben, aber noch keine eigene Instanz haben.'],
                        ['type' => 'Label', 'caption' => 'Wallbox zuerst in ihrer eigenen OCPP-Konfiguration auf den Splitter-Endpunkt einstellen, dann hier „Erstellen" klicken.'],
                    ],
                ],
                $splitterId > 0
                    ? ['type' => 'Label', 'caption' => 'Verbunden mit Splitter-Instanz #' . $splitterId]
                    : ['type' => 'Label', 'caption' => '⚠️ Kein übergeordneter OCPPHub-Splitter verbunden.'],
                [
                    'type'     => 'Configurator',
                    'name'     => 'ChargePointList',
                    'caption'  => 'Gesehene Wallboxen',
                    'rowCount' => 10,
                    'delete'   => false,
                    'sort'     => ['column' => 'lastSeen', 'direction' => 'descending'],
                    'columns'  => [
                        ['caption' => 'Charge-Point-Identity', 'name' => 'cpid', 'width' => '300px'],
                        ['caption' => 'Zuletzt gesehen', 'name' => 'lastSeen', 'width' => '200px'],
                    ],
                    'values' => $values,
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ],
        ]);
    }
}
