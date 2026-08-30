<?php

// ===========================================================================
// OCPPHub Konfigurator — zeigt Charge-Point-Identities, die sich bereits per
// OCPP beim Splitter gemeldet haben, aber noch keine Ladepunkt-Instanz
// haben. Klick auf „Erstellen" legt eine OCPPHub-Ladepunkt-Instanz mit
// vorausgefüllter CPID an.
// FIX 30.08.2026 (Live-Fund: Konfigurator blieb leer): ursprünglich verlassen
// auf IPS_GetParent() für die Splitter-Zuordnung (setzt voraus, dass die
// automatische Parent-Verbindung beim Anlegen tatsächlich gegriffen hat —
// war von Anfang an als UNGETESTETE Annahme markiert und hat sich als
// nicht zuverlässig herausgestellt). Jetzt zusätzlich explizites
// Auswahlfeld „SplitterID", das Vorrang vor IPS_GetParent() hat.
// STUFE 1 / TEILWEISE GETESTET, siehe OCPPHubSplitter-Header.
// ===========================================================================

class OCPPHubKonfigurator extends IPSModule
{
    private const LADEPUNKT_GUID = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.1.8';
    private const SPLITTER_GUID = '{81D3E328-9E12-43A9-825A-F7888530868C}';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    // „Was ist neu"-Banner (Verbund-Konvention, siehe SUITE.md, Referenz
    // ChargerHub) — bei jedem nutzerrelevanten Änderungs-Bump aktualisieren,
    // NICHT bei jedem library.json-Build (sonst nervt es).
    private const NEWS_VERSION = '0.1.7';
    private const NEWS_ITEMS = [
        'Splitter-Zuordnung jetzt auch manuell wählbar (Auswahlfeld oben), falls die automatische Erkennung über die Instanz-Verschachtelung nicht greift.',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SplitterID', 0);
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeString('SeenNews', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    private function resolveSplitterId(): int
    {
        $explicit = $this->ReadPropertyInteger('SplitterID');
        if ($explicit > 0) {
            return $explicit;
        }
        return (int)(@IPS_GetParent($this->InstanceID) ?: 0);
    }

    public function GetConfigurationForm()
    {
        $splitterId = $this->resolveSplitterId();

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

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖 Dokumentation & Hilfe (Version ' . self::VERSION . ')',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Zeigt Wallboxen, die sich bereits mit ihrer Charge-Point-Identity beim OCPPHub-Splitter gemeldet haben, aber noch keine eigene Instanz haben.'],
                        ['type' => 'Label', 'caption' => 'Wallbox zuerst in ihrer eigenen OCPP-Konfiguration auf den Splitter-Endpunkt einstellen, dann hier „Erstellen" klicken.'],
                        ['type' => 'Label', 'caption' => 'Falls unten kein Splitter automatisch erkannt wird: rechts die passende OCPPHub-Splitter-Instanz manuell auswählen.'],
                    ],
                ],
                [
                    'type'     => 'SelectInstance',
                    'name'     => 'SplitterID',
                    'caption'  => 'OCPPHub-Splitter (nur nötig, falls nicht automatisch erkannt)',
                    'moduleID' => self::SPLITTER_GUID,
                ],
                $splitterId > 0
                    ? ['type' => 'Label', 'caption' => 'Verbunden mit Splitter-Instanz #' . $splitterId]
                    : ['type' => 'Label', 'caption' => '⚠️ Kein OCPPHub-Splitter gefunden — oben manuell auswählen.'],
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
        ];

        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type'  => 'RowLayout',
                'name'  => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub ist früher Beta-Stand — Rückmeldungen willkommen über github.com/DG65/NRGOCPPHub.'],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'OHUBK_DismissReviewHint($id);'],
                ],
            ];
        }

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        return json_encode($form);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'OHUBK_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }
}
