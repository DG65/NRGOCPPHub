<?php

// ===========================================================================
// OCPPHub Abrechnung — Kundenverwaltung für Stufe 2 (Betriebsart ② „Mehrere
// Nutzer"): Kunde → Zugänge (Karten/idTags) → optional Fahrzeug → optional
// Gruppe, Verbrauchslimits. Wird vom Splitter automatisch angelegt (siehe
// .docs/architektur.md „Instanzmodell" — „obligatorischer" Bestandteil,
// unabhängig davon, ob Betriebsart ① oder ② aktiv ist).
//
// Datenmodell als vier JSON-Listen in Properties (Symcon-„List"-Formular-
// element), IDs werden intern vergeben (Formularfeld selbst zeigt keine
// ID-Eingabe). KEIN Tarif/keine Kosten — das ist Stufe 3.
//
// Aktueller Stand (14.09.2026): live verifiziert an Dietmars echter
// Kundenverwaltung (Kunde/Zugang/Fahrzeug/Gruppe anlegen, Karte anlernen
// per Kachel und Konsole, Verbrauchslimits). Tarife/Kosten bleiben Stufe 3.
// ===========================================================================

require_once __DIR__ . '/../libs/OHUB_Text.php';

class OCPPHubAbrechnung extends IPSModule
{
    private const VERSION = '0.5.2';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    // „Über dieses Modul" (14.09.2026, SUITE.md Formular-Konvention Punkt
    // 5) — siehe OCPPHubSplitter für den LICENSE-Branch-Stolperstein.
    private const LICENSE_URL = 'https://github.com/DG65/NRGOCPPHub/blob/main/LICENSE';
    // Symcon-Forum-Vorstellungs-Thread (16.09.2026, von Dietmar selbst
    // gepostet, siehe forum-ankuendigung-ocpphub.md).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-modul-nrg-stack-ocpphub-wallboxen-per-ocpp-1-6j-anbinden-mit-pv-ueberschussladen-und-kundenverwaltung/144409';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const NEWS_VERSION = '0.5.0';
    private const TESSIE_VEHICLE_GUID = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    private const SPLITTER_GUID = '{81D3E328-9E12-43A9-825A-F7888530868C}';
    // Datenfluss (Symcon-Review 30.10.2026), siehe .docs/datenfluss.md
    private const DATA_ZUM_SPLITTER = '{6ED0496A-EDD0-4600-9549-04D18B8387F5}';
    private const NEWS_ITEMS = [
        '🌐 New: German and English. The form, the configuration tile and the messages follow the Symcon language setting.',
        '🔌 Technical: Billing is now a device on the splitter and receives its requests (card check, consumption) through the Symcon data flow instead of direct function calls, as the store review requires. The splitter connects an existing Billing instance by itself the first time you apply changes after the update. Behavior does not change.',
        '🪟 New: “Form layout” (collapsed below the four tabs) — you decide in which order vehicles, groups, customers and access entries appear and which of them to hide. Hidden tabs keep working and their entries are preserved. There is no expand/collapse setting here: the tabs form an accordion, and the module remembers the tab you opened last. Takes effect after “Apply changes” the next time you open the form.',
        'New: “👋 What is this module for?” and “🧡 About this module” (license/donations) added; the feedback notice is now a panel of its own that you can hide instead of a line of text (store convention addition, consistent with the other three OCPPHub modules).',
        'Store checklist 9b: dates such as “Valid until” on access entries are now shown in the format of the display language (DD.MM.YYYY in German, YYYY-MM-DD in English), in the console and in the tile. Old entries already saved stay valid — no migration step needed, PHP recognizes both formats reliably.',
        'New: 🎪 Demo mode — takes effect automatically as soon as it is enabled on the associated splitter (Dietmar’s planned public demo of the whole stack). The configuration tile then becomes read-only (save/add row/delete/adopt card disabled, clearly visible notice at the top) — a visitor to the demo cannot create or change real customer data.',
        'The instance can now be moved freely in the object tree (e.g. for a tidier WebFront arrangement) without losing its connection to its splitter — the “not connected” warning now checks the actual assignment instead of the tree position.',
        'The configuration tile now warns when it belongs to an instance that no splitter uses as its Billing instance — before, vehicles/groups/customers/access entries silently stayed empty in that case even though data had already been maintained elsewhere.',
        'New: configuration tile — the same customer management (vehicles/groups/customers/access entries, teach-in of cards) is now also available as a WebFront tile of this instance. It can be assigned to a separate, secured WebFront without granting console access.',
        'Card teach-in: an unknown card (idTag) is now shown at the top of the form as soon as it has been presented at a wallbox — one click on “Adopt as new access entry” adds it as a draft to the access list, no more copying it from the system log.',
        'Added a warning in case this instance is NOT created as a direct child of an OCPPHub splitter — the splitter then does not use it and it has no function (duplicate/wrongly created).',
        'Vehicles can now be linked directly to a Tessie vehicle already known in the NRG-Stack — the name is taken over live, no need to maintain it twice.',
        'Vehicles, groups, customers and access entries appear in the form as four tabs of equal width that together fill the full form width. Accordion: the open tab takes the full width itself, moves all the way to the right, and the others collapse automatically.',
    ];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Quelltexte sind englisch, Deutsch steht in locale.json; je Text fragt Symcon nur einmal je Prozess.
        OHUB_Text::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        // „Wozu dieses Modul?" (14.09.2026, EMS-Fund über Dietmar — Store-
        // Konventions-Prüfung, Formular-Konvention Punkt 0, Referenz
        // MeterHub): ganz oben VOR dem News-Panel, aufgeklappt, einmalig
        // dismissible.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeString('ActiveAccordionPanel', '');

        // Vier Listen, IDs intern vergeben (siehe normalizeIds() in
        // ApplyChanges()). Leere JSON-Arrays als Default.
        $this->RegisterPropertyString('Fahrzeuge', '[]');
        $this->RegisterPropertyString('Gruppen', '[]');
        $this->RegisterPropertyString('Kunden', '[]');
        $this->RegisterPropertyString('Zugaenge', '[]');
        // SUITE.md 3a „Formular: Aufbau“: Liste {Key, Panel, Hidden, Pos} (Accordion: keine Auf-/Zuklapp-Wahl)
        $this->RegisterPropertyString('FormLayout', '[]');

        $this->RegisterAttributeInteger('NextEntityId', 1);

        // Verbrauchshistorie je Kunde/Periode — Attribut, KEINE Property
        // (Laufzeitdaten, kein Formularfeld). Struktur:
        // { "<customerId>": { "<periodKey>": <kWh> } }
        $this->RegisterAttributeString('ConsumptionLog', '{}');

