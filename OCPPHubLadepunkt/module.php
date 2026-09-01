<?php

// ===========================================================================
// OCPPHub Ladepunkt — eine Instanz je Wallbox/Connector, Kind der OCPPHub-
// Splitter-Instanz. Hält die sichtbaren Variablen (Ident-Vokabular bewusst
// identisch zu ChargerHub, siehe .docs/architektur.md „Instanzmodell") und
// das eigenständige PV-Überschussladen (Fallback ohne EMS, Logik aus
// ChargerHub SurplusChargeControl() 0.9.53 portiert).
//
// STUFE 1 (siehe .docs/pflichtenheft.md): kein RFID, keine Reservierung,
// keine Phasenumschaltung (ctl_phase_mode noch nicht implementiert — pro
// Hersteller zu verifizieren, siehe architektur.md), keine
// Splitter-interne Lastverteilung bei mehreren eigenen Ladepunkten
// gleichzeitig (TODO Stufe 2, siehe architektur.md „Splitter-interne
// Lastverteilung"). UNGETESTET, siehe Splitter-Header.
// ===========================================================================

class OCPPHubLadepunkt extends IPSModule
{
    private const METERHUB_GUID     = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const INVERTERHUB_GUID  = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const EMS_GUID          = '{90286A25-E6C9-4A66-BD4E-0CFB707C2C6C}';
    private const SPLITTER_GUID     = '{81D3E328-9E12-43A9-825A-F7888530868C}';
    private const TIBBER_GUID       = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';
    private const CHARGERHUB_GUID   = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';

    private const MIN_CURRENT_HARD = 6; // A — kleinster IEC-61851-Ladestrom

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.2.12';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    // „Was ist neu"-Banner (Verbund-Konvention, siehe SUITE.md, Referenz
    // ChargerHub) — bei jedem nutzerrelevanten Änderungs-Bump aktualisieren,
    // NICHT bei jedem library.json-Build (sonst nervt es).
    private const NEWS_VERSION = '0.2.9';
    private const NEWS_ITEMS = [
        'Fix: `power` blieb nach dem Ende einer Ladung auf dem letzten Wert stehen (z. B. dauerhaft „10760 W" trotz beendeter Sitzung), weil ohne aktives Laden keine neue Messwert-Nachricht mehr kommt, die das korrigiert hätte. Jeder Status außer „Charging" setzt `power` jetzt selbst auf 0 W.',
        'Neu: Cross-Hub-Warnung — meldet sich ChargerHub UND OCPPHub gleichzeitig an derselben Wallbox an (per IP-Abgleich erkannt), warnt das Formular jetzt deutlich. Live-Fund: das kann die Ladefreigabe komplett blockieren, ohne dass eine der beiden Seiten eine Fehlermeldung zeigt — die OCPP-Ebene meldete "Accepted", aber es floss kein Strom, sogar die Hersteller-App der Wallbox konnte nicht laden.',
        'Kritischer Fix (Live-Fund, erster echter Ladeversuch): der manuelle „Ladefreigabe"-Schalter sendete bislang immer einen internen Platzhalter statt einer echten Karte — unter Betriebsart ② wurde das zu Recht abgelehnt, der Schalter zeigte aber trotzdem „an", ohne dass sichtbar war, dass nichts startet. Der Schalter versucht jetzt zuerst den echten, registrierten Zugang des bereits erkannten Fahrzeugs zu benutzen (derselbe Weg wie die Auto-Autorisierung); bei einem Fehlschlag springt er sofort zurück auf „aus" UND zeigt den genauen Grund in `block_reason` (z. B. „Zugang ist gesperrt.", „Verbrauchslimit ist erreicht.") — jede Ablehnung einer Karte/eines Zugangs ist damit jetzt sofort sichtbar, nicht nur eine vom Charger selbst ohne Begründung abgelehnte RemoteStartTransaction.',
        'Neu: automatische Ladefreigabe für erkannte Fahrzeuge ("so etwas wie Autocharge") — erkennt Dashboard per Zeitkorrelation ein Fahrzeug mit aktivem Zugang, wird bei Betriebsart ② automatisch dessen Karte "aufgelegt" (dieselbe Prüfung wie eine echte Kartenauflage). Design mit Dashboard abgestimmt.',
        'Zwei Anzeige-Fixes (Dashboard-Fund, Live-Test): „Stromlimit" zeigte unsinnige Zehntel-Ampere (z. B. „10.0 A") — lag am geteilten NRG.Ampere-Profil, das auf diesem System als Float mit 1 Nachkommastelle existiert; ctl_curr_limit hat jetzt ein eigenes ganzzahliges Profil. „Fahrzeug angesteckt" zeigte das Symcon-Standard-Ein/Aus einer profillosen Bool-Variable — jetzt eigenes Profil mit Ja/Nein, analog ChargerHub.',
        'Neue Diagnose: bei einer eindeutigen Ladeablehnung wird — falls das Fahrzeug per Tessie verknüpft ist — automatisch nach einer Erklärung gefragt (eigene Ladeplanung aktiv, Ladelimit erreicht, oder Fahrzeug schläft gerade und wird automatisch aufgeweckt), sichtbar in der neuen Variable `block_reason`. Ergänzend ein unsicherer Tibber-Grid-Rewards-Namensabgleich, ausdrücklich als "möglicherweise" markiert.',
        'Kritischer Fix (Dashboard-Fund, Live-Test): „Laden starten" schlug am Ladepunkt mit einem PHP-Fatal-Error ab (ArgumentCountError) — Symcons generierte globale Funktion für RemoteStart() ignoriert PHP-Standardwerte auf Parametern, ein fehlender dritter Parameter ließ jeden manuellen Ladestart über Dashboard/ctl_enable scheitern.',
        'Stufe 2: Reservierung hinzugekommen — OHUBL_Reserve($idTag, $bisWann)/OHUBL_CancelReservation(), sichtbar in den neuen Variablen reserved_by/reserved_until. Eine aktive Reservierung blockiert jede Kartenauflage mit einem anderen idTag, unabhängig von der Splitter-Betriebsart.',
        'RFID-Autorisierung/Verbrauchslimits (Betriebsart ② am Splitter) wirken sich jetzt aus — vorher wurde jede Karte unabhängig von der Kundenverwaltung angenommen.',
    ];

    // Frische-Wache Überschussladen (ChargerHub-Empfehlung 30.08.2026): ist
    // die letzte MeterValues-Meldung älter, wird nicht mehr geregelt. Passt
    // zum angestrebten MeterValueSampleInterval von 10s (siehe Splitter
    // onBootNotification/ChangeConfiguration) mit reichlich Marge.
    private const MAX_METER_VALUES_AGE_SECONDS = 30;

    private const MANAGEDBY_ALL = ['none', 'ems', 'goe-controller', 'tibber', 'p14a', 'marketer', 'other'];
    private const MANAGEDBY_LABELS = [
        'none'           => 'Niemand — frei / manuell (Standard)',
        'ems'            => 'Energiemanagement (EMS)',
        'goe-controller' => 'go-e Controller (Überschussladen)',
        'tibber'         => 'Tibber (Regelenergie / Grid Rewards)',
        'p14a'           => '§14a-Steuerung (Netzbetreiber)',
        'marketer'       => 'Direktvermarkter',
        'other'          => 'Anderes externes Lastmanagement',
    ];

