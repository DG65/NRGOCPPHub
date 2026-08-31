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
// STUFE 2 / UNGETESTET (wie der gesamte bisherige Live-Test-Zyklus in
// diesem Repo — erst nach Verifikation an WB1 als stabil betrachten).
// ===========================================================================

class OCPPHubAbrechnung extends IPSModule
{
    private const VERSION = '0.2.12';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    private const NEWS_VERSION = '0.2.12';
    private const TESSIE_VEHICLE_GUID = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
    private const SPLITTER_GUID = '{81D3E328-9E12-43A9-825A-F7888530868C}';
    private const NEWS_ITEMS = [
        'Karte anlernen: eine unbekannte Karte (idTag) wird jetzt oben im Formular angezeigt, sobald sie an einer Wallbox aufgelegt wurde — ein Klick auf „Als neuen Zugang übernehmen" trägt sie als Entwurf in die Zugänge-Liste ein, kein Abtippen aus dem Systemlog mehr nötig.',
        'Warnhinweis hinzugekommen, falls diese Instanz NICHT als direktes Kind eines OCPPHub-Splitters angelegt ist — dann wird sie vom Splitter nicht verwendet und hat keine Funktion (Duplikat/Fehlanlage).',
        'Fahrzeuge können jetzt direkt mit einem bereits im NRG-Stack-Verbund bekannten Tessie-Fahrzeug verknüpft werden — Name wird live übernommen, kein doppeltes Pflegen mehr.',
        'Fahrzeuge, Gruppen, Kunden und Zugänge stehen im Formular als vier gleich breite Reiter, die zusammen die volle Formularbreite ausfüllen. Ziehharmonika: der geöffnete Reiter nimmt selbst die volle Breite ein, rückt dabei ganz nach rechts, und die anderen klappen automatisch zu.',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeString('ActiveAccordionPanel', '');

        // Vier Listen, IDs intern vergeben (siehe normalizeIds() in
        // ApplyChanges()). Leere JSON-Arrays als Default.
        $this->RegisterPropertyString('Fahrzeuge', '[]');
        $this->RegisterPropertyString('Gruppen', '[]');
        $this->RegisterPropertyString('Kunden', '[]');
        $this->RegisterPropertyString('Zugaenge', '[]');

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

        if ($changed) {
            @IPS_ApplyChanges($this->InstanceID);
        }
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

        $fahrzeugOptions = [['caption' => '— keins —', 'value' => 0]];
        foreach ($fahrzeuge as $f) {
            $fahrzeugName = $this->resolveFahrzeugName($f);
            $fahrzeugOptions[] = ['caption' => $fahrzeugName . ($f['kennzeichen'] !== '' ? ' (' . $f['kennzeichen'] . ')' : ''), 'value' => (int)$f['id']];
        }
        $gruppenOptions = [['caption' => '— keine —', 'value' => 0]];
        foreach ($gruppen as $g) {
            $gruppenOptions[] = ['caption' => $g['name'], 'value' => (int)$g['id']];
        }
        $kundenOptions = [['caption' => '— keiner —', 'value' => 0]];
        foreach ($kunden as $k) {
            $kundenOptions[] = ['caption' => $k['name'], 'value' => (int)$k['id']];
        }

        // Panels je Name gebaut, damit der aktive Reiter unten (Reihenfolge) ans Ende
        // (= ganz rechts) verschoben werden kann, statt seine feste Position zu behalten.
        $panelDefs = [
            'Fahrzeuge' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelFahrzeuge',
                'caption'  => '🚙 Fahrzeuge',
                'expanded' => $activePanel === 'Fahrzeuge',
                'width'    => $this->panelWidth('Fahrzeuge', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Fahrzeuge\');',
                'items'    => [
                    ['type' => 'Label', 'width' => '540px', 'caption' => '„Tessie-Fahrzeug" wählen, wenn das Auto schon im Verbund bekannt ist (spiegelt den dortigen Namen automatisch — kein doppeltes Eintippen, immer aktuell). Ohne Tessie oder für ein nicht per Tessie erfasstes Auto: Anzeigename/Kennzeichen von Hand eintragen, „Tessie-Fahrzeug" auf „keins" lassen.'],
                    [
                        'type'     => 'List',
                        'name'     => 'Fahrzeuge',
                        'caption'  => 'Fahrzeuge',
                        'rowCount' => 5,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'Tessie-Fahrzeug', 'name' => 'tessieInstanceId', 'width' => '180px', 'add' => 0, 'edit' => ['type' => 'SelectInstance', 'moduleID' => self::TESSIE_VEHICLE_GUID]],
                            ['caption' => 'Anzeigename (falls kein Tessie-Fahrzeug)', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Kennzeichen', 'name' => 'kennzeichen', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ],
                        'values' => $fahrzeuge,
                    ],
                ],
            ],
            'Gruppen' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelGruppen',
                'caption'  => '👥 Gruppen',
                'expanded' => $activePanel === 'Gruppen',
                'width'    => $this->panelWidth('Gruppen', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Gruppen\');',
                'items'    => [
                    ['type' => 'Label', 'width' => '540px', 'caption' => 'Rein zur Bündelung für Verbrauchslimits (z. B. „Familie" mit gemeinsamem Monats-Limit) — kein eigenes Ladeverhalten.'],
                    [
                        'type'     => 'List',
                        'name'     => 'Gruppen',
                        'caption'  => 'Gruppen',
                        'rowCount' => 5,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '150px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Woche', 'name' => 'maxKwhWeek', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Monat', 'name' => 'maxKwhMonth', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Jahr', 'name' => 'maxKwhYear', 'width' => '90px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ],
                        'values' => $gruppen,
                    ],
                ],
            ],
            'Kunden' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelKunden',
                'caption'  => '🙋 Kunden',
                'expanded' => $activePanel === 'Kunden',
                'width'    => $this->panelWidth('Kunden', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Kunden\');',
                'items'    => [
                    [
                        'type'     => 'List',
                        'name'     => 'Kunden',
                        'caption'  => 'Kunden',
                        'rowCount' => 6,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'Name', 'name' => 'name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Aktiv', 'name' => 'active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Gruppe', 'name' => 'groupId', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $gruppenOptions]],
                            ['caption' => 'Limit/Woche (kWh, 0=aus)', 'name' => 'maxKwhWeek', 'width' => '150px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Limit/Monat (kWh, 0=aus)', 'name' => 'maxKwhMonth', 'width' => '150px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                            ['caption' => 'Limit/Jahr (kWh, 0=aus)', 'name' => 'maxKwhYear', 'width' => '150px', 'add' => 0.0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ],
                        'values' => $kunden,
                    ],
                ],
            ],
            'Zugaenge' => [
                'type'     => 'ExpansionPanel',
                'name'     => 'PanelZugaenge',
                'caption'  => '🪪 Zugänge (Karten)',
                'expanded' => $activePanel === 'Zugaenge',
                'width'    => $this->panelWidth('Zugaenge', $activePanel),
                'onClick'  => 'OHUBA_OnPanelToggle($id, \'Zugaenge\');',
                'items'    => [
                    [
                        'type'     => 'List',
                        'name'     => 'Zugaenge',
                        'caption'  => 'Zugänge',
                        'rowCount' => 8,
                        'add'      => true,
                        'delete'   => true,
                        'columns'  => [
                            ['caption' => 'idTag (Karte)', 'name' => 'idTag', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Anzeigename', 'name' => 'name', 'width' => '140px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Kunde', 'name' => 'customerId', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $kundenOptions]],
                            ['caption' => 'Fahrzeug', 'name' => 'vehicleId', 'width' => '160px', 'add' => 0, 'edit' => ['type' => 'Select', 'options' => $fahrzeugOptions]],
                            ['caption' => 'Aktiv', 'name' => 'active', 'width' => '60px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Gültig bis (JJJJ-MM-TT, leer=unbegrenzt)', 'name' => 'validUntil', 'width' => '160px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Erlaubt ab (HH:MM, leer=immer)', 'name' => 'allowedFrom', 'width' => '120px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Erlaubt bis (HH:MM, leer=immer)', 'name' => 'allowedTo', 'width' => '120px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ],
                        'values' => $this->getZugaenge(),
                    ],
                ],
            ],
        ];

        // Aktiver Reiter wandert ans Ende der Reihenfolge (= ganz rechts), die übrigen
        // drei behalten ihre relative Reihenfolge zueinander bei.
        $panelOrder = self::PANEL_NAMES;
        if ($activePanel !== '' && in_array($activePanel, $panelOrder, true)) {
            $panelOrder = array_values(array_diff($panelOrder, [$activePanel]));
            $panelOrder[] = $activePanel;
        }

        $form = [
            'elements' => [
                $this->unknownCardHint(),
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖 Dokumentation & Hilfe (Version ' . self::VERSION . ')',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Was diese Instanz macht: Kundenverwaltung für Betriebsart ② „Mehrere Nutzer" (siehe Splitter-Formular). Diese Instanz existiert immer (wird vom Splitter automatisch angelegt), wirkt sich aber NUR aus, solange am Splitter „Mehrere Nutzer" gewählt ist — bei „Einzelnutzer" wird jede Karte angenommen und diese Daten hier bleiben ungenutzt liegen.'],
                        ['type' => 'Label', 'caption' => '📋 Reihenfolge beim Anlegen: erst Fahrzeuge und Gruppen (falls gewünscht), dann Kunden (können einer Gruppe zugeordnet werden), zuletzt Zugänge/Karten (werden genau einem Kunden zugeordnet, optional einem Fahrzeug). Ein Kunde kann mehrere Zugänge haben — z. B. Hauptkarte + Ersatzkarte.'],
                        ['type' => 'Label', 'caption' => '🔑 Zugang = eine einzelne RFID-Karte (idTag). Der idTag muss exakt dem Wert entsprechen, den die Wallbox beim Kartenauflegen an OCPPHub meldet (im Debug der Splitter-Instanz als „Authorize" sichtbar). Beliebig viele Zugänge möglich — keine Obergrenze wie bei einer Wallbox-internen Kartenliste.'],
                        ['type' => 'Label', 'caption' => '⏱️ Verbrauchslimits (Woche/Monat/Jahr, 0 = kein Limit) gelten je Kunde UND je Gruppe unabhängig — das jeweils restriktivere Limit greift zuerst. Eine bereits laufende Ladung wird beim Erreichen NICHT abgebrochen, nur der nächste Kartenaufleger wird abgelehnt. Zählperiode ist die Kalenderwoche/-monat/-jahr, nicht rollierend.'],
                        ['type' => 'Label', 'caption' => '🕐 Zeitfenster am Zugang (Uhrzeit von/bis, Format HH:MM, leer = keine Einschränkung) — z. B. eine Gastkarte, die nur tagsüber laden darf.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Noch NICHT enthalten (Stufe 3): Tarife/Kostenberechnung, Berichte/CSV-Export, Reservierungsgebühr. Die Verbrauchsdaten (kWh je Transaktion) werden aber schon jetzt mitgeschrieben, damit ein späteres Zuschalten nicht auf fehlenden Rohdaten aufsetzen muss.'],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => array_map(fn ($name) => $panelDefs[$name], $panelOrder),
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
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'OHUBA_DismissReviewHint($id);'],
                ],
            ];
        }

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        if (!$this->hasSplitterParent()) {
            array_unshift($form['elements'], [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'Label', 'caption' => '⚠️ Diese Instanz ist NICHT direktes Kind einer OCPPHub-Splitter-Instanz. Der Splitter benutzt ausschließlich seine EIGENE, selbst angelegte „OCPPHub Abrechnung"-Instanz (im Objektbaum direkt unter ihm) — diese hier wurde offenbar manuell erstellt und wird darum von KEINEM Splitter jemals abgefragt. Eingaben hier haben keinerlei Wirkung. Bitte deine Daten stattdessen in der Instanz unter deinem OCPPHub-Splitter eintragen und diese hier löschen.'],
                ],
            ]);
        }

        return json_encode($form);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
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
        $caption = $tag === '' ? '' : '🆕 Unbekannte Karte erkannt: „' . $tag . '" (' . date('d.m.Y H:i:s', $at) . '). Noch kein Zugang dafür angelegt — übernehmen, um als Entwurf ins Formular zu holen (danach unten bei „Zugänge" prüfen/ausfüllen und mit „Übernehmen" speichern).';
        return [
            'type'    => 'RowLayout',
            'name'    => 'UnknownCardHint',
            'visible' => $tag !== '',
            'items'   => [
                ['type' => 'Label', 'caption' => $caption],
                ['type' => 'Button', 'caption' => 'Als neuen Zugang übernehmen', 'onClick' => 'OHUBA_AdoptLastUnknownIdTag($id);'],
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
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'OHUBA_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    // Diese Instanz hat nur dann eine Funktion, wenn sie ein direktes Kind
    // einer OCPPHub-Splitter-Instanz ist (siehe ensureAbrechnung() im
    // Splitter — er erstellt/verwendet ausschließlich diese eine Instanz,
    // sucht nicht anderswo). Eine manuell woanders angelegte Abrechnung-
    // Instanz wird von KEINEM Splitter je konsultiert (live gefunden
    // 31.08.2026, siehe .docs/architektur.md „Instanzmodell").
    private function hasSplitterParent(): bool
    {
        $parentId = @IPS_GetParent($this->InstanceID);
        if ($parentId === 0 || !@IPS_InstanceExists($parentId)) {
            return false;
        }
        return (IPS_GetInstance($parentId)['ModuleInfo']['ModuleID'] ?? '') === self::SPLITTER_GUID;
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