        // Karte-anlernen: letzte unbekannte idTag merken, damit sie per Klick
        // als Zugang-Entwurf übernommen werden kann statt sie aus dem
        // Systemlog abzutippen.
        $this->RegisterAttributeString('LastUnknownIdTag', '');
        $this->RegisterAttributeInteger('LastUnknownIdTagAt', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // IDs für neu angelegte Listenzeilen vergeben (Formular selbst
        // zeigt kein ID-Feld — neue Zeilen kommen mit id=0 rein). Bei Bedarf
        // die Property neu speichern und EINMAL rekursiv erneut anwenden —
        // WICHTIG (Lehre aus dem Splitter-Basic-Auth-Fix, 30.08.2026): Status
        // etc. müssen bereits VOR dieser evtl. rekursiven Runde laufen, s. u.
        $changed = false;
        $changed |= $this->assignIds('Fahrzeuge');
        $changed |= $this->assignIds('Gruppen');
        $changed |= $this->assignIds('Kunden');
        $changed |= $this->assignIds('Zugaenge');

        $this->SetStatus(102);

        // Konfigurationskachel (31.08.2026, Dietmars Wunsch: "eine oder
        // mehrere Konfigurationskacheln ... die gesicherten WebFronts
        // zugeordnet werden können, mindestens das gleiche wie in der
        // Konsole möglich"). Eigene HTML-Kachel (SetVisualizationType(1) +
        // module.html) statt des Konsole-only-Formulars — die Zugriffs-
        // steuerung übernimmt Symcon selbst über die Sichtbarkeit dieser
        // Instanz je WebFront-Instanz (Standardmechanismus, kein Custom-
        // Auth-Code nötig). Muster 1:1 von NRGDashboardTile übernommen
        // (dortige RegisterHook()/MessageSink()/ProcessHookData()-Struktur,
        // dort bereits verbundweit bewährt).
        $this->SetVisualizationType(1);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook('/hook/ohubadmin' . $this->InstanceID);
        } else {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        }