    // OCPP-1.6-StatusNotification-Werte (ChargePointStatus) → Anzeige-Code
    // fürs `state`-Profil (Sprachregel: Anzeige auf Deutsch, Rohwert bleibt
    // als state_raw für Diagnose erhalten).
    private const STATUS_MAP = [
        'Available'     => 0,
        'Preparing'     => 1,
        'Charging'      => 2,
        'SuspendedEVSE' => 3,
        'SuspendedEV'   => 4,
        'Finishing'     => 5,
        'Reserved'      => 6,
        'Unavailable'   => 7,
        'Faulted'       => 8,
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeString('SeenNews', '');

        // Charge-Point-Identity — der URL-Pfad-Teil, mit dem sich diese
        // Wallbox beim Splitter meldet. Einziges Pflichtfeld.
        $this->RegisterPropertyString('CPID', '');
        // FIX 30.08.2026 (Live-Fund, Dashboard-Diagnose + eigene Nachprüfung
        // direkt an Dietmars Instanz): IPS_GetParent()/IPS_GetChildrenIDs()
        // spiegeln NICHT zuverlässig die Splitter-Zuordnung — Objekte lassen
        // sich in der Konsole frei in andere Kategorien verschieben (Dietmar
        // organisiert seine Instanzen unter „Geräte / Module"), wodurch die
        // Objektbaum-Position von der tatsächlichen Splitter-Zugehörigkeit
        // abweichen kann. Live bestätigt: WB1 lag unter einer fremden
        // Kategorie (#29186), nicht unter dem Splitter — dadurch fand
        // OHUB_GetFunctions() UND der interne IPS_GetParent()-Aufruf hier
        // (für Steuerbefehle) die Instanz nicht mehr. Explizite Property
        // statt Objektbaum-Position, gleiches Muster wie OCPPHub Konfigurator.
        $this->RegisterPropertyInteger('SplitterID', 0);
        $this->RegisterPropertyString('Label', '');

        $this->RegisterPropertyInteger('MinCurrent', self::MIN_CURRENT_HARD);
        $this->RegisterPropertyInteger('MaxCurrent', 16);
        $this->RegisterPropertyString('ManagedBy', 'none');

        // Eigenständiges Überschussladen als Fallback ohne EMS (Dietmars
        // Vorgabe, wie in ChargerHub) — Default aus, sicherer Opt-in.
        $this->RegisterPropertyBoolean('EnableSurplusCharging', false);
        $this->RegisterPropertyInteger('IntervalFast', 10);
        $this->RegisterPropertyInteger('StorageSharePercent', 0);
        $this->RegisterPropertyFloat('BatteryCapacityKWh', 0.0);
        $this->RegisterPropertyInteger('SurplusMeterID', 0);

        $this->RegisterAttributeInteger('LastTransactionId', 0);
        $this->RegisterAttributeInteger('MeterStartWh', 0);
        // idTag der laufenden Transaktion — StopTransaction.req liefert
        // idTag laut OCPP-1.6-Spezifikation nur OPTIONAL, Splitter fällt
        // bei fehlendem Feld hierauf zurück (siehe OHUB_onStopTransaction).
        $this->RegisterAttributeString('LastIdTag', '');
        // Reservierung (Stufe 2) — siehe Reserve()/CancelReservation()/
        // GetActiveReservationIdTag().
        $this->RegisterAttributeString('ReservedIdTag', '');
        $this->RegisterAttributeInteger('ReservedUntilTs', 0);
        $this->RegisterAttributeInteger('ReservationId', 0);
        // Frische-Wache fürs Überschussladen (ChargerHub-Empfehlung
        // 30.08.2026) — siehe UpdateMeterValues()/Update().
        $this->RegisterAttributeInteger('LastMeterValuesAt', 0);
        // Diagnose-Feature 31.08.2026 (Ladeablehnung erklären) — vom
        // Splitter bei idTag-Direktzuordnung mitgesetzt (0 = kein Tessie-
        // verknüpftes Fahrzeug), siehe SetVehicleTessieId()/
        // DiagnoseBlockReason().
        $this->RegisterAttributeInteger('LastVehicleTessieId', 0);
        // Auto-Autorisierung ("so etwas wie Autocharge", 31.08.2026) — siehe
        // maybeAutoAuthorize(): Sperrfrist gegen wiederholte Versuche.
        $this->RegisterAttributeInteger('LastAutoAuthAttempt', 0);
        // Tages-Override „heute Vollladen trotz PV-Vorrang" (Backend-Funktion
        // für Dashboard, siehe OHUB_SetDailyOverride am Splitter). Reset auf
        // "aus" übernimmt OCPPHub SELBST anhand von DailyOverrideDate (siehe
        // Update()) — Dashboard-Rückfrage 30.08.2026 zeigte, dass ein
        // Cron/Timer auf deren Seite unnötige neue Infrastruktur wäre, wenn
        // der Zustand ohnehin hier bei uns liegt.
        $this->RegisterAttributeBoolean('DailyOverride', false);
        $this->RegisterAttributeString('DailyOverrideDate', '');
        // Cross-Hub-Erkennung (Live-Fund 01.09.2026) — Quell-IP der
        // eingehenden WebSocket-Verbindung, vom Splitter durchgereicht
        // (siehe SetSourceIP()/forwardSourceIp()), für einen Heuristik-
        // Abgleich gegen ChargerHubs konfigurierte Modbus-IP.
        $this->RegisterAttributeString('SourceIP', '');

        $this->RegisterTimer('SurplusTimer', 0, 'OHUBL_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('EnableActionsTimer', 0, 'OHUBL_EnableActions($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        $active = $this->ReadPropertyString('CPID') !== '';
        if (!$active) {
            $this->SetTimerInterval('SurplusTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval(
            'SurplusTimer',
            $this->ReadPropertyBoolean('EnableSurplusCharging') ? $this->ReadPropertyInteger('IntervalFast') * 1000 : 0
        );
        $this->SetTimerInterval('EnableActionsTimer', 200);
        $this->SetStatus(102);
    }

    // 200ms nach ApplyChanges (Muster wie ChargerHub/InverterHub, siehe
    // SUITE.md „IP-Symcon-Stolpersteine" — EnableAction() braucht die fertig
    // aufgebaute Instanz-Baumstruktur, deshalb verzögert statt direkt in
    // ApplyChanges()).
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);
        $this->EnableAction('ctl_enable');
        $this->EnableAction('ctl_curr_limit');
    }

    public function GetConfigurationForm()
    {
        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖 Dokumentation & Hilfe (Version ' . self::VERSION . ')',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Was diese Instanz macht: eine „OCPPHub Ladepunkt"-Instanz je Wallbox/Connector — sie hält die sichtbaren Messwerte und Steuervariablen (Ladeleistung, Energiezähler, Status, Ladefreigabe, Stromlimit) und trägt optional das eigenständige PV-Überschussladen. Die eigentliche OCPP-Kommunikation läuft komplett über den zugeordneten Splitter; diese Instanz selbst hält keine WebSocket-Verbindung.'],
                        ['type' => 'Label', 'caption' => '🆔 Charge-Point-Identity: muss exakt zu dem Namen passen, den die Wallbox selbst in ihrer eigenen OCPP-Konfiguration als letztes Pfadstück der Backend-URL mitschickt (Groß-/Kleinschreibung zählt). Am einfachsten über „OCPPHub Konfigurator" anlegen — der zeigt bereits verbundene Wallboxen an und füllt dieses Feld beim Erstellen automatisch korrekt aus, inklusive der Splitter-Zuordnung unten.'],
                        ['type' => 'Label', 'caption' => '⚠️ „OCPPHub-Splitter" unten ist ein Pflichtfeld, auch wenn die Instanz automatisch über den Konfigurator angelegt wurde und dort schon vorausgefüllt sein sollte. Ohne diese Zuordnung findet der Splitter diesen Ladepunkt weder für eingehende OCPP-Nachrichten noch für Steuerbefehle (Ladefreigabe, Stromlimit) noch für den Dashboard-Vertrag — Symcons Position dieser Instanz im Objektbaum (welcher Kategorie/welchem Ordner sie in der Konsole zugeordnet ist) reicht dafür ausdrücklich NICHT, weil sich Instanzen dort frei verschieben lassen, ohne dass sich an der eigentlichen Zuordnung etwas ändert.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Funktionsumfang: die eigentlichen Messwert- und Steuervariablen (`power`, `energy_total`, `energy_session`, `state`, `vehicle_plugged`, `vehicle_name`, `vehicle_soc`, `ctl_enable`, `ctl_curr_limit`, `surplus_status`, `reserved_by`, `reserved_until`, `block_reason`) erscheinen als Kind-Objekte dieser Instanz im Objektbaum, NICHT hier im Konfigurationsformular — dort auch der Ladefreigabe-Schalter zum manuellen Testen.'],
                        ['type' => 'Label', 'caption' => '🩺 Ladeablehnung erklären (`block_reason`): lehnt die Wallbox einen Ladestart/eine Stromlimit-Änderung eindeutig ab, wird — falls das Fahrzeug per Tessie verknüpft ist (siehe „OCPPHub Abrechnung"-Instanz) — automatisch nachgefragt, ob eine eigene Ladeplanung im Fahrzeug aktiv ist, das Ladelimit schon erreicht ist, oder das Fahrzeug gerade schläft (dann wird automatisch ein Aufwecken angestoßen). Zusätzlich, aber ausdrücklich nur als unsicherer Hinweis: ein Namensabgleich gegen aktive Tibber-Grid-Rewards-Steuerungen. Ohne Tessie-Verknüpfung oder ohne eindeutige Ablehnung bleibt `block_reason` leer.'],
                        ['type' => 'Label', 'caption' => '🔓 Automatische Ladefreigabe für erkannte Fahrzeuge: erkennt Dashboard per eigener Zeitkorrelation (nicht wir selbst — bewusst EIN Korrelationsmechanismus im Verbund) ein Fahrzeug an diesem Ladepunkt, das einem aktiven Zugang in der Abrechnung-Instanz zugeordnet ist, wird bei Betriebsart ② automatisch dessen Karte „aufgelegt" (dieselbe Prüfung wie eine echte Kartenauflage, alle Limits/Zeitfenster gelten identisch) — praktisch das Ergebnis von „Autocharge", ohne dass die Wallbox das selbst können muss. Kein Zwang zum sofortigen Losladen, nur zur Freigabe; wirkt erst, sobald das Ladepunkt-Modul der Dashboard-Sitzung diese Zuordnung meldet.'],
                        ['type' => 'Label', 'caption' => '🎫 RFID-Autorisierung/Verbrauchslimits: wird zentral in der „OCPPHub Abrechnung"-Instanz gepflegt, gilt aber nur, wenn am Splitter „② Mehrere Nutzer" ausgewählt ist — bei „① Einzelnutzer" wird jede Karte angenommen, unabhängig davon, was dort hinterlegt ist.'],
                        ['type' => 'Label', 'caption' => '🔒 Reservierung: unabhängig von der Splitter-Betriebsart nutzbar (Backend-Funktionen `OHUBL_Reserve`/`OHUBL_CancelReservation`, Dashboard baut die Bedienoberfläche). Solange eine Reservierung aktiv ist, wird jede Kartenauflage mit einem ANDEREN idTag abgelehnt — sichtbar in `reserved_by`/`reserved_until`. Diese Blockade prüfen wir selbst (unabhängig davon, ob die Wallbox den OCPP-Kernbefehl `ReserveNow` selbst unterstützt) — manche Modelle (z. B. go-e) lehnen `ReserveNow` mit „NotImplemented" ab, was nur eine etwaige eigene Anzeige an der Wallbox betrifft, nicht unsere Durchsetzung.'],
                    ],
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'CPID',
                    'caption' => 'Charge-Point-Identity (aus der Wallbox-OCPP-Konfiguration)',
                ],
                [
                    'type'     => 'SelectInstance',
                    'name'     => 'SplitterID',
                    'caption'  => 'OCPPHub-Splitter (Pflichtfeld)',
                    'moduleID' => self::SPLITTER_GUID,
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Label',
                    'caption' => 'Anzeigename (leer = Instanzname)',
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⚡ Stromgrenzen & Steuerungshoheit',
                    'items'   => [
                        ['type' => 'NumberSpinner', 'name' => 'MinCurrent', 'caption' => 'Minimaler Ladestrom (A)', 'minimum' => 6, 'maximum' => 32, 'suffix' => 'A'],
                        ['type' => 'Label', 'caption' => 'Kleinster Ladestrom, den die Wallbox/das Fahrzeug akzeptiert (IEC 61851: 6 A). Unterhalb dieser Schwelle pausiert das Überschussladen die Ladung komplett, statt mit zu wenig Strom weiterzuladen.'],
                        ['type' => 'NumberSpinner', 'name' => 'MaxCurrent', 'caption' => 'Maximaler Ladestrom (A)', 'minimum' => 6, 'maximum' => 63, 'suffix' => 'A'],
                        ['type' => 'Label', 'caption' => 'Zuleitung/Absicherung dieses Ladepunkts — harte Obergrenze für jedes Stromlimit, das OCPPHub setzt (zusätzlich zum Hardware-Limit der Wallbox), unabhängig davon, was ein EMS anfordert.'],
                        [
                            'type'    => 'Select',
                            'name'    => 'ManagedBy',
                            'caption' => 'Wer regelt diesen Ladepunkt?',
                            'options' => array_map(
                                fn ($key) => ['value' => $key, 'caption' => self::MANAGEDBY_LABELS[$key]],
                                self::MANAGEDBY_ALL
                            ),
                        ],
                        ['type' => 'Label', 'caption' => '⚠️ Zwei-Regler-Warnung: Regelt bereits etwas anderes diese Wallbox — go-e Controller, Lastmanagement, Tibber Grid Rewards oder eine §14a-Steuerung —, darf OCPPHub nicht parallel Ladefreigabe/Stromlimit schreiben (beide Regler überschreiben sich sonst). Hier eintragen, wer die Hoheit hat: bei allem außer „Niemand" bleibt das eigenständige Überschussladen unten automatisch passiv.'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '☀️ PV-Überschussladen (eigenständig, nur ohne aktives EMS)',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'EnableSurplusCharging', 'caption' => 'Aktivieren'],
                        ['type' => 'Label', 'caption' => 'Nur wirksam, wenn oben „Wer regelt?" auf „Niemand" steht UND kein aktives EMS installiert ist UND ein MeterHub-Zähler am Netzanschlusspunkt einen Echtzeit-Wert liefert. Ist EMS aktiv, hat es immer Vorrang — diese Option greift dann automatisch nicht. Sichtbarer Status erscheint als Variable „Überschussladen" (`surplus_status`), sobald diese Option angehakt ist.'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Regelintervall (s, Fallback/Watchdog)', 'minimum' => 5, 'maximum' => 300, 'suffix' => 's'],
                        ['type' => 'Label', 'caption' => 'Die Regelung rechnet primär EREIGNISGETRIEBEN neu, sobald die Wallbox eine frische Messung (MeterValues) meldet — dieser Timer ist nur der Fallback, falls mal keine Meldung kommt.'],
                        ['type' => 'NumberSpinner', 'name' => 'StorageSharePercent', 'caption' => 'Anteil für Speicher (%)', 'minimum' => 0, 'maximum' => 100, 'suffix' => '%'],
                        ['type' => 'Label', 'caption' => 'Dieser Anteil des Überschusses bleibt dem Speicher vorbehalten und wird von der Ampere-Berechnung fürs Laden abgezogen. 0 % = kompletter Überschuss geht in die Wallbox, 100 % = nichts geht in die Wallbox.'],
                        ['type' => 'NumberSpinner', 'name' => 'BatteryCapacityKWh', 'caption' => 'Speicherkapazität (kWh, 0 = kein Speicher/unbekannt)', 'minimum' => 0, 'maximum' => 200, 'digits' => 1, 'suffix' => 'kWh'],
                        ['type' => 'Label', 'caption' => 'Aktuell nur informativ hinterlegt — die darauf aufbauende Phasenumschalt-Wartezeit (wie bei ChargerHub) ist bei OCPPHub noch nicht umgesetzt (Phasenumschaltung ist Stufe-2-Thema).'],
                        ['type' => 'SelectInstance', 'name' => 'SurplusMeterID', 'caption' => 'Netzzähler erzwingen (leer = automatisch über MeterHub-Vertrag)', 'moduleID' => self::METERHUB_GUID],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => '🔄 Übernehmen erzwingen (ohne Formularänderung)', 'onClick' => "IPS_ApplyChanges(\$id); echo '✅ ApplyChanges() ausgeführt.';"],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Charge-Point-Identity fehlt'],
            ],
        ];

        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type'  => 'RowLayout',
                'name'  => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub ist früher Beta-Stand — Rückmeldungen willkommen über github.com/DG65/NRGOCPPHub.'],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'OHUBL_DismissReviewHint($id);'],
                ],
            ];
        }

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        $conflictId = $this->findConflictingChargerHubInstance();
        if ($conflictId !== 0) {
            array_unshift($form['elements'], [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'Label', 'caption' => '⚠️ Diese Wallbox (IP ' . $this->ReadAttributeString('SourceIP') . ') scheint GLEICHZEITIG von ChargerHub verwaltet zu werden (Instanz „' . @IPS_GetName($conflictId) . '"). Live-Fund 01.09.2026: das kann die Ladefreigabe blockieren, OHNE dass eine der beiden Seiten eine Fehlermeldung zeigt (Symptom damals: OCPP meldete überall „Accepted", aber es floss kein Strom, sogar die Hersteller-App der Wallbox konnte nicht laden). Bitte pro Wallbox nur EINEN Kanal aktiv lassen — entweder ChargerHub (Modbus) ODER OCPPHub (OCPP), nicht beides. Heuristik über die IP-Adresse — bei Hostname-Konfiguration bei ChargerHub kann dieser Hinweis fehlen, auch wenn ein Konflikt vorliegt.'],
                ],
            ]);
        }

        return json_encode($form);
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
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'OHUBL_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    private function CreateProfiles(): void
    {
        // Gemeinsame NRG.*-Profile (Verbund-Regel 8) — nur anlegen, wenn sie
        // noch nicht existieren, nie überschreiben.
        if (!IPS_VariableProfileExists('NRG.Watt')) {
            IPS_CreateVariableProfile('NRG.Watt', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Watt', '', ' W');
            IPS_SetVariableProfileDigits('NRG.Watt', 0);
        }
        if (!IPS_VariableProfileExists('NRG.kWh')) {
            IPS_CreateVariableProfile('NRG.kWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.kWh', '', ' kWh');
            IPS_SetVariableProfileDigits('NRG.kWh', 1);
        }
        if (!IPS_VariableProfileExists('NRG.Ampere')) {
            IPS_CreateVariableProfile('NRG.Ampere', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('NRG.Ampere', '', ' A');
        }
        if (!IPS_VariableProfileExists('NRG.Percent')) {
            IPS_CreateVariableProfile('NRG.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Percent', '', ' %');
            IPS_SetVariableProfileDigits('NRG.Percent', 0);
            IPS_SetVariableProfileValues('NRG.Percent', 0, 100, 1);
        }

        // Modul-eigene Profile (Präfix OHUB., Verbund-Regel 8): Sitzungs-kWh
        // bewusst NICHT NRG.kWh, damit die MeterHub-Zählersuche den
        // rückspringenden Sitzungswert nicht als Zählerstand aufnimmt.
        if (!IPS_VariableProfileExists('OHUB.kWhSession')) {
            IPS_CreateVariableProfile('OHUB.kWhSession', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('OHUB.kWhSession', '', ' kWh');
            IPS_SetVariableProfileDigits('OHUB.kWhSession', 2);
        }
        if (!IPS_VariableProfileExists('OHUB.ChargePointStatus')) {
            IPS_CreateVariableProfile('OHUB.ChargePointStatus', VARIABLETYPE_INTEGER);
            $captions = [
                0 => 'Verfügbar', 1 => 'Wird vorbereitet', 2 => 'Lädt',
                3 => 'Pausiert (Wallbox)', 4 => 'Pausiert (Fahrzeug)',
                5 => 'Wird abgeschlossen', 6 => 'Reserviert',
                7 => 'Nicht verfügbar', 8 => 'Störung',
            ];
            foreach ($captions as $code => $caption) {
                IPS_SetVariableProfileAssociation('OHUB.ChargePointStatus', $code, $caption, '', -1);
            }
        }
        // Live-Fund 31.08.2026 (Dashboard-Rückmeldung): das geteilte
        // "NRG.Ampere" ist auf Dietmars System ein FLOAT-Profil mit 1
        // Nachkommastelle (von einem anderen Modul zuerst angelegt, z. B.
        // für echte, tatsächlich fraktionale Ladestrom-Messwerte) — für ein
        // GANZZAHLIGES Sollwert-Limit wie ctl_curr_limit ergibt "10.0 A"
        // aber keinen Sinn (weder Wallbox noch Fahrzeug kennen Zehntel-
        // Ampere-Grenzen). Eigenes Profil statt das geteilte umzudeuten
        // (Verbund-Regel 8: gemeinsame Profile nie überschreiben).
        if (!IPS_VariableProfileExists('OHUB.AmpereLimit')) {
            IPS_CreateVariableProfile('OHUB.AmpereLimit', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('OHUB.AmpereLimit', '', ' A');
            IPS_SetVariableProfileValues('OHUB.AmpereLimit', 0, 63, 1);
        }
        // Analog ChargerHubs CHB.Connected (dortiges Vorbild, siehe
        // .docs/architektur.md „Instanzmodell") — ohne eigenes Profil zeigt
        // eine profil-lose Boolean-Variable Symcons Default "Ein"/"Aus",
        // das für "ist ein Fahrzeug angesteckt" nicht passt.
        if (!IPS_VariableProfileExists('OHUB.Connected')) {
            IPS_CreateVariableProfile('OHUB.Connected', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileAssociation('OHUB.Connected', false, 'Nein', '', -1);
            IPS_SetVariableProfileAssociation('OHUB.Connected', true, 'Ja', '', -1);
        }
    }

    private function RegisterVariables(): void
    {
        $this->MaintainVariable('power', 'Ladeleistung', VARIABLETYPE_FLOAT, 'NRG.Watt', 0, true);
        $this->MaintainVariable('energy_total', 'Energie gesamt', VARIABLETYPE_FLOAT, 'NRG.kWh', 10, true);
        $this->MaintainVariable('energy_session', 'Energie dieser Ladung', VARIABLETYPE_FLOAT, 'OHUB.kWhSession', 20, true);
        $this->MaintainVariable('state', 'Status', VARIABLETYPE_INTEGER, 'OHUB.ChargePointStatus', 30, true);
        $this->MaintainVariable('vehicle_plugged', 'Fahrzeug angesteckt', VARIABLETYPE_BOOLEAN, 'OHUB.Connected', 40, true);
        $this->MaintainVariable('vehicle_name', 'Zugeordnetes Fahrzeug', VARIABLETYPE_STRING, '', 50, true);
        // Nur befüllt, wenn die Wallbox den OCPP-Measurand „SoC" tatsächlich
        // überträgt (nicht jede tut das) — siehe UpdateMeterValues().
        $this->MaintainVariable('vehicle_soc', 'Fahrzeug-Ladestand (SoC, falls von der Wallbox übertragen)', VARIABLETYPE_FLOAT, 'NRG.Percent', 55, true);

        $this->MaintainVariable('ctl_enable', 'Ladefreigabe', VARIABLETYPE_BOOLEAN, '', 60, true);
        $this->MaintainVariable('ctl_curr_limit', 'Stromlimit', VARIABLETYPE_INTEGER, 'OHUB.AmpereLimit', 70, true);
        $this->EnableAction('ctl_enable');
        $this->EnableAction('ctl_curr_limit');

        if ($this->ReadPropertyBoolean('EnableSurplusCharging')) {
            $this->MaintainVariable('surplus_status', 'Überschussladen', VARIABLETYPE_STRING, '', 80, true);
        } else {
            $this->MaintainVariable('surplus_status', 'Überschussladen', VARIABLETYPE_STRING, '', 80, false);
        }

        // Reservierung (Stufe 2) — siehe Reserve()/CancelReservation().
        $this->MaintainVariable('reserved_by', 'Reserviert für', VARIABLETYPE_STRING, '', 90, true);
        $this->MaintainVariable('reserved_until', 'Reserviert bis', VARIABLETYPE_STRING, '', 100, true);

        // Diagnose-Feature 31.08.2026 (Ladeablehnung erklären, siehe
        // DiagnoseBlockReason()) — leer im Normalfall, nur befüllt nach
        // einer eindeutigen Ablehnung von RemoteStartTransaction/
        // SetChargingProfile durch die Wallbox.
        $this->MaintainVariable('block_reason', 'Möglicher Grund für Ladeablehnung', VARIABLETYPE_STRING, '', 110, true);
    }

    // Property zuerst, Objektbaum-Position nur als Rückfall (siehe FIX-
    // Kommentar in Create()).
    private function resolveSplitterId(): int
    {
        $explicit = $this->ReadPropertyInteger('SplitterID');
        if ($explicit > 0) {
            return $explicit;
        }
        return (int)(@IPS_GetParent($this->InstanceID) ?: 0);
    }

    public function RequestAction($Ident, $Value)
    {
        $splitterId = $this->resolveSplitterId();
        $cpid = $this->ReadPropertyString('CPID');

        switch ($Ident) {
            case 'ctl_enable':
                $this->SetValue($Ident, (bool)$Value);
                if ($splitterId > 0 && $cpid !== '') {
                    if ($Value) {
                        // Live-Fund 01.09.2026 (Dietmar klickte wiederholt den
                        // Schalter, ohne dass sichtbar war, dass nichts
                        // startet): unter Betriebsart ② wird der interne
                        // Platzhalter 'symcon' zu Recht als "Invalid"
                        // abgelehnt (keine echte, registrierte Karte) — der
                        // Schalter zeigte trotzdem optimistisch "an". Jetzt
                        // OHUB_ManualStart(): versucht zuerst das bereits
                        // erkannte Fahrzeug über dessen ECHTEN, registrierten
                        // Zugang zu autorisieren (dieselbe Prüfung wie eine
                        // echte Kartenauflage), fällt nur ohne bekanntes
                        // Fahrzeug oder bei Betriebsart ① auf 'symcon'
                        // zurück. Bei Fehlschlag meldet der Splitter über
                        // ReportBlockedStart() den genauen Grund zurück UND
                        // setzt ctl_enable wieder auf false.
                        OHUB_ManualStart($splitterId, $cpid);
                    } else {
                        OHUB_RemoteStop($splitterId, $cpid, $this->ReadAttributeInteger('LastTransactionId'));
                    }
                }
                break;

            case 'ctl_curr_limit':
                $clamped = max(
                    $this->ReadPropertyInteger('MinCurrent'),
                    min($this->ReadPropertyInteger('MaxCurrent'), (int)$Value)
                );
                $this->SetValue($Ident, $clamped);
                if ($splitterId > 0 && $cpid !== '') {
                    OHUB_SetCurrentLimit($splitterId, $cpid, (float)$clamped);
                }
                break;
        }
    }

    // ---------------------------------------------------------------------
    // Von OCPPHubSplitter aufgerufene Rückkanal-Funktionen (OHUBL_*).
    // ---------------------------------------------------------------------

    public function UpdateBootInfo(string $vendor, string $model, string $serial): void
    {
        // Stufe 1: nur geloggt, keine eigenen Variablen dafür — Vendor/
        // Model/Serial sind Diagnoseinfo, kein Regel-relevanter Wert.
        $this->SendDebug('OCPPHub Boot', "$vendor / $model / $serial", 0);
    }

    public function UpdateStatus(string $ocppStatus, string $errorCode): void
    {
        if ($errorCode !== '' && $errorCode !== 'NoError') {
            IPS_LogMessage('OCPPHub', 'Ladepunkt ' . $this->InstanceID . ' meldet Fehler: ' . $errorCode);
        }
        // FIX 30.08.2026: vehicle_plugged/state nur bei ERKANNTEM Status
        // setzen — vorher wurde bei einem unbekannten OCPP-Status-String
        // fälschlich vehicle_plugged=false gesetzt (in_array liefert dann
        // false, was hier aber "kein Fahrzeug" statt "unbekannt" bedeutet
        // hätte). Auto-Löschung der Fahrzeugzuordnung beim Abstecken —
        // ChargerHub-Konvention (Gegenprüfung 30.08.2026): nur bei WIRKLICH
        // erkanntem false, nicht bei unbekanntem Zustand.
        if (isset(self::STATUS_MAP[$ocppStatus])) {
            $this->SetValue('state', self::STATUS_MAP[$ocppStatus]);
            $plugged = in_array($ocppStatus, ['Preparing', 'Charging', 'SuspendedEVSE', 'SuspendedEV', 'Finishing'], true);
            $this->SetValue('vehicle_plugged', $plugged);
            if (!$plugged) {
                $this->SetValue('vehicle_name', '');
                $this->WriteAttributeInteger('LastVehicleTessieId', 0);
                $this->SetValue('block_reason', '');
            }
            // Live-Fund 01.09.2026 (Dietmar): `power` blieb nach einem
            // Stopp einfach auf dem letzten Wert stehen (z. B. „10760 W"
            // dauerhaft), weil eine `MeterValues`-Nachricht nur WÄHREND
            // aktiven Ladens gesendet wird — ohne neue Messung gibt es
            // nichts, das den alten Wert korrigiert. Nur „Charging" liefert
            // echte Leistung; jeder andere Status bedeutet zuverlässig
            // 0 W, unabhängig davon, ob/wann die nächste MeterValues-
            // Nachricht kommt.
            if ($ocppStatus !== 'Charging') {
                $this->SetValue('power', 0);
            }
        }
    }

    // Fahrzeug-Zuordnung — dummer Setter, KEINE eigene Korrelationslogik
    // (Verbund-Entscheidung, siehe .docs/architektur.md „Fahrzeug-Zuordnung
    // & SOC"): die eigentliche Zeitkorrelation macht ausschließlich
    // Dashboards AssignVehicles(). Analog ChargerHubs CHUB_SetVehicleName().
    //
    // $TimeCorrelated (additiv 31.08.2026, "so etwas wie Autocharge", mit
    // Dashboard abgestimmt): true nur, wenn Dashboard das Fahrzeug per
    // ECHTER Zeitkorrelation erkannt hat (nicht bei deren Ein-Wallbox/Ein-
    // Fahrzeug-Blindzuordnungs-Sonderfall) — löst dann bei uns eine
    // automatische Autorisierung aus (siehe unten). KEIN Standardwert
    // (Symcons generierte globale Funktion ignoriert PHP-Standardwerte
    // ohnehin, siehe RemoteStart()-Kommentar im Splitter) — unser eigener
    // Aufruf in OCPPHubSplitter::checkIdTagInternal() übergibt bewusst
    // `false` (dort läuft schon eine echte Autorisierung, keine zusätzliche
    // Auto-Autorisierung nötig/gewünscht).
    public function SetVehicleName(string $name, bool $TimeCorrelated): void
    {
        $this->SetValue('vehicle_name', $name);
        $this->maybeAutoAuthorize($name, $TimeCorrelated);
    }

    // Dashboards SetVehicleName()-Aufruf ist KEIN Einmal-Ereignis, sondern
    // wiederholt sich bei jedem weiteren buildPayload()-Lauf (laut Dashboard
    // z. B. bei jedem Leistungs-/SoC-Update oder spätestens ihrem 5-Minuten-
    // Timer), solange die Zuordnung besteht — darum hier eine 60s-Sperrfrist
    // gegen wiederholte Versuche/Log-Spam, zusätzlich zum ctl_enable-Guard
    // (schon autorisiert → nichts zu tun).
    private const AUTO_AUTH_COOLDOWN_SECONDS = 60;

    private function maybeAutoAuthorize(string $name, bool $timeCorrelated): void
    {
        if (!$timeCorrelated || $name === '' || $this->GetValue('ctl_enable')) {
            return;
        }
        if (time() - $this->ReadAttributeInteger('LastAutoAuthAttempt') < self::AUTO_AUTH_COOLDOWN_SECONDS) {
            return;
        }
        $this->WriteAttributeInteger('LastAutoAuthAttempt', time());
        $splitterId = $this->resolveSplitterId();
        $cpid = $this->ReadPropertyString('CPID');
        if ($splitterId > 0 && $cpid !== '') {
            OHUB_AutoAuthorizeVehicle($splitterId, $cpid, $name);
        }
    }

    // Vom Splitter aufgerufen, sobald AutoAuthorizeVehicle() erfolgreich war
    // — mirrort den ctl_enable-Teil von RequestAction('ctl_enable', true),
    // aber OHNE dort nochmal OHUB_RemoteStart() mit dem generischen
    // 'symcon'-idTag auszulösen (der Splitter hat den echten idTag bereits
    // selbst verwendet, siehe AutoAuthorizeVehicle()).
    public function ConfirmAutoStart(): void
    {
        $this->SetValue('ctl_enable', true);
    }

    // Additiv (Diagnose-Feature 31.08.2026) — merkt sich, welche Tessie-
    // Instanz (falls überhaupt) zum aktuell zugeordneten Fahrzeug gehört,
    // für eine spätere DiagnoseBlockReason(). 0 = kein Tessie-verknüpftes
    // Fahrzeug.
    public function SetVehicleTessieId(int $TessieInstanceId): void
    {
        $this->WriteAttributeInteger('LastVehicleTessieId', $TessieInstanceId);
    }

    // Diagnose-Feature 31.08.2026 ("Ladeablehnung erklären", siehe
    // .docs/architektur.md und OCPPHubSplitter::handleCall() CALLRESULT/
    // CALLERROR) — wird vom Splitter aufgerufen, wenn go-e ein
    // RemoteStartTransaction/SetChargingProfile eindeutig ablehnt. Fragt das
    // verknüpfte Tessie-Fahrzeug (falls vorhanden) und Tibber Grid Rewards
    // (nur als unsicherer Namensabgleich, siehe Tibber-Rückmeldung
    // 31.08.2026: deviceId ist Tibbers eigene ID, keine Symcon-Instanz-ID)
    // nach einer möglichen Erklärung. TESSIE_*/TIBBERGR_* sind ECHTE
    // Fremdmodule (anders als OHUBA_/OHUBL_) — beide Aufrufe darum hinter
    // function_exists() abgesichert (Verbund-Regel 1).
    public function DiagnoseBlockReason(): void
    {
        $reason = $this->diagnoseFromTessie();
        if ($reason === '') {
            $reason = $this->diagnoseFromTibber();
        }
        $this->SetValue('block_reason', $reason);
    }

    // Vom Splitter aufgerufen, sobald derselbe Aufruftyp (RemoteStart/
    // SetChargingProfile) doch angenommen wird — eine zuvor gesetzte
    // Begründung wäre sonst veraltet und stünde fälschlich weiter im
    // Dashboard.
    public function ClearBlockReason(): void
    {
        $this->SetValue('block_reason', '');
    }

    // Für OHUB_ManualStart() im Splitter — braucht das aktuell erkannte
    // Fahrzeug, um bei einem manuellen Klick dessen ECHTEN, registrierten
    // Zugang zu versuchen statt des Platzhalters 'symcon' (Live-Fund
    // 01.09.2026, siehe RequestAction()).
    public function GetVehicleName(): string
    {
        return (string)$this->GetValue('vehicle_name');
    }

    // Vom Splitter bei jeder eingehenden Nachricht dieser Wallbox
    // durchgereicht (siehe OCPPHubSplitter::forwardSourceIp()) — Grundlage
    // für die Cross-Hub-Warnung in GetConfigurationForm().
    public function SetSourceIP(string $IP): void
    {
        if ($IP !== '' && $IP !== $this->ReadAttributeString('SourceIP')) {
            $this->WriteAttributeString('SourceIP', $IP);
        }
    }

    // Cross-Hub-Erkennung (Live-Fund 01.09.2026, siehe .docs/architektur.md
    // „Ladeablehnung erklären"): ChargerHub (Modbus) und OCPPHub (OCPP)
    // hatten sich an derselben physischen Wallbox gegenseitig blockiert —
    // OCPP-Ebene meldete überall korrekt „Accepted", aber ChargerHubs
    // eigenes Ladefreigabe-Register verhinderte die tatsächliche Ladung,
    // ohne jede sichtbare Fehlermeldung auf beiden Seiten. Reine Heuristik
    // (Text-Abgleich unserer beobachteten Quell-IP gegen ChargerHubs
    // konfigurierte „Host"-Property) — erkennt KEINEN Konflikt, wenn
    // ChargerHub per Hostname statt IP konfiguriert ist, ist aber besser
    // als gar keine Warnung. `IPS_GetProperty()` ist für Properties
    // (anders als Attribute) generisch cross-instance lesbar, kein eigener
    // ChargerHub-Vertrag nötig.
    private function findConflictingChargerHubInstance(): int
    {
        $ip = $this->ReadAttributeString('SourceIP');
        if ($ip === '') {
            return 0;
        }
        foreach (@IPS_GetInstanceListByModuleID(self::CHARGERHUB_GUID) ?: [] as $id) {
            if ((string)@IPS_GetProperty($id, 'Host') === $ip) {
                return $id;
            }
        }
        return 0;
    }

    // Gegenstück zu ConfirmAutoStart()/ClearBlockReason() für den Fall, dass
    // eine Autorisierung (egal ob echte Kartenauflage, Auto-Autorisierung
    // oder manueller Klick) definitiv scheitert und der Splitter den Grund
    // bereits genau kennt (kein Rätselraten wie bei DiagnoseBlockReason() —
    // unsere eigene Zugänge-Prüfung sagt ja bereits exakt, woran es liegt).
    // Setzt ctl_enable zurück auf false: RequestAction() setzt es beim Klick
    // OPTIMISTISCH auf true, bevor die eigentliche (asynchrone)
    // Autorisierung zurückkommt — ohne diesen Reset zeigte der Schalter
    // weiterhin "an", obwohl nichts gestartet ist (Live-Fund 01.09.2026,
    // Dietmar klickte wiederholt, ohne dass der Fehlschlag sichtbar war).
    public function ReportBlockedStart(string $Reason): void
    {
        $this->SetValue('ctl_enable', false);
        $this->SetValue('block_reason', $Reason);
    }

    private function diagnoseFromTessie(): string
    {
        $tessieId = $this->ReadAttributeInteger('LastVehicleTessieId');
        if ($tessieId <= 0 || !@IPS_InstanceExists($tessieId) || !function_exists('TESSIE_GetVehicleState')) {
            return '';
        }

        // Telemetrie veraltet (>15 Min, Status 203 laut Tessie-Rückmeldung
        // 31.08.2026) — typisches Muster für ein schlafendes Fahrzeug, das
        // gerade nicht mit der Wallbox verhandelt. Aufwecken anstoßen
        // (asynchron, Tesla braucht oft >30s — hier NICHT block-wartend,
        // das würde die OCPP-Nachrichtenverarbeitung aufhalten), aber die
        // Werte aus diesem Aufruf nicht mehr als sichere Begründung werten.
        $status = @IPS_GetInstance($tessieId)['InstanceStatus'] ?? 0;
        if ($status === 203) {
            if (function_exists('TESSIE_WakeUp')) {
                @TESSIE_WakeUp($tessieId);
            }
            return 'Fahrzeug antwortet gerade nicht (evtl. im Ruhemodus) — Aufwecken angestoßen, bitte in Kürze erneut versuchen.';
        }

        $state = json_decode((string)@TESSIE_GetVehicleState($tessieId), true);
        if (!is_array($state)) {
            return '';
        }
        if (($state['scheduledChargingActive'] ?? null) === true) {
            return 'Fahrzeug hat eine eigene Ladeplanung aktiv (geplante Abfahrtszeit).';
        }
        if (isset($state['soc'], $state['chargeLimit']) && (float)$state['soc'] >= (float)$state['chargeLimit']) {
            return 'Ladelimit im Fahrzeug ist bereits erreicht.';
        }
        return '';
    }

    // Nur ein unsicherer Namensabgleich (siehe Tibber-Rückmeldung
    // 31.08.2026: GetActiveControls() liefert Tibbers eigene deviceId, keine
    // Symcon-Instanz-ID — ohne manuellen Abgleich nicht zuverlässig einem
    // Fahrzeug zuordenbar) UND laut Tibber strukturell unwahrscheinlich als
    // Grund für eine Ablehnung VOR Sitzungsbeginn (sie greifen erst nach
    // Sitzungsstart auf Fahrzeugseite ein) — deshalb bewusst nur als
    // "möglicherweise" markiert, nie als sichere Aussage.
    private function diagnoseFromTibber(): string
    {
        if (!function_exists('TIBBERGR_GetActiveControls')) {
            return '';
        }
        $vehicleName = (string)$this->GetValue('vehicle_name');
        if ($vehicleName === '') {
            return '';
        }
        // Live-Fund 01.09.2026 (echter Ladeversuch, Dietmars Auto):
        // TIBBERGR_GetActiveControls() ohne InstanceID aufgerufen —
        // ArgumentCountError, DiagnoseBlockReason() brach deshalb komplett
        // ab (auch der schon fertige Tessie-Teil blieb ungenutzt). Jede vom
        // Kernel generierte globale Wrapper-Funktion braucht die
        // Instanz-ID als ERSTES Argument, unabhängig von der PHP-Signatur
        // der Methode selbst (dieselbe Lehre wie beim RemoteStart()-Fund,
        // hier schlicht komplett vergessen). Erste gefundene Tibber-
        // Instanz reicht — bei mehreren wird nur eine sinnvoll bedient,
        // wie schon bei buildTessieOptions() in Abrechnung.
        $tibberIds = @IPS_GetInstanceListByModuleID(self::TIBBER_GUID) ?: [];
        if ($tibberIds === []) {
            return '';
        }
        $controls = @TIBBERGR_GetActiveControls($tibberIds[0]);
        if (!is_array($controls)) {
            return '';
        }
        foreach ($controls as $control) {
            $name = (string)($control['name'] ?? '');
            if ($name !== '' && stripos($vehicleName, $name) !== false) {
                $reason = (string)($control['reason'] ?? 'kein Grund angegeben');
                return 'Möglicherweise (nicht sicher zuordenbar): Tibber Grid Rewards ist für dieses Fahrzeug aktiv — ' . $reason;
            }
        }
        return '';
    }

    public function StartTransaction(int $transactionId, string $idTag, int $meterStartWh): void
    {
        $this->WriteAttributeInteger('LastTransactionId', $transactionId);
        $this->WriteAttributeInteger('MeterStartWh', $meterStartWh);
        $this->WriteAttributeString('LastIdTag', $idTag);
        $this->SetValue('energy_session', 0.0);
        $this->SendDebug('OCPPHub StartTransaction', "id=$transactionId idTag=$idTag meterStart=$meterStartWh", 0);
    }

    // Für den Splitter: Rückfall-idTag, falls StopTransaction.req es nicht
    // mitschickt (laut Spezifikation optional).
    public function GetLastIdTag(): string
    {
        return $this->ReadAttributeString('LastIdTag');
    }

    // Für den Splitter: Meterstand zu Sitzungsbeginn, für die Verbrauchs-
    // Gutschrift bei StopTransaction (siehe OHUB_onStopTransaction).
    public function GetMeterStartWh(): int
    {
        return $this->ReadAttributeInteger('MeterStartWh');
    }

    public function StopTransaction(int $transactionId, int $meterStopWh): void
    {
        $meterStart = $this->ReadAttributeInteger('MeterStartWh');
        if ($meterStopWh > $meterStart) {
            $this->SetValue('energy_session', ($meterStopWh - $meterStart) / 1000.0);
        }
        $this->SetValue('ctl_enable', false);
        $this->SendDebug('OCPPHub StopTransaction', "id=$transactionId meterStop=$meterStopWh", 0);
    }

    // $socPercent: OCPP-1.6-Measurand „SoC" aus MeterValues — NICHT jede
    // Wallbox/jedes Fahrzeug liefert das (eher DC-Laden/ISO 15118). Bewusst
    // NOCH NICHT im OHUB_GetFunctions-Vertrag als vehicleSocID (ChargerHub-
    // Empfehlung 30.08.2026: additives Feld erst nach Bestätigung, dass
    // reale Hardware das liefert, UND Abstimmung mit der EMS-Sitzung) — bis
    // dahin nur lokal sichtbare Variable, kein Vertragsbestandteil.
    public function UpdateMeterValues(?float $powerW, ?float $energyWh, ?float $socPercent = null): void
    {
        // NaN/Inf-Wache (ChargerHub-Empfehlung 30.08.2026, dort für Modbus-
        // Füllwerte, bei uns für kaputte MeterValues-Payloads relevant).
        if ($powerW !== null && !is_finite($powerW)) {
            $powerW = null;
        }
        if ($energyWh !== null && !is_finite($energyWh)) {
            $energyWh = null;
        }
        if ($socPercent !== null && (!is_finite($socPercent) || $socPercent < 0 || $socPercent > 100)) {
            $socPercent = null;
        }
        if ($socPercent !== null) {
            $this->SetValue('vehicle_soc', $socPercent);
        }

        if ($powerW !== null) {
            $this->SetValue('power', $powerW);
            // Zeitstempel für die Frische-Wache in Update() — ChargerHub-
            // Empfehlung: eigene Ladeleistung nur nutzen, wenn sie nicht zu
            // alt ist (bei uns der Normalfall bei Verbindungsproblemen,
            // anders als bei ChargerHubs synchronem Modbus-Poll).
            $this->WriteAttributeInteger('LastMeterValuesAt', time());
        }
        if ($energyWh !== null) {
            $this->SetValue('energy_total', $energyWh / 1000.0);
            $meterStart = $this->ReadAttributeInteger('MeterStartWh');
            if ($meterStart > 0 && $energyWh > $meterStart) {
                $this->SetValue('energy_session', ($energyWh - $meterStart) / 1000.0);
            }
        }

        // Ereignisgetrieben nachregeln (ChargerHub-Empfehlung): der Timer
        // bleibt nur Fallback/Watchdog, die eigentliche Reaktion auf frische
        // Messwerte soll nicht auf den nächsten Timer-Tick warten.
        if ($powerW !== null) {
            $this->Update();
        }
    }

    public function GetLastTransactionId(): int
    {
        return $this->ReadAttributeInteger('LastTransactionId');
    }

    // Für den Splitter, um vor einem automatischen Reset-/frc-Ausweichweg
    // zu prüfen, ob tatsächlich schon geladen wird (Live-Fund 01.09.2026:
    // ein abgelehntes RemoteStartTransaction kann auch bedeuten, dass die
    // Wallbox bereits eine ANDERE, z. B. lokal selbst gestartete Sitzung
    // fährt — ein Reset würde die dann grundlos unterbrechen).
    public function GetState(): int
    {
        return (int)$this->GetValue('state');
    }

    // ---------------------------------------------------------------------
    // Backend-Funktionen für Dashboard (Scope-Korrektur 30.08.2026: OCPPHub
    // baut KEINE eigene WebFront-Kachel — Dashboard konsumiert diese
    // Funktionen für die eigentliche Bedienoberfläche, siehe
    // .docs/architektur.md „Bedienung: Backend-Funktion für Dashboard").
    // Bewusst hier auf OCPPHubLadepunkt statt auf dem Splitter — Dashboard
    // braucht dafür nur die eine Ladepunkt-Instanz-ID, wie mit der
    // Dashboard-Sitzung abgestimmt (30.08.2026), keine zusätzliche
    // Splitter-ID-Auflösung.
    // ---------------------------------------------------------------------

    // KEIN Standardwert auf $ZugangID (siehe RemoteStart()-Kommentar im Splitter:
    // Symcons generierte globale Funktion ignoriert PHP-Standardwerte auf
    // Instanzmethoden-Parametern, jeder Aufrufer muss ihn explizit mitgeben).
    // $ZugangID selbst wird aktuell noch nicht ausgewertet (keine idTag-Auflösung
    // über die Abrechnung-Instanz) — derselbe Weg wie ein Klick auf ctl_enable,
    // löst intern RemoteStartTransaction über den Splitter aus (idTag 'symcon').
    public function ManualStart(int $ZugangID): void
    {
        IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
    }

    public function ManualStop(): void
    {
        IPS_RequestAction($this->InstanceID, 'ctl_enable', false);
    }

    // Tages-Override „heute Vollladen trotz PV-Vorrang" — siehe
    // Update()-Kommentar unten für den automatischen Reset.
    public function SetDailyOverride(bool $Active): void
    {
        $this->WriteAttributeBoolean('DailyOverride', $Active);
        $this->WriteAttributeString('DailyOverrideDate', $Active ? date('Y-m-d') : '');
    }

    // ---------------------------------------------------------------------
    // Reservierung (Stufe 2, siehe .docs/architektur.md „Reservierung").
    // Nur ab Betriebsart ② am Splitter sinnvoll, technisch aber unabhängig
    // durchsetzbar — Splitter prüft die Reservierung VOR der Betriebsart-
    // abhängigen Autorisierung (siehe OCPPHubSplitter::checkIdTag()).
    // ---------------------------------------------------------------------

    // $UntilIso: z. B. "2026-08-30 18:00". Vereinfachung Stufe 2 (noch
    // nicht über die Kundenverwaltung aufgelöst): `reserved_by` zeigt den
    // idTag selbst, keinen Kundennamen — spätere Verfeinerung möglich, ohne
    // den Vertrag zu brechen (rein interne Darstellung).
    public function Reserve(string $IdTag, string $UntilIso): bool
    {
        $untilTs = strtotime($UntilIso);
        if ($IdTag === '' || $untilTs === false || $untilTs <= time()) {
            return false;
        }
        $splitterId = $this->resolveSplitterId();
        $cpid = $this->ReadPropertyString('CPID');
        if ($splitterId <= 0 || $cpid === '') {
            return false;
        }
        $reservationId = OHUB_ReserveNow($splitterId, $cpid, $IdTag, $UntilIso);

        $this->WriteAttributeString('ReservedIdTag', $IdTag);
        $this->WriteAttributeInteger('ReservedUntilTs', $untilTs);
        $this->WriteAttributeInteger('ReservationId', $reservationId);
        $this->SetValue('reserved_by', $IdTag);
        $this->SetValue('reserved_until', date('Y-m-d H:i', $untilTs));
        return true;
    }

    public function CancelReservation(): void
    {
        $splitterId = $this->resolveSplitterId();
        $cpid = $this->ReadPropertyString('CPID');
        if ($splitterId > 0 && $cpid !== '') {
            OHUB_CancelReservation($splitterId, $cpid, $this->ReadAttributeInteger('ReservationId'));
        }
        $this->clearReservation();
    }

    // Für den Splitter (checkIdTag()): liefert den berechtigten idTag,
    // solange die Reservierung noch läuft, sonst leer — räumt eine
    // abgelaufene Reservierung dabei gleich mit auf (Anzeige-Variablen
    // sonst bis zum nächsten Update()-Zyklus veraltet, siehe TODO in
    // architektur.md „Vermerkte, noch nicht vertiefte Punkte").
    public function GetActiveReservationIdTag(): string
    {
        $until = $this->ReadAttributeInteger('ReservedUntilTs');
        if ($until === 0) {
            return '';
        }
        if ($until < time()) {
            $this->clearReservation();
            return '';
        }
        return $this->ReadAttributeString('ReservedIdTag');
    }

    private function clearReservation(): void
    {
        $this->WriteAttributeString('ReservedIdTag', '');
        $this->WriteAttributeInteger('ReservedUntilTs', 0);
        $this->WriteAttributeInteger('ReservationId', 0);
        $this->SetValue('reserved_by', '');
        $this->SetValue('reserved_until', '');
    }

    // Verbund-Vertrag — ein Eintrag, feldgleich zu CHUB_GetFunctions 1.2
    // (siehe .docs/architektur.md „Vertrag OHUB_GetFunctions"), additiv
    // transport/ocppVersion. NOCH NICHT final mit der EMS-Sitzung
    // gegenprogrammiert.
    public function GetContractEntry(): array
    {
        $managedBy = $this->ReadPropertyString('ManagedBy');
        return [
            'contractVersion'   => '1.2',
            // 1.1 (Dashboard-Fund 30.08.2026): Splitter sammelt die Einträge
            // ALLER eigenen Ladepunkte über OHUB_GetFunctions() ein — anders
            // als bei ChargerHub (1 Instanz = 1 Wallbox) reicht die
            // Splitter-ID als instanceID für Konsumenten NICHT, jeder Eintrag
            // braucht seine EIGENE (Ladepunkt-)Instanz-ID für Steuerungs-
            // aufrufe wie OHUBL_ManualStart(). Additiv, kein Bruch.
            // 1.2 (Diagnose-Feature 31.08.2026): blockReasonID additiv.
            'instanceID'        => $this->InstanceID,
            'function'          => 'charger',
            'label'             => $this->ReadPropertyString('Label') ?: IPS_GetName($this->InstanceID),
            'powerID'           => $this->GetIDForIdent('power'),
            'energyImportID'    => $this->GetIDForIdent('energy_total'),
            'measured'          => true,
            'chargeEnableID'    => $this->GetIDForIdent('ctl_enable'),
            'currentLimitID'    => $this->GetIDForIdent('ctl_curr_limit'),
            'plugStateID'       => $this->GetIDForIdent('vehicle_plugged'),
            'minCurrent'        => $this->ReadPropertyInteger('MinCurrent'),
            'maxCurrent'        => $this->ReadPropertyInteger('MaxCurrent'),
            'managedBy'         => $managedBy,
            'externallyManaged' => !in_array($managedBy, ['none', 'ems'], true),
            'vehicleNameID'     => $this->GetIDForIdent('vehicle_name'),
            'transport'         => 'ocpp',
            'ocppVersion'       => '1.6',
            'blockReasonID'     => $this->GetIDForIdent('block_reason'),
        ];
    }

    // ---------------------------------------------------------------------
    // PV-Überschussladen (Fallback ohne EMS) — Logik aus ChargerHub
    // SurplusChargeControl() 0.9.53 portiert, transportunabhängig. Stufe 1:
    // nur EIN Ladepunkt gleichzeitig unterstellt — Splitter-interne
    // Lastverteilung bei mehreren eigenen aktiven Ladepunkten ist TODO
    // (siehe .docs/architektur.md), ebenso Phasenumschaltung.
    // ---------------------------------------------------------------------

    public function Update(): void
    {
        // Boot-Timing-Wache (ChargerHub-Fund 31.08.2026, Commit e32ed18, bei
        // uns proaktiv übernommen): SurplusTimer kann beim Systemstart feuern,
        // bevor der Kernel alle Instanzen fertig angebunden hat — jeder
        // Property-Zugriff wirft dann kurzzeitig "InstanceInterface is not
        // available". Timer feuert kurz danach ohnehin erneut, kein Datenverlust.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        if (!$this->ReadPropertyBoolean('EnableSurplusCharging')) {
            return;
        }

        $managedBy = $this->ReadPropertyString('ManagedBy');
        if ($managedBy !== 'none') {
            $this->SetValue('surplus_status', 'Passiv — Regelungshoheit bei: ' . self::MANAGEDBY_LABELS[$managedBy]);
            return;
        }
        if ($this->IsEmsActive()) {
            $this->SetValue('surplus_status', 'Passiv — EMS aktiv');
            return;
        }
        if (!$this->GetValue('vehicle_plugged')) {
            $this->SetValue('surplus_status', 'Kein Fahrzeug angesteckt');
            return;
        }

        // Tages-Override (Dashboard-Backend-Funktion OHUB_SetDailyOverride):
        // ignoriert NUR die Überschussberechnung unten, NICHT die
        // Vorrangkaskade/managedBy-Prüfung oben — ein Override darf nicht
        // zwei Regler gegeneinander ausspielen, siehe .docs/architektur.md
        // „Sicherheits-/Regulatorik-Vorrang". Läuft an einem neuen Kalendertag
        // automatisch ab (OCPPHub verwaltet das selbst, siehe Create()) — kein
        // Cron/Timer auf Dashboard-Seite nötig.
        if ($this->ReadAttributeBoolean('DailyOverride') && $this->ReadAttributeString('DailyOverrideDate') !== date('Y-m-d')) {
            $this->SetDailyOverride(false);
        }
        if ($this->ReadAttributeBoolean('DailyOverride')) {
            $maxCurrent = $this->ReadPropertyInteger('MaxCurrent');
            if (!$this->GetValue('ctl_enable')) {
                IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
            }
            if ($this->GetValue('ctl_curr_limit') !== $maxCurrent) {
                IPS_RequestAction($this->InstanceID, 'ctl_curr_limit', $maxCurrent);
            }
            $this->SetValue('surplus_status', 'Tages-Override aktiv — lädt mit ' . $maxCurrent . ' A');
            return;
        }

        // Frische-Wache (ChargerHub-Empfehlung 30.08.2026): bei ChargerHub
        // kommt die eigene Ladeleistung synchron aus demselben Poll-Zyklus,
        // ist also immer frisch. Bei OCPPHub kommt sie asynchron per
        // MeterValues von der Wallbox — bei Verbindungsproblemen der
        // Normalfall, dass sie fehlt/veraltet. Lieber NICHT regeln als mit
        // einem stalen Wert rechnen (das reproduziert sonst genau die
        // Selbstregelschwingung, die die Rückaddierung eigentlich verhindern
        // soll, nur mit der Meldeverzögerung der Wallbox als Periode statt
        // dem Regelintervall).
        $lastMeterValuesAt = $this->ReadAttributeInteger('LastMeterValuesAt');
        if ($lastMeterValuesAt === 0 || (time() - $lastMeterValuesAt) > self::MAX_METER_VALUES_AGE_SECONDS) {
            $this->SetValue('surplus_status', 'Messwerte veraltet — Regelung ausgesetzt');
            return;
        }

        $surplusW = $this->FindGridSurplusW();
        if ($surplusW === null) {
            $this->SetValue('surplus_status', 'Kein Netzzähler gefunden (MeterHub-Vertrag)');
            return;
        }

        // Speicheranteil vor der Ampere-Berechnung abziehen.
        $storageShare = $this->ReadPropertyInteger('StorageSharePercent') / 100.0;
        $surplusW -= $this->GetBatteryChargePowerW() * $storageShare;

        // Eigene Ladeleistung zurückaddieren — sonst Selbstregelschwingung
        // (live erlitten in ChargerHub 0.9.50, siehe architektur.md).
        $surplusW += $this->GetValue('power');

        // Phasenzahl: noch nicht aus ctl_phase_mode ablesbar (Ident existiert
        // noch nicht, Phasenumschaltung ist Stufe-2-TODO) — Default bewusst
        // 3, NICHT 1 (ChargerHub-Empfehlung, sicherheitsrelevant): wird
        // tatsächlich 3-phasig geladen und wir rechnen mit 1, käme ein zu
        // hohes Stromlimit raus → Netzbezug statt Überschussladen. Der
        // umgekehrte Fehler (3 angenommen, real 1-phasig) lädt nur
        // konservativer, nie mit Netzbezug.
        $phases = 3;
        $ampere = max(0, $surplusW) / (230 * $phases);
        $minCurrent = $this->ReadPropertyInteger('MinCurrent');
        $maxCurrent = $this->ReadPropertyInteger('MaxCurrent');

        if ($ampere < $minCurrent) {
            if ($this->GetValue('ctl_enable')) {
                IPS_RequestAction($this->InstanceID, 'ctl_enable', false);
            }
            $this->SetValue('surplus_status', sprintf('Kein ausreichender Überschuss (%.0f W)', $surplusW));
            return;
        }

        $clamped = (int)min($maxCurrent, $ampere);
        if (!$this->GetValue('ctl_enable')) {
            IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
        }
        if ($this->GetValue('ctl_curr_limit') !== $clamped) {
            IPS_RequestAction($this->InstanceID, 'ctl_curr_limit', $clamped);
        }
        $this->SetValue('surplus_status', sprintf('Lädt mit %d A (%.0f W Überschuss)', $clamped, $surplusW));
    }

    // Vorrangkaskade: EMS aktiv → passiv. Prüft eine Statusvariable
    // 'Active_State' unter der EMS-Instanz (Ident-Name wie in
    // .docs/architektur.md „Steuerung / Überschussladen" beschrieben,
    // NOCH NICHT gegen eine echte EMS-Instanz verifiziert — dort prüfen,
    // sobald EMS diese Variable tatsächlich exponiert; bis dahin liefert
    // die Funktion bei fehlender Variable sicherheitshalber false, also
    // "EMS nicht aktiv/nicht vorhanden", statt fälschlich zu blockieren).
    private function IsEmsActive(): bool
    {
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        if (!$ids) {
            return false;
        }
        $vid = @IPS_GetObjectIDByIdent('Active_State', $ids[0]);
        if (!$vid) {
            return false;
        }
        $value = @GetValue($vid);
        return is_bool($value) ? $value : (bool)$value;
    }

    // Verbund-Vertrag mit MeterHub (siehe ChargerHub, 26.08.2026 abgestimmt):
    // NIE über den Instanznamen gehen, nur über
    // MHUB_GetFunctions()['assignments'][*]['function'] === 'grid',
    // 'latency' === 'realtime'; 'authority' === 'billing' nur als
    // Tiebreaker. Vorzeichen: + = Bezug, − = Einspeisung → Überschuss =
    // max(0, -wert).
    private function FindGridSurplusW(): ?float
    {
        if (!function_exists('MHUB_GetFunctions')) {
            return null;
        }
        $forcedID = $this->ReadPropertyInteger('SurplusMeterID');
        $candidates = $forcedID > 0 ? [$forcedID] : (@IPS_GetInstanceListByModuleID(self::METERHUB_GUID) ?: []);

        $best = null;
        foreach ($candidates as $iid) {
            $fns = json_decode((string)@MHUB_GetFunctions($iid), true);
            if (!is_array($fns) || !isset($fns['assignments']) || !is_array($fns['assignments'])) {
                continue;
            }
            foreach ($fns['assignments'] as $assignment) {
                if (!is_array($assignment) || ($assignment['function'] ?? '') !== 'grid') {
                    continue;
                }
                if (($assignment['latency'] ?? '') !== 'realtime') {
                    continue;
                }
                $powerID = (int)($assignment['powerID'] ?? 0);
                if ($powerID <= 0) {
                    continue;
                }
                $billing = ($assignment['authority'] ?? '') === 'billing';
                if ($best === null || ($billing && !$best['billing'])) {
                    $best = ['powerID' => $powerID, 'billing' => $billing];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        $value = @GetValue($best['powerID']);
        return is_numeric($value) ? max(0, -(float)$value) : null;
    }

    // Speicher-Ladeleistung über den InverterHub-Vertrag (batPowerID) —
    // negativ = Laden. Rückgabe hier positiv als "aktuelle Ladeleistung",
    // 0 wenn kein Speicher/kein Vertrag.
    private function GetBatteryChargePowerW(): float
    {
        if (!function_exists('IHUB_GetFunctions')) {
            return 0.0;
        }
        foreach (@IPS_GetInstanceListByModuleID(self::INVERTERHUB_GUID) ?: [] as $iid) {
            $fns = @IHUB_GetFunctions($iid);
            if (!is_array($fns) || !isset($fns[0]['batPowerID'])) {
                continue;
            }
            $value = @GetValue($fns[0]['batPowerID']);
            if (is_numeric($value) && $value < 0) {
                return abs((float)$value);
            }
        }
        return 0.0;
    }
}