        if ($changed) {
            @IPS_ApplyChanges($this->InstanceID);
        }
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message === IPS_KERNELMESSAGE && isset($data[0]) && $data[0] === KR_READY) {
            $this->ApplyChanges();
        }
    }

    // Standard-WebHook-Registrierungsmuster (1:1 aus NRGDashboardTile
    // übernommen, generischer Symcon-Mechanismus, keine modul-eigene Logik).
    private function RegisterHook(string $WebHook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $index => $hook) {
            if ($hook['Hook'] === $WebHook) {
                if ((int)$hook['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    // Texte der Kachel (module.html): englische Schluessel, Deutsch kommt aus locale.json. Der Pruefstand
    // (.tools/test-sprache.php) vergleicht diese Liste mit dem, was die Kachel tatsaechlich benutzt.
    private const TILE_TEXTS = [
        'Vehicles',
        'Tessie vehicle',
        '— no vehicle —',
        'Display name',
        'License plate',
        'Groups',
        'Name',
        'Limit/week (kWh)',
        'Limit/month (kWh)',
        'Limit/year (kWh)',
        'Customers',
        'Active',
        'Group',
        '— no group —',
        'Access (cards)',
        'idTag',
        'Customer',
        '— no customer —',
        'Vehicle',
        'Valid until',
        'YYYY-MM-DD',
        'From',
        'HH:MM',
        'To',
        '➕ Row',
        '💾 Save',
        'No entries yet — click “➕ Row”.',
        'Saving …',
        'Saved.',
        'Error while saving — please try again.',
        'en-GB',
        '⚠️ No splitter uses this instance as its Billing instance. Every OCPPHub splitter uses exactly ONE Billing instance, stored with it — this one is not it (regardless of where it sits in the object tree) and is therefore queried by NO splitter. All areas below are therefore empty, even if data has been maintained elsewhere. Please set the tile up on the instance the splitter created itself (name ends in “… Billing”) and delete this one if necessary.',
        '🎪 Demo mode is active — display only. Changes are not saved here.',
        '🆕 Unknown card detected:',
        'Adopt as new access entry',
    ];

    /** Kachel-HTML mit den uebersetzten Texten (window.OHUB_TEXTS) und den aktuellen Daten. */
    private function tileHtml(): string
    {
        $texte = [];
        foreach (self::TILE_TEXTS as $schluessel) {
            $texte[$schluessel] = OHUB_Text::t($schluessel);
        }
        $html = (string)file_get_contents(__DIR__ . '/module.html');
        $html = str_replace('<!--OHUB_TEXTS-->', '<script>window.OHUB_TEXTS = ' . json_encode($texte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>', $html);
        return $html . '<script>handleMessage(' . json_encode($this->buildTilePayload()) . ');</script>';
    }

    public function GetVisualizationTile()
    {
        return $this->tileHtml();
    }

    // Bedient sowohl die eingebettete WebFront-Kachel (fetch()-Aufrufe aus
    // module.html) als auch eine eigenständige Seite (IPSView/Browser-Popup,
    // Muster Dashboard/Prognose). Schreibzugriffe (?area=…) persistieren
    // SOFORT per IPS_SetProperty()+IPS_ApplyChanges() — anders als im
    // Konsolenformular gibt es hier keinen umschließenden "Übernehmen"-
    // Dialog, der die Selbstpersistenz-Regel für Formular-Buttons auslösen
    // würde (siehe AdoptLastUnknownIdTag() dort).
    public function ProcessHookData()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'adoptUnknown') {
            header('Content-Type: application/json; charset=utf-8');
            if ($this->isDemoMode()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => OHUB_Text::t('Demo mode is active — no changes are possible.')]);
                return;
            }
            $this->adoptUnknownIdTagDirect();
            echo json_encode($this->buildTilePayload());
            return;
        }
        if (isset($_GET['area'])) {
            header('Content-Type: application/json; charset=utf-8');
            if ($this->isDemoMode()) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => OHUB_Text::t('Demo mode is active — no changes are possible.')]);
                return;
            }
            $area = (string)$_GET['area'];
            if (!array_key_exists($area, self::AREA_SCHEMA)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => OHUB_Text::t('Unknown area.')]);
                return;
            }
            $rows = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($rows)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => OHUB_Text::t('Invalid data.')]);
                return;
            }
            IPS_SetProperty($this->InstanceID, $area, json_encode($this->sanitizeRows($area, $rows)));
            IPS_ApplyChanges($this->InstanceID);
            echo json_encode($this->buildTilePayload());
            return;
        }
        if (isset($_GET['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($this->buildTilePayload());
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->tileHtml();
    }

    // Whitelist der per Kachel schreibbaren Bereiche + Feldtypen — verhindert
    // sowohl beliebige Property-Namen über ?area= (Sicherheit) als auch
    // Datenmüll durch ungeprüfte JS-Werte (der Konsole nimmt Symcons
    // Formular-Typprüfung dieselbe Arbeit ab, hier machen wir es selbst).
    private const AREA_SCHEMA = [
        'Fahrzeuge' => ['tessieInstanceId' => 'int', 'name' => 'string', 'kennzeichen' => 'string'],
        'Gruppen'   => ['name' => 'string', 'maxKwhWeek' => 'float', 'maxKwhMonth' => 'float', 'maxKwhYear' => 'float'],
        'Kunden'    => ['name' => 'string', 'active' => 'bool', 'groupId' => 'int', 'maxKwhWeek' => 'float', 'maxKwhMonth' => 'float', 'maxKwhYear' => 'float'],
        'Zugaenge'  => ['idTag' => 'string', 'name' => 'string', 'customerId' => 'int', 'vehicleId' => 'int', 'active' => 'bool', 'validUntil' => 'string', 'allowedFrom' => 'string', 'allowedTo' => 'string'],
    ];

    private function sanitizeRows(string $area, array $rows): array
    {
        $schema = self::AREA_SCHEMA[$area];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = ['id' => (int)($row['id'] ?? 0)];
            foreach ($schema as $field => $type) {
                $value = $row[$field] ?? null;
                switch ($type) {
                    case 'int':
                        $clean[$field] = (int)$value;
                        break;
                    case 'float':
                        $clean[$field] = (float)$value;
                        break;
                    case 'bool':
                        $clean[$field] = (bool)$value;
                        break;
                    default:
                        $clean[$field] = (string)$value;
                }
            }
            $out[] = $clean;
        }
        return $out;
    }

    private function buildTilePayload(): array
    {
        return [
            'version'         => self::VERSION,
            'hookPath'        => '/hook/ohubadmin' . $this->InstanceID,
            // Live-Fund 01.09.2026: Dietmar hatte eine zweite, manuell
            // angelegte Abrechnung-Instanz (von keinem Splitter geführt) zum
            // Kachel-Testen benutzt — die zeigte kommentarlos leere Daten,
            // ohne jeden Hinweis warum. Die Konsole warnt davor bereits
            // (isRegisteredWithSplitter()), die Kachel bislang NICHT — genau
            // die Art von stillem Fehlschlag, die dieses ganze Diagnose-
            // Feature eigentlich verhindern sollte. Jetzt auch hier.
            'connected'       => $this->isRegisteredWithSplitter(),
            'demoMode'        => $this->isDemoMode(),
            'Fahrzeuge'       => $this->getFahrzeuge(),
            'Gruppen'         => $this->getGruppen(),
            'Kunden'          => $this->getKunden(),
            'Zugaenge'        => $this->getZugaenge(),
            'tessieOptions'   => $this->buildTessieOptions(),
            'gruppenOptions'  => $this->buildIdLabelOptions($this->getGruppen()),
            'kundenOptions'   => $this->buildIdLabelOptions($this->getKunden()),
            'fahrzeugOptions' => $this->buildFahrzeugOptions(),
            'unknownIdTag'    => $this->ReadAttributeString('LastUnknownIdTag'),
            'unknownIdTagAt'  => $this->ReadAttributeInteger('LastUnknownIdTagAt'),
        ];
    }

    private function buildIdLabelOptions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int)($row['id'] ?? 0), 'label' => (string)($row['name'] ?? '')];
        }
        return $out;
    }

    private function buildFahrzeugOptions(): array
    {
        $out = [];
        foreach ($this->getFahrzeuge() as $row) {
            $out[] = ['id' => (int)($row['id'] ?? 0), 'label' => $this->resolveFahrzeugName($row)];
        }
        return $out;
    }

    private function buildTessieOptions(): array
    {
        $out = [];
        foreach ((array)(@IPS_GetInstanceListByModuleID(self::TESSIE_VEHICLE_GUID) ?: []) as $id) {
            $out[] = ['id' => (int)$id, 'label' => IPS_GetName((int)$id)];
        }
        return $out;
    }

    // Direktes Übernehmen (Kachel/WebHook, kein Konsolenformular-Kontext) —
    // im Unterschied zu AdoptLastUnknownIdTag() (Konsole) hier bewusst
    // SOFORTIGE Persistenz, s. Docblock ProcessHookData().
    private function adoptUnknownIdTagDirect(): void
    {
        $tag = $this->ReadAttributeString('LastUnknownIdTag');
        if ($tag === '') {
            return;
        }
        $rows = $this->getZugaenge();
        $rows[] = [
            'idTag'       => $tag,
            'name'        => '',
            'customerId'  => 0,
            'vehicleId'   => 0,
            'active'      => true,
            'validUntil'  => '',
            'allowedFrom' => '',
            'allowedTo'   => '',
            'id'          => 0,
        ];
        IPS_SetProperty($this->InstanceID, 'Zugaenge', json_encode(array_values($rows)));
        IPS_ApplyChanges($this->InstanceID);

        $this->WriteAttributeString('LastUnknownIdTag', '');
        $this->WriteAttributeInteger('LastUnknownIdTagAt', 0);
    }

    private function assignIds(string $propertyName): bool
    {
        $rows = json_decode($this->ReadPropertyString($propertyName), true);
        if (!is_array($rows)) {
            return false;
        }
        $changed = false;
        foreach ($rows as $index => $row) {
            if ((int)($row['id'] ?? 0) <= 0) {
                $nextId = $this->ReadAttributeInteger('NextEntityId');
                $rows[$index]['id'] = $nextId;
                $this->WriteAttributeInteger('NextEntityId', $nextId + 1);
                $changed = true;
            }
        }
        if ($changed) {
            @IPS_SetProperty($this->InstanceID, $propertyName, json_encode(array_values($rows)));
        }
        return $changed;
    }

    public function GetConfigurationForm()
    {
        $fahrzeuge = $this->getFahrzeuge();
        $gruppen = $this->getGruppen();
        $kunden = $this->getKunden();
        $activePanel = $this->ReadAttributeString('ActiveAccordionPanel');

        $fahrzeugOptions = [['caption' => '— no vehicle —', 'value' => 0]];
        foreach ($fahrzeuge as $f) {
            $fahrzeugName = $this->resolveFahrzeugName($f);
            $fahrzeugOptions[] = ['caption' => $fahrzeugName . ($f['kennzeichen'] !== '' ? ' (' . $f['kennzeichen'] . ')' : ''), 'value' => (int)$f['id']];
        }
        $gruppenOptions = [['caption' => '— no group —', 'value' => 0]];
        foreach ($gruppen as $g) {
            $gruppenOptions[] = ['caption' => $g['name'], 'value' => (int)$g['id']];
        }
        $kundenOptions = [['caption' => '— no customer —', 'value' => 0]];
        foreach ($kunden as $k) {
            $kundenOptions[] = ['caption' => $k['name'], 'value' => (int)$k['id']];
        }

        // Panels je Name gebaut, damit der aktive Reiter unten (Reihenfolge) ans Ende
        // (= ganz rechts) verschoben werden kann, statt seine feste Position zu behalten.
        $panelDefs = [
            'Fahrzeuge' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelFahrzeuge',
                'caption'  => '🚙 Vehicles',
                'expanded' => $activePanel === 'Fahrzeuge',
                'width'    => $this->panelWidth('Fahrzeuge', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Fahrzeuge\');',
                'items'    => [
                    ['type' => 'Label', 'width' => '540px', 'caption' => 'Select “Tessie vehicle” if the car is already known in the stack (mirrors its name there automatically — no typing it twice, always up to date). Without Tessie, or for a car not recorded through Tessie: enter the display name/license plate by hand and leave “Tessie vehicle” on “no vehicle”.'],
                    [
                        'type'     => 'List',
                        'name'     => 'Fahrzeuge',
                        'caption'  => 'Vehicles',
                        'rowCount' => 5,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'Tessie vehicle', 'name' => 'tessieInstanceId', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectInstance', 'moduleID' => self::TESSIE_VEHICLE_GUID]],
                            ['caption' => 'Display name (if no Tessie vehicle)', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'License plate', 'name' => 'kennzeichen', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ],
                        'values' => $fahrzeuge,
                    ],
                ],
            ],
            'Gruppen' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelGruppen',
                'caption'  => '👥 Groups',
                'expanded' => $activePanel === 'Gruppen',
                'width'    => $this->panelWidth('Gruppen', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Gruppen\');',
                'items'    => [
                    ['type' => 'Label', 'width' => '540px', 'caption' => 'Purely for bundling consumption limits (e.g. “Family” with a shared monthly limit) — no charging behavior of its own.'],
                    [
                        'type'     => 'List',
                        'name'     => 'Gruppen',
                        'caption'  => 'Groups',
                        'rowCount' => 5,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Week', 'name' => 'maxKwhWeek', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Month', 'name' => 'maxKwhMonth', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Year', 'name' => 'maxKwhYear', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ],
                        'values' => $gruppen,
                    ],
                ],
            ],
            'Kunden' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelKunden',
                'caption'  => '🙋 Customers',
                'expanded' => $activePanel === 'Kunden',
                'width'    => $this->panelWidth('Kunden', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Kunden\');',
                'items'    => [
                    [
                        'type'     => 'List',
                        'name'     => 'Kunden',
                        'caption'  => 'Customers',
                        'rowCount' => 6,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Active', 'name' => 'active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Group', 'name' => 'groupId', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $gruppenOptions]],
                            ['caption' => 'Limit/week (kWh, 0=off)', 'name' => 'maxKwhWeek', 'width' => '150px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Limit/month (kWh, 0=off)', 'name' => 'maxKwhMonth', 'width' => '150px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Limit/year (kWh, 0=off)', 'name' => 'maxKwhYear', 'width' => '150px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ],
                        'values' => $kunden,
                    ],
                ],
            ],
            'Zugaenge' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelZugaenge',
                'caption'  => '🪪 Access (cards)',
                'expanded' => $activePanel === 'Zugaenge',
                'width'    => $this->panelWidth('Zugaenge', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Zugaenge\');',
                'items'    => [
                    [
                        'type'     => 'List',
                        'name'     => 'Zugaenge',
                        'caption'  => 'Access',
                        'rowCount' => 8,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'idTag (card)', 'name' => 'idTag', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Display name', 'name' => 'name', 'width' => '140px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Customer', 'name' => 'customerId', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $kundenOptions]],
                            ['caption' => 'Vehicle', 'name' => 'vehicleId', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $fahrzeugOptions]],
                            ['caption' => 'Active', 'name' => 'active', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Valid until (YYYY-MM-DD, empty=unlimited)', 'name' => 'validUntil', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Allowed from (HH:MM, empty=always)', 'name' => 'allowedFrom', 'width' => '120px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Allowed until (HH:MM, empty=always)', 'name' => 'allowedTo', 'width' => '120px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ],
                        'values' => $this->getZugaenge(),
                    ],
                ],
            ],
        ];

        // Aktiver Reiter wandert ans Ende der Reihenfolge (= ganz rechts), die übrigen
        // drei behalten ihre relative Reihenfolge zueinander bei.
        // SUITE.md 3a „Formular: Aufbau“: Position und Ausblenden der vier Reiter. Auf-/Zuklappen
        // gibt es hier nicht, weil die Ziehharmonika sich den aktiven Reiter selbst merkt.
        $layout = $this->formLayout();
        $panelOrder = array_keys($layout);
        if ($activePanel !== '' && in_array($activePanel, $panelOrder, true) && $layout[$activePanel]['hidden']) {
            $activePanel = '';
        }
        if ($activePanel !== '' && in_array($activePanel, $panelOrder, true)) {
            $panelOrder = array_values(array_diff($panelOrder, [$activePanel]));
            $panelOrder[] = $activePanel;
        }
        foreach ($layout as $key => $l) {
            if ($l['hidden']) {
                // Nicht weglassen: die Listen bleiben im Formular, gespeicherte Werte bleiben erhalten.
                $panelDefs[$key]['visible'] = false;
                $panelDefs[$key]['expanded'] = false;
            }
        }

        $form = [
            'elements' => [
                $this->unknownCardHint(),
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => OHUB_Text::t('📖 Documentation & help (version %s)', self::VERSION),
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'What this instance does: customer management for operating mode ② “Multiple users” (see the splitter form). This instance always exists (it is created automatically by the splitter) but ONLY has an effect while “Multiple users” is selected on the splitter — with “Single user” every card is accepted and the data here stays unused.'],
                        ['type' => 'Label', 'caption' => '📋 Order of creation: first vehicles and groups (if wanted), then customers (can be assigned to a group), finally access entries/cards (each assigned to exactly one customer, optionally to a vehicle). A customer can have several access entries — e.g. main card + spare card.'],
                        ['type' => 'Label', 'caption' => '🔑 Access entry = a single RFID card (idTag). The idTag must match exactly the value the wallbox reports to OCPPHub when the card is presented (visible as “Authorize” in the debug output of the splitter instance). Any number of access entries is possible — no upper limit like a wallbox’s internal card list.'],
                        ['type' => 'Label', 'caption' => '⏱️ Consumption limits (week/month/year, 0 = no limit) apply per customer AND per group independently — the stricter limit applies first. A charge already in progress is NOT stopped when the limit is reached, only the next card presentation is rejected. The counting period is the calendar week/month/year, not rolling.'],
                        ['type' => 'Label', 'caption' => '🕐 Time window on the access entry (time from/to, format HH:MM, empty = no restriction) — e.g. a guest card that may only charge during the day.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ NOT included yet (stage 3): tariffs/cost calculation, reports/CSV export, reservation fee. The consumption data (kWh per transaction) is already recorded now, so that enabling it later does not have to build on missing raw data.'],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🧩 Configuration tile: the same management (vehicles/groups/customers/access entries, card teach-in) is also available as a WebFront tile of this instance — useful for assigning it to a secured WebFront without granting console access. Prerequisite: a WebHook Control instance must exist in the object tree (Symcon usually creates it automatically); the tile registers there under “/hook/ohubadmin%d”. Access protection runs entirely through Symcon’s own WebFront visibility per instance — no additional password in this module.', $this->InstanceID)],
                    ],
                ],
                $this->connectionsPanel(),
                [
                    'type'  => 'RowLayout',
                    'items' => array_map(fn ($name) => $panelDefs[$name], $panelOrder),
                ],
                $this->layoutPanel($layout, $panelDefs),
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
            ],
        ];

        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => '💬 Feedback',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub is an early beta — feedback is welcome in the Symcon forum.'],
                    ['type' => 'Button', 'caption' => 'Go to forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Do not show again', 'onClick' => 'OHUBA_DismissReviewHint($id);'],
                ],
            ];
        }

        $form['elements'][] = $this->licenseHint();

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        // „Wozu dieses Modul?" VOR dem News-Banner (Formular-Konvention
        // Punkt 0) — die Orphan-Warnung unten bleibt bewusst noch davor, sie
        // meldet ein akutes Live-Problem, kein Einstiegstext.
        $intro = $this->purposeIntroPanel();
        if ($intro !== null) {
            array_unshift($form['elements'], $intro);
        }

        if (!$this->isRegisteredWithSplitter()) {
            array_unshift($form['elements'], [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'Label', 'caption' => '⚠️ No splitter uses this instance as its Billing instance. Every OCPPHub splitter uses exactly ONE Billing instance, stored with it — this one is not it (regardless of where it sits in the object tree) and is therefore queried by NO splitter. Entries made here have no effect at all. Please enter your data in the instance the splitter created itself (name ends in “… Billing”) and delete this one.'],
                ],
            ]);
        }

        return json_encode($form);
    }

    // SUITE.md „Verbund-Verbindungen im Formular sichtbar machen" (21.09.2026):
    // je Verbindung eine live berechnete Zeile (✅/⚠️/ℹ️/⛔), direkt beim Bau
    // des Formulars, kein Platzhalter-Label zum nachträglichen Suchen.
    private function connectionsPanel(): array
    {
        $lines = [];

        // 1) Splitter, der diese Abrechnung tatsächlich benutzt
        $bound = $this->isRegisteredWithSplitter() ? $this->elternId() : 0;
        if ($bound > 0) {
            $wirkung = (int)@IPS_GetProperty($bound, 'Betriebsart') === 2
                ? OHUB_Text::t('Operating mode ②: every card presentation is checked against these access entries.')
                : OHUB_Text::t('Operating mode ①: the access entries here are not evaluated, every card charges.');
            $lines[] = OHUB_Text::t('✅ Used by splitter #%d “%s”. %s', $bound, IPS_GetName($bound), $wirkung);
        } else {
            $lines[] = OHUB_Text::t('⚠️ No splitter uses this instance as its Billing instance. Entries made here stay without effect (see the notice above).');
        }

        // 2) Tessie-Fahrzeuge
        $verknuepft = [];
        $kaputt = [];
        foreach ($this->getFahrzeuge() as $f) {
            $tid = (int)($f['tessieInstanceId'] ?? 0);
            if ($tid <= 0) {
                continue;
            }
            if (@IPS_InstanceExists($tid) && IPS_GetInstance($tid)['ModuleInfo']['ModuleID'] === self::TESSIE_VEHICLE_GUID) {
                $verknuepft[] = OHUB_Text::t('%s (Tessie #%d)', $this->resolveFahrzeugName($f), $tid);
            } else {
                $kaputt[] = (string)($f['name'] ?? OHUB_Text::t('Vehicle %s', $f['id'] ?? '?'));
            }
        }
        $tessieVorhanden = count(@IPS_GetInstanceListByModuleID(self::TESSIE_VEHICLE_GUID) ?: []);
        if (count($kaputt) > 0) {
            $lines[] = OHUB_Text::t('⚠️ Linked Tessie vehicle no longer exists for: %s. The name is no longer taken live from there; please link it again or enter the name by hand.', implode(', ', $kaputt));
        }
        if (count($verknuepft) > 0) {
            $lines[] = OHUB_Text::t('✅ Vehicle names taken live from Tessie: %s.', implode(', ', $verknuepft));
        } elseif ($tessieVorhanden > 0) {
            $lines[] = OHUB_Text::t('ℹ️ The system has %d Tessie vehicle(s), but no vehicle here is linked. Selectable under “Vehicles”, otherwise the names entered by hand apply.', $tessieVorhanden);
        } else {
            $lines[] = OHUB_Text::t('ℹ️ No Tessie vehicle found in the system: vehicle names apply as entered by hand.');
        }

        // 3) Preis-/Tarifquelle: gibt es (noch) nicht, das ehrlich sagen
        $lines[] = OHUB_Text::t('ℹ️ Price/tariff source: there is no cost calculation yet (planned, stage 3). Only the kWh per charging session are recorded, no price is applied and no tariff instance (e.g. Tibber) is queried.');

        $items = [];
        foreach ($lines as $line) {
            $label = ['type' => 'Label', 'caption' => $line];
            if (str_starts_with($line, '⛔')) {
                $label['color'] = 0xFF0000;
            }
            $items[] = $label;
        }
        return ['type' => 'ExpansionPanel', 'name' => 'ConnectionsPanel', 'caption' => '🔗 Connections in the stack', 'expanded' => true, 'items' => $items];
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    // „Über dieses Modul" (SUITE.md Formular-Konvention Punkt 5) — bewusst
    // NICHT dismissible, Wortlaut verbundweit identisch ("Variante A").
    private function licenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private, non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and would still like to leave something behind? I would be happy about a small donation — entirely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    private function purposeIntroPanel(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋 What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'This “OCPPHub Billing” instance is the customer management: customer → access entry (RFID card) → optionally vehicle/group, with consumption limits and time windows. It always exists (it is created automatically by the associated splitter instance) but ONLY has an effect when “② Multiple users” is selected there — with “① Single user” every card is accepted, regardless of the data maintained here.'],
                ['type' => 'Label', 'caption' => 'The benefit: several people/vehicles on the same wallbox, each with their own card, their own consumption limits (week/month/year) and optionally their own time window — e.g. for a guest card that may only charge during the day.'],
                ['type' => 'Label', 'caption' => 'The same management is also available as a WebFront tile (see the “🧩 Configuration tile” panel below) — handy for assigning it to a secured WebFront without granting console access.'],
                ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'OHUBA_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    // Ziehharmonika-Verhalten für die vier Reiter Fahrzeuge/Gruppen/Kunden/Zugänge:
    // ExpansionPanel liefert kein eigenes onClick-Gruppierungskonzept, onClick feuert
    // sowohl beim Auf- als auch beim Zuklappen — der jeweils aktive Reiter wird darum
    // serverseitig gemerkt (ActiveAccordionPanel), nicht aus dem Klick selbst abgeleitet.
    // Feste Breite (Symcon-Formular-Property, kein CSS-Prozent — % ist auf 9.0 laut
    // Symcon-Community ein bestätigter Darstellungsfehler): eingeklappt teilen sich alle
    // vier WIDTH_NARROW (zusammen = WIDTH_FULL), aufgeklappt nimmt der aktive Reiter
    // WIDTH_FULL allein ein.
    private const PANEL_NAMES = ['Fahrzeuge', 'Gruppen', 'Kunden', 'Zugaenge'];
    private const WIDTH_NARROW = '400px';
    private const WIDTH_FULL = '1600px';

    // SUITE.md 3a „Formular: Aufbau“ (29.10.2026). Die vier Reiter (Fahrzeuge, Gruppen, Kunden,
    // Zugänge) sind eine Ziehharmonika, bei der immer nur einer offen ist und das Modul sich den
    // aktiven Reiter selbst merkt (ActiveAccordionPanel). Wählbar sind deshalb nur die Position und
    // „Ausgeblendet“; „Aufgeklappt/Zugeklappt“ gibt es hier bewusst nicht.
    private function formLayout(): array
    {
        $saved = json_decode($this->ReadPropertyString('FormLayout'), true);
        $by = [];
        foreach (is_array($saved) ? $saved : [] as $row) {
            if (is_array($row) && isset($row['Key'])) {
                $by[(string)$row['Key']] = $row;
            }
        }
        $out = [];
        foreach (self::PANEL_NAMES as $i => $k) {
            $r = $by[$k] ?? [];
            $out[$k] = [
                'hidden' => !empty($r['Hidden']),
                'pos'    => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'    => $i,
            ];
        }
        uasort($out, fn ($a, $b) => [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]);
        return $out;
    }

    private function layoutPanel(array $layout, array $panelDefs): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', OHUB_Text::t((string)($panelDefs[$key]['caption'] ?? $key))));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Hidden' => $l['hidden'], 'Pos' => $l['pos']];
            if ($l['hidden']) {
                $hidden[] = trim((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', $name));
            }
        }
        $hiddenLine = $hidden
            ? [['type' => 'Label', 'caption' => OHUB_Text::t('🙈 Hidden: %s — to show them again, remove the check mark at “Hidden”. Hidden areas keep working, only the display is missing.', implode(', ', $hidden))]]
            : [];
        return [
            'type' => 'ExpansionPanel', 'name' => 'LayoutPanel', 'expanded' => false,
            'caption' => OHUB_Text::t('🪟  Form layout') . ($hidden ? OHUB_Text::t(' (%d hidden)', count($hidden)) : ''),
            'items' => array_merge($hiddenLine, [
                ['type' => 'Label', 'caption' => 'Here you decide in which order the four tabs (vehicles, groups, customers, access entries) appear and which of them you do not want to see at all. It takes effect after “Apply changes” the next time the form is opened. You cannot set expanded/collapsed here: the four tabs form an accordion (only one open at a time), and the module remembers the tab you opened last.'],
                ['type' => 'Label', 'caption' => 'No check mark at “Hidden” means “default”: visible. The position is a number; with equal numbers the default order is kept. “Hidden” removes a tab from the display without it losing anything: its entries are preserved and stay in effect (meant for areas you rarely need). To show it again, remove the check mark or click “Reset to default”. The areas above (purpose, news, documentation, connections) and below (feedback, about this module) stay where they are and cannot be hidden.'],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => 'Areas', 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => 'Area', 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => 'Key', 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => '🙈 Hidden', 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Position', 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => '↩️ Reset to default', 'onClick' => 'echo OHUBA_ResetFormLayout($id);'],
            ]),
        ];
    }

    // Setzt Position und Sichtbarkeit der Reiter im offenen Formular auf den Standard
    // (gilt erst nach „Übernehmen“, keine Selbstpersistenz).
    public function ResetFormLayout(): string
    {
        $captions = ['Fahrzeuge' => OHUB_Text::t('🚙 Vehicles'), 'Gruppen' => OHUB_Text::t('👥 Groups'), 'Kunden' => OHUB_Text::t('🙋 Customers'), 'Zugaenge' => OHUB_Text::t('🪪 Access')];
        $rows = [];
        foreach (self::PANEL_NAMES as $i => $k) {
            $rows[] = ['Key' => $k, 'Panel' => $captions[$k] ?? $k, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return OHUB_Text::t('ℹ️ Reset to default. Click “Apply changes” below to save.');
    }

    private function panelWidth(string $name, string $activePanel): string
    {
        return $name === $activePanel ? self::WIDTH_FULL : self::WIDTH_NARROW;
    }

    // Reihenfolge der Reiter hängt vom aktiven Panel ab (siehe $panelOrder in
    // GetConfigurationForm) — UpdateFormField kann die Reihenfolge von Elementen
    // innerhalb eines RowLayout nicht nachträglich ändern, nur Eigenschaften
    // bestehender Elemente. Darum hier ReloadForm() statt gezielter UpdateFormField-
    // Aufrufe: das Formular wird komplett aus GetConfigurationForm() neu aufgebaut.
    public function OnPanelToggle(string $Panel): void
    {
        $current = $this->ReadAttributeString('ActiveAccordionPanel');
        $newActive = $current === $Panel ? '' : $Panel;
        $this->WriteAttributeString('ActiveAccordionPanel', $newActive);
        $this->ReloadForm();
    }

    // Karte anlernen: eine zuvor unbekannte idTag (siehe CheckAuthorization())
    // wird hier sichtbar gemacht, statt sie aus dem Systemlog abzutippen. Das
    // Element existiert immer im Formular (name 'UnknownCardHint'), Sichtbarkeit
    // steuert 'visible' — analog ReviewHint/NewsPanel.
    private function unknownCardHint(): array
    {
        $tag = $this->ReadAttributeString('LastUnknownIdTag');
        $at = $this->ReadAttributeInteger('LastUnknownIdTagAt');
        $caption = $tag === '' ? '' : OHUB_Text::t('🆕 Unknown card detected: “%s” (%s). No access entry created for it yet — adopt it to bring it into the form as a draft (then check/fill it in below under “Access (cards)” and save with “Apply changes”).', $tag, date(OHUB_Text::t('Y-m-d H:i:s'), $at));
        return [
            'type'    => 'RowLayout',
            'name'    => 'UnknownCardHint',
            'visible' => $tag !== '',
            'items'   => [
                ['type' => 'Label', 'caption' => $caption],
                ['type' => 'Button', 'caption' => 'Adopt as new access entry', 'onClick' => 'OHUBA_AdoptLastUnknownIdTag($id);'],
            ],
        ];
    }

    // Staged NUR per UpdateFormField (kein IPS_SetProperty/ApplyChanges im
    // Button-Handler — Verbundregel „keine Selbstpersistenz in Formular-
    // Buttons"). Bewusst KEIN ReloadForm(): das würde die gerade gestagte,
    // ungespeicherte Zeile wieder verwerfen (siehe Kommentar bei
    // OnPanelToggle) — darum wird der Zugänge-Reiter hier nur aufgeklappt,
    // NICHT ans Ende der Reihenfolge verschoben (kleiner optischer
    // Kompromiss statt Datenverlust).
    public function AdoptLastUnknownIdTag(): void
    {
        $tag = $this->ReadAttributeString('LastUnknownIdTag');
        if ($tag === '') {
            return;
        }

        $rows = json_decode($this->ReadPropertyString('Zugaenge'), true);
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows[] = [
            'idTag'       => $tag,
            'name'        => '',
            'customerId'  => 0,
            'vehicleId'   => 0,
            'active'      => true,
            'validUntil'  => '',
            'allowedFrom' => '',
            'allowedTo'   => '',
            'id'          => 0,
        ];
        $this->UpdateFormField('Zugaenge', 'values', json_encode(array_values($rows)));

        $this->UpdateFormField('PanelZugaenge', 'expanded', true);
        $this->UpdateFormField('PanelZugaenge', 'width', self::WIDTH_FULL);
        foreach (self::PANEL_NAMES as $name) {
            if ($name !== 'Zugaenge') {
                $this->UpdateFormField('Panel' . $name, 'width', self::WIDTH_NARROW);
            }
        }
        $this->WriteAttributeString('ActiveAccordionPanel', 'Zugaenge');

        $this->WriteAttributeString('LastUnknownIdTag', '');
        $this->WriteAttributeInteger('LastUnknownIdTagAt', 0);
        $this->UpdateFormField('UnknownCardHint', 'visible', false);
    }

    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 New in this module — please take a quick look and check your settings if needed:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . OHUB_Text::t($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'OHUBA_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => OHUB_Text::t('🆕 New in version %s', self::NEWS_VERSION), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    // Diese Instanz hat nur dann eine Funktion, wenn ein Splitter sie
    // tatsächlich als SEINE Abrechnung führt — das ist Splitter::AbrechnungID
    // (Attribut), NICHT die Baumposition. `IPS_SetParent()` in Splitters
    // ensureAbrechnung() setzt beim Erstanlegen nur einen kosmetischen
    // Startort; die Instanz kann danach beliebig im Objektbaum verschoben
    // werden (z. B. für eine aufgeräumtere WebFront-Einordnung), ohne die
    // Bindung zu verlieren. Korrigiert 01.09.2026 (Dietmars Frage "gibt es
    // keine andere Möglichkeit als die Abrechnungs-Instanz direkt unter die
    // Splitter-Instanz zu nageln?") — die alte, rein Baumposition-basierte
    // Prüfung hätte nach einem Verschieben der ECHTEN Instanz fälschlich
    // "nicht verbunden" gemeldet, und hätte umgekehrt eine zufällig unter
    // denselben Splitter gehängte ZWEITE (unbenutzte) Instanz fälschlich als
    // verbunden durchgehen lassen. Siehe .docs/architektur.md „Instanzmodell".
    private function elternId(): int
    {
        return (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    // Anfrage an den Splitter (Datenfluss, Symcon-Review 30.10.2026). Liefert `daten` der
    // Antwort, null ohne Splitter oder wenn er die Anfrage ablehnt.
    private function anSplitter(string $aktion, array $parameter = []): ?array
    {
        if ($this->elternId() <= 0) {
            return null;
        }
        $parameter['DataID'] = self::DATA_ZUM_SPLITTER;
        $parameter['Aktion'] = $aktion;
        $parameter['InstanzID'] = $this->InstanceID;
        $a = json_decode((string)$this->SendDataToParent((string)json_encode($parameter)), true);
        return (is_array($a) && !empty($a['ok'])) ? (array)($a['daten'] ?? []) : null;
    }

    // Der Splitter sagt, ob ER diese Instanz als seine Abrechnung führt (genau eine je Splitter).
    private function isRegisteredWithSplitter(): bool
    {
        return !empty($this->anSplitter('AbrechnungGebunden')['gebunden']);
    }

    // Vorführmodus (01.09.2026, Anfrage Dashboard-Sitzung, siehe
    // OCPPHubSplitter::IsDemoMode()): ist der bindende Splitter im
    // Vorführmodus, darf über die Kachel niemand echte Kundendaten anlegen/
    // ändern/löschen — eine öffentliche Demo-Instanz darf keine echten
    // Zugänge/Limits eines Besuchers dauerhaft übernehmen.
    private function isDemoMode(): bool
    {
        $a = $this->anSplitter('Vorfuehrmodus');
        return $a !== null && !empty($a['aktiv']) && $this->isRegisteredWithSplitter();
    }

    // Anfragen des Splitters (Datenfluss). Wir antworten nur auf Nachrichten, die ausdrücklich an
    // diese Instanz gerichtet sind (Ziel „Abrechnung“, InstanzID = diese Instanz).
    public function ReceiveData($JSONString)
    {
        $a = json_decode((string)$JSONString, true);
        if (!is_array($a) || (string)($a['Ziel'] ?? '') !== 'Abrechnung' || (int)($a['InstanzID'] ?? 0) !== $this->InstanceID) {
            return '';
        }
        try {
            switch ((string)($a['Aktion'] ?? '')) {
                case 'Autorisierung':
                    $daten = $this->CheckAuthorization((string)($a['IdTag'] ?? ''));
                    break;
                case 'Verbrauch':
                    $this->RecordConsumption((string)($a['IdTag'] ?? ''), (float)($a['Kwh'] ?? 0), (int)($a['Zeit'] ?? time()));
                    $daten = [];
                    break;
                case 'IdTagZuFahrzeug':
                    $daten = ['idTag' => $this->FindIdTagForVehicleName((string)($a['Name'] ?? ''))];
                    break;
                default:
                    return '';
            }
        } catch (\Throwable $e) {
            $this->LogMessage(OHUB_Text::t('Request “%s” of the splitter could not be processed: %s', (string)($a['Aktion'] ?? '?'), $e->getMessage()), KL_WARNING);
            return '';
        }
        return (string)json_encode(['ok' => true, 'daten' => $daten]);
    }

    // ---------------------------------------------------------------------
    // Datenzugriff (intern)
    // ---------------------------------------------------------------------

    private function getFahrzeuge(): array
    {
        return (array)(json_decode($this->ReadPropertyString('Fahrzeuge'), true) ?: []);
    }

    private function getGruppen(): array
    {
        return (array)(json_decode($this->ReadPropertyString('Gruppen'), true) ?: []);
    }

    private function getKunden(): array
    {
        return (array)(json_decode($this->ReadPropertyString('Kunden'), true) ?: []);
    }

    private function getZugaenge(): array
    {
        return (array)(json_decode($this->ReadPropertyString('Zugaenge'), true) ?: []);
    }

    private function findById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    // Bei Verknüpfung mit einem Tessie-Fahrzeug (Betriebsart ②, Verbund-Kenntnis
    // via TessieVehicle-Instanz) gilt dessen Name als Quelle der Wahrheit — kein
    // doppelt gepflegter Text, immer aktuell. Ohne Verknüpfung: manuelles Feld.
    private function resolveFahrzeugName(array $fahrzeug): string
    {
        $instanceId = (int)($fahrzeug['tessieInstanceId'] ?? 0);
        if ($instanceId > 0 && @IPS_InstanceExists($instanceId) && IPS_GetInstance($instanceId)['ModuleInfo']['ModuleID'] === self::TESSIE_VEHICLE_GUID) {
            return IPS_GetName($instanceId);
        }
        return (string)($fahrzeug['name'] ?? '');
    }

    private function findZugangByIdTag(string $idTag): ?array
    {
        foreach ($this->getZugaenge() as $row) {
            if (($row['idTag'] ?? '') === $idTag) {
                return $row;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // Verbrauchs-Tracking (Attribut, keine Property — Laufzeitdaten)
    // ---------------------------------------------------------------------

    private function periodKeys(int $timestamp): array
    {
        return [
            'week'  => date('o-\WW', $timestamp),   // z. B. "2026-W35" (ISO-Woche)
            'month' => date('Y-m', $timestamp),      // z. B. "2026-08"
            'year'  => date('Y', $timestamp),         // z. B. "2026"
        ];
    }

    private function addPeriodConsumption(int $customerId, int $timestamp, float $kWh): void
    {
        if ($customerId <= 0 || $kWh <= 0) {
            return;
        }
        $log = json_decode($this->ReadAttributeString('ConsumptionLog'), true);
        if (!is_array($log)) {
            $log = [];
        }
        $key = (string)$customerId;
        if (!isset($log[$key]) || !is_array($log[$key])) {
            $log[$key] = [];
        }
        foreach ($this->periodKeys($timestamp) as $periodType => $periodKey) {
            $fullKey = $periodType . ':' . $periodKey;
            $log[$key][$fullKey] = ($log[$key][$fullKey] ?? 0) + $kWh;
        }
        $this->WriteAttributeString('ConsumptionLog', json_encode($log));
    }

    private function getPeriodConsumption(int $customerId, string $periodType, string $periodKey): float
    {
        $log = json_decode($this->ReadAttributeString('ConsumptionLog'), true);
        if (!is_array($log)) {
            return 0.0;
        }
        return (float)($log[(string)$customerId][$periodType . ':' . $periodKey] ?? 0.0);
    }

    // ---------------------------------------------------------------------
    // Vertragsschnittstelle für den Splitter
    // ---------------------------------------------------------------------

    // Prüft eine Kartenauflage. Rückgabe u. a.:
    // ['status' => 'Accepted'|'Blocked'|'Expired'|'Invalid',
    //  'customerId' => int, 'vehicleName' => string]
    public function CheckAuthorization(string $idTag): array
    {
        $zugang = $this->findZugangByIdTag($idTag);
        if ($zugang === null) {
            $this->WriteAttributeString('LastUnknownIdTag', $idTag);
            $this->WriteAttributeInteger('LastUnknownIdTagAt', time());
            return ['status' => 'Invalid'];
        }
        if (!($zugang['active'] ?? true)) {
            return ['status' => 'Blocked', 'reason' => 'zugang-gesperrt'];
        }
        // Store-Checkliste 9b (14.09.2026): Formular/Kachel zeigen/erwarten jetzt
        // TT.MM.JJJJ statt JJJJ-MM-TT. Kein Migrationscode nötig — PHPs
        // strtotime() erkennt punktgetrennte Daten zuverlässig als d.m.Y (deutsche
        // Reihenfolge) UND bindestrichgetrennte weiterhin als Y-m-d, beide Formate
        // laufen hier unverändert nebeneinander (lokal verifiziert: "31.12.2026"
        // UND "2026-12-31" liefern exakt denselben Zeitstempel). Bereits
        // gespeicherte alte JJJJ-MM-TT-Einträge bleiben dadurch gültig.
        $validUntil = (string)($zugang['validUntil'] ?? '');
        if ($validUntil !== '' && strtotime($validUntil . ' 23:59:59') !== false && strtotime($validUntil . ' 23:59:59') < time()) {
            return ['status' => 'Expired'];
        }
        if (!$this->isWithinTimeWindow((string)($zugang['allowedFrom'] ?? ''), (string)($zugang['allowedTo'] ?? ''))) {
            return ['status' => 'Blocked', 'reason' => 'ausserhalb-zeitfenster'];
        }

        $customerId = (int)($zugang['customerId'] ?? 0);
        $kunden = $this->getKunden();
        $kunde = $customerId > 0 ? $this->findById($kunden, $customerId) : null;
        if ($kunde === null) {
            return ['status' => 'Blocked', 'reason' => 'kein-kunde-zugeordnet'];
        }
        if (!($kunde['active'] ?? true)) {
            return ['status' => 'Blocked', 'reason' => 'kunde-gesperrt'];
        }
        if ($this->isOverLimit($kunde, $kunden)) {
            return ['status' => 'Blocked', 'reason' => 'limit-erreicht'];
        }

        $vehicleId = (int)($zugang['vehicleId'] ?? 0);
        $vehicleName = '';
        $vehicleTessieInstanceId = 0;
        if ($vehicleId > 0) {
            $fahrzeug = $this->findById($this->getFahrzeuge(), $vehicleId);
            if ($fahrzeug !== null) {
                $vehicleName = $this->resolveFahrzeugName($fahrzeug);
                $vehicleTessieInstanceId = (int)($fahrzeug['tessieInstanceId'] ?? 0);
            }
        }

        return [
            'status'                  => 'Accepted',
            'customerId'              => $customerId,
            'vehicleName'             => $vehicleName,
            // Additiv (Diagnose-Feature 31.08.2026, siehe .docs/architektur.md
            // „Ladeablehnung erklären") — 0, wenn kein Fahrzeug oder kein
            // Tessie-verknüpftes Fahrzeug zugeordnet ist.
            'vehicleTessieInstanceId' => $vehicleTessieInstanceId,
        ];
    }

    private function isWithinTimeWindow(string $from, string $to): bool
    {
        if ($from === '' || $to === '') {
            return true;
        }
        $now = date('H:i');
        // Einfache HH:MM-Stringvergleiche reichen für den üblichen Fall
        // (Fenster über Mitternacht hinweg wird unten separat behandelt).
        if ($from <= $to) {
            return $now >= $from && $now <= $to;
        }
        // Fenster geht über Mitternacht (z. B. 22:00–06:00).
        return $now >= $from || $now <= $to;
    }

    private function isOverLimit(array $kunde, array $kunden): bool
    {
        $now = time();
        $customerId = (int)($kunde['id'] ?? 0);
        $periods = $this->periodKeys($now);

        foreach (['week' => 'maxKwhWeek', 'month' => 'maxKwhMonth', 'year' => 'maxKwhYear'] as $periodType => $limitField) {
            $limit = (float)($kunde[$limitField] ?? 0);
            if ($limit > 0 && $this->getPeriodConsumption($customerId, $periodType, $periods[$periodType]) >= $limit) {
                return true;
            }
        }

        $groupId = (int)($kunde['groupId'] ?? 0);
        if ($groupId > 0) {
            $gruppe = $this->findById($this->getGruppen(), $groupId);
            if ($gruppe !== null) {
                foreach (['week' => 'maxKwhWeek', 'month' => 'maxKwhMonth', 'year' => 'maxKwhYear'] as $periodType => $limitField) {
                    $limit = (float)($gruppe[$limitField] ?? 0);
                    if ($limit <= 0) {
                        continue;
                    }
                    $groupTotal = 0.0;
                    foreach ($kunden as $member) {
                        if ((int)($member['groupId'] ?? 0) === $groupId) {
                            $groupTotal += $this->getPeriodConsumption((int)$member['id'], $periodType, $periods[$periodType]);
                        }
                    }
                    if ($groupTotal >= $limit) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    // Vom Splitter nach StopTransaction aufgerufen — schreibt die kWh der
    // Transaktion dem zugeordneten Kunden gut (für die Limit-Prüfung oben).
    public function RecordConsumption(string $idTag, float $kWh, int $timestamp): void
    {
        $zugang = $this->findZugangByIdTag($idTag);
        if ($zugang === null) {
            return;
        }
        $this->addPeriodConsumption((int)($zugang['customerId'] ?? 0), $timestamp, $kWh);
    }

    // Diagnose/Automatisierungs-Feature 31.08.2026 ("so etwas wie Autocharge",
    // Dietmars Wunsch, mit Dashboard abgestimmt): findet zu einem per
    // Fahrzeugerkennung übergebenen Namen den idTag EINES darauf verweisenden
    // Zugangs (erster Treffer bei mehreren) — reine Suche, KEINE eigene
    // Gültigkeitsprüfung (aktiv/Zeitfenster/Limit). Der Splitter jagt den
    // gefundenen idTag anschließend durch dieselbe checkIdTag()-Prüfung wie
    // eine echte Kartenauflage, damit alle Regeln identisch greifen — hier
    // keine Logik duplizieren. '' = kein passendes Fahrzeug/kein Zugang
    // gefunden. Namensabgleich exakt (kein Fuzzy-Match) — Kollisionsrisiko
    // bei zwei gleich benannten Fahrzeugen bewusst nicht behandelt (kleiner
    // Nutzerkreis, Namen sind bei der Anlage frei wählbar).
    public function FindIdTagForVehicleName(string $VehicleName): string
    {
        if ($VehicleName === '') {
            return '';
        }
        $fahrzeugId = 0;
        foreach ($this->getFahrzeuge() as $fahrzeug) {
            if ($this->resolveFahrzeugName($fahrzeug) === $VehicleName) {
                $fahrzeugId = (int)($fahrzeug['id'] ?? 0);
                break;
            }
        }
        if ($fahrzeugId === 0) {
            return '';
        }
        foreach ($this->getZugaenge() as $zugang) {
            if ((int)($zugang['vehicleId'] ?? 0) === $fahrzeugId) {
                return (string)($zugang['idTag'] ?? '');
            }
        }
        return '';
    }
}
