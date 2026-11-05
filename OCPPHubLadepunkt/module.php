<?php

// ===========================================================================
// OCPPHub Ladepunkt — eine Instanz je Wallbox/Connector, Kind der OCPPHub-
// Splitter-Instanz. Hält die sichtbaren Variablen (Ident-Vokabular bewusst
// identisch zu ChargerHub, siehe .docs/architektur.md „Instanzmodell") und
// das eigenständige PV-Überschussladen (Fallback ohne EMS, Logik aus
// ChargerHub SurplusChargeControl() 0.9.53 portiert).
//
// Aktueller Stand (14.09.2026): RFID-Autorisierung, Reservierung und das
// eigenständige PV-Überschussladen sind gebaut UND live verifiziert, siehe
// Splitter-Header. Noch offen: Phasenumschaltung (`ctl_phase_mode` noch
// nicht implementiert — pro Hersteller zu verifizieren, siehe
// architektur.md), Splitter-interne Lastverteilung bei mehreren eigenen
// gleichzeitig aktiven Ladepunkten (Stufe 3, siehe architektur.md
// „Splitter-interne Lastverteilung").
// ===========================================================================

require_once __DIR__ . '/../libs/OHUB_Text.php';

class OCPPHubLadepunkt extends IPSModule
{
    private const METERHUB_GUID     = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const INVERTERHUB_GUID  = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const EMS_GUID          = '{90286A25-E6C9-4A66-BD4E-0CFB707C2C6C}';
    private const SPLITTER_GUID     = '{81D3E328-9E12-43A9-825A-F7888530868C}';
    // Datenfluss (Symcon-Review 30.10.2026): Anfragen an den Splitter gehen per SendDataToParent,
    // seine Meldungen kommen in ReceiveData an — siehe .docs/datenfluss.md.
    private const DATA_ZUM_SPLITTER = '{6ED0496A-EDD0-4600-9549-04D18B8387F5}';
    private const TIBBER_GUID       = '{E92F62F4-88A6-4C6E-9F0D-E76C3B1C9A01}';
    private const CHARGERHUB_GUID   = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';

    private const MIN_CURRENT_HARD = 6; // A — kleinster IEC-61851-Ladestrom

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.4.0';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    // „Über dieses Modul" (14.09.2026, SUITE.md Formular-Konvention Punkt
    // 5) — siehe OCPPHubSplitter für den LICENSE-Branch-Stolperstein.
    private const LICENSE_URL = 'https://github.com/DG65/NRGOCPPHub/blob/main/LICENSE';
    // Symcon-Forum-Vorstellungs-Thread (16.09.2026, von Dietmar selbst
    // gepostet, siehe forum-ankuendigung-ocpphub.md).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-modul-nrg-stack-ocpphub-wallboxen-per-ocpp-1-6j-anbinden-mit-pv-ueberschussladen-und-kundenverwaltung/144409';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // „Was ist neu"-Banner (Verbund-Konvention, siehe SUITE.md, Referenz
    // ChargerHub) — bei jedem nutzerrelevanten Änderungs-Bump aktualisieren,
    // NICHT bei jedem library.json-Build (sonst nervt es).
    private const NEWS_VERSION = '0.4.0';
    private const NEWS_ITEMS = [
        '🌐 New: German and English. The form, the messages, the variable and profile names follow the Symcon language setting. Variables that already exist keep their names (you can rename them yourself); the labels of the module’s own profiles follow the next time you apply changes.',
        '🎛️ New: “Control” area in the form (as in ChargerHub) — set charge enable and current limit right here, with feedback (✅/⚠️) and a display of who is currently in control. Takes effect immediately, through the same path as the control variables. With another controller in control, deactivation or demo mode, nothing is sent, and the form says so.',
        '🔌 Technical: this charge point now talks to the splitter through the Symcon data flow (messages instead of direct function calls), as the store review requires. You select the splitter at the top, under the interface (“Gateway”); the “OCPPHub splitter” field in the form is gone. Existing instances are connected automatically the first time you apply changes after the update. Behavior does not change.',
        '🪟 New: “Form layout” (collapsed below the specialist panels) — for each area (Control, Current limits & control authority, Duplicate connection, PV surplus charging) you decide whether it is expanded, collapsed or hidden when opened, and at which position it sits. Hidden areas keep working, only the display is missing. Takes effect after “Apply changes” the next time you open the form; “Reset to default” restores everything.',
        'New: “🧡 About this module” at the very bottom of the form (license/donations); the feedback notice is now a panel of its own that you can hide, instead of a line of text (store convention addition).',
        'New: “👋 What is this module for?” — a new panel at the very top of the form briefly explains what this instance does and what it is good for (store convention addition, same pattern as the “What’s new” panel below it).',
        'Critical fix (live finding, Dietmar only wanted to switch off WB1): there was no visible switch to deactivate ONE single charge point — only the whole splitter could be switched off, which paralyzed all the other charge points as well. New switch “🔌 Deactivate this charge point” at the top of the form, identical to `OHUBL_SetActive(false)` (one shared state for the console AND external callers such as MeterHubVirtual).',
        'Correction to “Duplicate connection” (final decision, confirmed directly by Dietmar): `duplicateOf` affects ONLY the consumption counting, no longer the writing — an entry marked as a duplicate can still be the controller (e.g. OCPP measures at WB1 but control goes through ChargerHub). Whether this charge point may write to the wallbox is decided exclusively by “Who is in control?” (`ManagedBy`) or `OHUBL_SetActive()`. New safety net: if “Who is in control?” is set to “Nobody” here AND the instance chosen as the counting one has no external load management either, the instance shows a warning status “Two controllers on one wallbox” until this is resolved — prevents the “marked as duplicate, but forgot to settle the control authority” case.',
        'New: `OHUBL_SetActive(bool)` — backend function for MeterHubVirtual’s dual-writer detection, adds a generic on/off switch to “Duplicate connection” (without having to name a counterpart instance). Deactivated: the charge point no longer writes to the wallbox (Authorize/RemoteStart/current limit/Reset), a charge already in progress is NOT interrupted (Dietmar’s decision). In addition `deviceSerial`/`deviceIP`/`active` added to the contract (from BootNotification/source IP, previously internal only) — more reliable dual-writer detection than the mere meter reading comparison.',
        'New: 🔀 “Duplicate connection” — Dietmar’s decision (via the EMS session): if the same wallbox is also connected through another stack module (e.g. simultaneously as a ChargerHub instance), you can enter here which of the two entries counts. From then on the charge point marked as duplicate no longer writes to the wallbox (charge enable/current limit/Reset) but stays readable — stack consumers (EMS/MeterHub/Dashboard) skip it themselves when counting consumption (new contract field `duplicateOf`, contractVersion 1.3→1.4).',
        'New: `ocpp_connected` — shows whether this wallbox has sent any OCPP message within the last 15 minutes (finding from ChargerHub: an already created charge point instance had NO connection monitoring any more, neither visible nor with an age check — values could stay frozen for days without anyone noticing).',
        'New: 🎪 Demo mode — takes effect as soon as it is enabled on the associated splitter (Dietmar’s planned public demo of the whole stack). “Charge enable”/current limit then reject every real control command, the switch immediately jumps back to the actual state.',
        'Critical fix (live finding, real wallbox capture): a manual stop via “charge enable” was immediately undone again by the standalone PV surplus charging — the vehicle was still plugged in, surplus still available, the next timer tick (seconds later) simply switched on again. Until now a manual stop counted for the controller as “just off”, not as a deliberate decision. OCPPHub now remembers “stopped manually” until the next manual start OR until the vehicle is unplugged — surplus charging AND automatic vehicle authorization leave the charge alone for that long.',
        'Fix: `power` stayed at its last value after a charge ended (e.g. permanently “10760 W” although the session was over), because without active charging no new reading arrives that would have corrected it. Every status other than “Charging” now sets `power` to 0 W itself.',
        'New: cross-hub warning — if ChargerHub AND OCPPHub register at the same wallbox at the same time (detected by IP comparison), the form now warns clearly. Live finding: this can block the charge enable completely without either side showing an error message — the OCPP level reported “Accepted” but no current flowed, even the wallbox manufacturer’s app could not charge.',
        'Critical fix (live finding, first real charging attempt): the manual “charge enable” switch always sent an internal placeholder instead of a real card — under operating mode ② this was rightly rejected, but the switch still showed “on” without it being visible that nothing starts. The switch now first tries to use the real, registered access entry of the already detected vehicle (same path as the auto-authorization); on failure it jumps straight back to “off” AND shows the exact reason in `block_reason` (e.g. “Access entry is blocked.”, “Consumption limit has been reached.”) — every rejection of a card/access entry is thus visible immediately, not only a RemoteStartTransaction rejected by the charger itself without a reason.',
        'New: automatic charge enable for detected vehicles (“something like autocharge”) — if the dashboard detects a vehicle with an active access entry by time correlation, its card is automatically “presented” in operating mode ② (same check as a real card presentation). Design agreed with the dashboard.',
        'Two display fixes (dashboard finding, live test): “Current limit” showed nonsensical tenths of an ampere (e.g. “10.0 A”) — caused by the shared NRG.Ampere profile, which exists on this system as a float with 1 decimal place; ctl_curr_limit now has its own integer profile. “Vehicle plugged in” showed the Symcon default on/off of a bool variable without a profile — now its own profile with Yes/No, analogous to ChargerHub.',
        'New diagnostic: on an unambiguous charge rejection, if the vehicle is linked through Tessie, an explanation is requested automatically (own charge schedule active, charge limit reached, or vehicle currently asleep and being woken up automatically), visible in the new variable `block_reason`. In addition an uncertain Tibber Grid Rewards name comparison, explicitly marked as “possibly”.',
        'Critical fix (dashboard finding, live test): “Start charging” failed at the charge point with a PHP fatal error (ArgumentCountError) — the global function Symcon generates for RemoteStart() ignores PHP default values on parameters, and a missing third parameter made every manual charge start via dashboard/ctl_enable fail.',
        'Stage 2: reservation added — OHUBL_Reserve($idTag, $until)/OHUBL_CancelReservation(), visible in the new variables reserved_by/reserved_until. An active reservation blocks every card presentation with a different idTag, regardless of the splitter’s operating mode.',
        'RFID authorization/consumption limits (operating mode ② on the splitter) now take effect — before, every card was accepted regardless of the customer management.',
    ];

    // Frische-Wache Überschussladen (ChargerHub-Empfehlung 30.08.2026): ist
    // die letzte MeterValues-Meldung älter, wird nicht mehr geregelt. Passt
    // zum angestrebten MeterValueSampleInterval von 10s (siehe Splitter
    // onBootNotification/ChangeConfiguration) mit reichlich Marge.
    private const MAX_METER_VALUES_AGE_SECONDS = 30;

    private const MANAGEDBY_ALL = ['none', 'ems', 'goe-controller', 'tibber', 'p14a', 'marketer', 'other'];
    private const MANAGEDBY_LABELS = [
        'none'           => 'Nobody — free / manual (default)',
        'ems'            => 'Energy management (EMS)',
        'goe-controller' => 'go-e controller (surplus charging)',
        'tibber'         => 'Tibber (balancing energy / Grid Rewards)',
        'p14a'           => '§14a control (grid operator)',
        'marketer'       => 'Direct marketer',
        'other'          => 'Other external load management',
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

        // Dual-Writer-Zählung (13.09.2026, Dietmars Entscheidung über EMS,
        // Anlass: dieselbe Wallbox hängt bei ihm doppelt — hier als
        // OCPPHub-Ladepunkt UND als eigene ChargerHub-Instanz).
        // KORRIGIERT 13.09.2026 (EMS' endgültige Entscheidung, von Dietmar
        // direkt bestätigt): betrifft NUR die Verbrauchs-ZÄHLUNG, nicht die
        // Steuerung — Zählen und Schreiben sind orthogonale Fragen. Wer
        // tatsächlich an die Wallbox schreiben darf, entscheidet
        // ausschließlich `ManagedBy` (siehe isWriteBlocked() am Splitter)
        // plus `ManuallyDeactivated` unten. Ein als Duplikat markierter
        // Eintrag kann also trotzdem der Regler sein (z. B. Dietmars WB1:
        // OCPP zählt, ChargerHub regelt). Rein Nutzer-gesetzt über das
        // Formularpanel, NIE automatisch erkannt (Verbund-Regel-1-
        // Verletzung/Rollenduplikat sonst, siehe MeterHubVirtual). Gesetzt
        // bedeutet: der Eintrag bleibt in `OHUB_GetFunctions()` sichtbar
        // (trägt jetzt `duplicateOf`), Konsumenten (EMS/MeterHub/Dashboard)
        // überspringen ihn selbst bei der Zählung. Sicherheitsnetz gegen den
        // „vergessen, ManagedBy auch umzustellen"-Fall: siehe
        // refreshInstanceStatus()/hasDuplicateWriteConflict() unten
        // (SUITE.md Regel 9f).
        $this->RegisterPropertyString('DuplicateOfSource', '');
        $this->RegisterPropertyInteger('DuplicateOfInstanceID', 0);
        // Generischer Ein/Aus-Schalter (13.09.2026, MeterHubVirtual-Anfrage
        // — `OHUBL_SetActive(bool)`). MeterHubVirtual selbst ruft das laut
        // eigener Aussage NICHT auf (nutzt nur `duplicateOf`, „damit die
        // Entscheidung nur einen Ort hat") — bleibt trotzdem als generische
        // Backend-Funktion bestehen, z. B. für Wartungsfälle ohne
        // Gegenstück-Instanz. Sperrt zusammen mit externer Steuerhoheit
        // (`ManagedBy` != none/ems) über OCPPHubSplitter::isWriteBlocked() —
        // NICHT mehr zusammen mit IsDuplicate() (siehe Korrektur oben).
        // FIX 13.09.2026 (Live-Fund EMS/Dietmar): bewusst eine PROPERTY, nicht
        // nur ein internes Attribut — Dietmar wollte WB1 einzeln abschalten,
        // fand dafür in der Konsole nichts (SetActive() war nur per Backend-
        // Aufruf erreichbar) und hat stattdessen den ganzen Splitter
        // deaktiviert, was WB2 gleich mit lahmlegte. Jetzt zusätzlich als
        // sichtbarer Formular-Schalter (siehe GetConfigurationForm() unten) —
        // SetActive() persistiert denselben Wert, EIN Datenstand für Konsole
        // UND externe Aufrufe.
        $this->RegisterPropertyBoolean('Deaktiviert', false);
        // SUITE.md 3a „Formular: Aufbau“: Liste {Key, Panel, Opened, Closed, Hidden, Pos}
        $this->RegisterPropertyString('FormLayout', '[]');
        // Gerätemerkmale aus BootNotification (13.09.2026, MeterHub-Anfrage
        // für Dual-Writer-Erkennung) — vorher nur SendDebug(), nirgends
        // gespeichert. IP steckt schon in SourceIP (siehe forwardSourceIp()).
        $this->RegisterAttributeString('DeviceSerial', '');
        $this->RegisterAttributeString('DeviceModel', '');

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
        // Live-Fund 01.09.2026 (Dietmar stoppte manuell, 2s später startete
        // OCPPHub von selbst wieder — im echten Wallbox-Dump sichtbar):
        // PV-Überschussladen (Update() unten) und die Auto-Autorisierung
        // (maybeAutoAuthorize()) kannten "manuell gestoppt" nicht, sahen nur
        // ctl_enable=false + Fahrzeug noch angesteckt + Überschuss vorhanden
        // und schalteten beim nächsten Timer-Tick sofort wieder ein — ein
        // manueller Stopp hatte dadurch de facto nie eine Chance, zu wirken.
        // Gesetzt bei manuellem Stopp (RequestAction ctl_enable=false),
        // gelöscht bei manuellem Start ODER beim Abstecken (UpdateStatus()),
        // damit ein neu angestecktes Fahrzeug wieder ganz normal automatisch
        // lädt.
        $this->RegisterAttributeBoolean('ManualStopActive', false);
        // Cross-Hub-Erkennung (Live-Fund 01.09.2026) — Quell-IP der
        // eingehenden WebSocket-Verbindung, vom Splitter durchgereicht
        // (siehe SetSourceIP()/forwardSourceIp()), für einen Heuristik-
        // Abgleich gegen ChargerHubs konfigurierte Modbus-IP.
        $this->RegisterAttributeString('SourceIP', '');
        // Verfügbarkeits-Fund ChargerHub 12.09.2026 (Routine-Prüfung nach dem
        // WB2-Vorfall): eine bereits als Instanz angelegte Wallbox hatte
        // schlicht KEINE Verbindungsüberwachung mehr — rememberSeenChargePoint()
        // am Splitter trackt bewusst nur noch nicht angelegte Charge-Point-
        // Identities (für den Konfigurator), danach verliert sich jede
        // Sichtbarkeit, ob die OCPP-Verbindung überhaupt noch lebt oder die
        // Wallbox seit Tagen gar nicht mehr meldet. Splitter ruft jetzt bei
        // JEDER eingehenden Nachricht UpdateLastSeen() hier auf (siehe
        // OCPPHubSplitter::ProcessHookData()) — unabhängig vom OCPP-
        // Nachrichteninhalt, reiner Lebenszeichen-Nachweis.
        $this->RegisterAttributeInteger('LastSeenAt', 0);

        // Vertrag 1.7 (EMS-Bitte 20.09.2026): was die Wallbox selbst per
        // GetConfiguration über Stromgrenzen/Phasenumschaltung sagt.
        // 0 bzw. -1 = (noch) unbekannt, dann fehlt das Vertragsfeld.
        $this->RegisterAttributeInteger('StationMaxCurrentA', 0);
        $this->RegisterAttributeInteger('StationMinCurrentA', 0);
        $this->RegisterAttributeInteger('PhaseSwitchSupported', -1);

        $this->RegisterTimer('SurplusTimer', 0, 'OHUBL_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('EnableActionsTimer', 0, 'OHUBL_EnableActions($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ConnectivityTimer', 0, 'OHUBL_CheckConnectivity($_IPS[\'TARGET\']);');
        // Einmalig nach dem Übernehmen: Bestandsinstanzen, die bisher nur über die Property SplitterID
        // zugeordnet waren, werden mit dem Splitter verbunden (Datenfluss), siehe VerbindungPruefen().
        $this->RegisterTimer('VerbindenTimer', 0, 'OHUBL_VerbindungPruefen($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        if ($this->elternId() === 0) {
            $this->SetTimerInterval('VerbindenTimer', 2000);
        }

        $active = $this->ReadPropertyString('CPID') !== '';
        if (!$active) {
            $this->SetTimerInterval('SurplusTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            $this->SetTimerInterval('ConnectivityTimer', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval(
            'SurplusTimer',
            $this->ReadPropertyBoolean('EnableSurplusCharging') ? $this->ReadPropertyInteger('IntervalFast') * 1000 : 0
        );
        $this->SetTimerInterval('EnableActionsTimer', 200);
        // Läuft IMMER, unabhängig von EnableSurplusCharging — reine
        // Verbindungsüberwachung (siehe RegisterAttributeInteger('LastSeenAt', ...)
        // in Create()), nicht an die optionale Überschussladen-Regelung
        // gekoppelt.
        $this->SetTimerInterval('ConnectivityTimer', 60000);
        // Vorläufig — Deaktiviert-Property ist ein reiner lokaler Wert, sicher
        // auch früh im Boot lesbar. Feinere Prüfung (Duplikat-Konflikt, siehe
        // refreshInstanceStatus()) braucht ggf. andere Instanzen, deshalb erst
        // NACH dem Kernel-Ready-Check unten.
        $this->SetStatus($this->IsDeactivated() ? 104 : 102);
        // Sofortiges Feedback nach dem Speichern (nicht erst nach bis zu 60s
        // Timer-Wartezeit) — nur im laufenden Kernel-Betrieb, siehe
        // CheckConnectivity()/Update() für dasselbe Boot-Timing-Muster.
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->refreshInstanceStatus();
        }
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
                    'caption'  => OHUB_Text::t('📖 Documentation & help (version %s)', self::VERSION),
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => OHUB_Text::t('What this instance does: one “OCPPHub Charge Point” instance per wallbox/connector — it holds the visible readings and control variables (charging power, energy meter, status, charge enable, current limit), can be controlled directly from the form in the “Control” area, and optionally carries the standalone PV surplus charging. The actual OCPP communication runs entirely through the assigned splitter; this instance itself holds no WebSocket connection.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🆔 Charge point identity: must match exactly the name that the wallbox itself sends in its own OCPP configuration as the last path segment of the backend URL (case matters). The easiest way is to create it via “OCPPHub Configurator” — it shows wallboxes that are already connected and fills in this field correctly when creating, including the splitter assignment below.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🔌 Splitter: this charge point is a device below the “OCPPHub Splitter” and talks only to it through the Symcon data flow (messages instead of direct function calls). You select the splitter at the top of the form, under the interface (“Gateway”); when created via the configurator this happens by itself, existing instances are connected automatically the first time you apply changes after the update. The object tree position plays no role in this, you can move the instance freely to other categories.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('ℹ️ Scope: the measurement and control variables themselves (`ocpp_connected`, `power`, `energy_total`, `energy_session`, `state`, `vehicle_plugged`, `vehicle_name`, `vehicle_soc`, `ctl_enable`, `ctl_curr_limit`, `surplus_status`, `reserved_by`, `reserved_until`, `block_reason`) appear as child objects of this instance in the object tree. Charge enable and current limit can also be set right here in the form, in the “Control” area.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🩺 Explain charge rejection (`block_reason`): if the wallbox unambiguously rejects a charge start/a current limit change, then — if the vehicle is linked through Tessie (see the “OCPPHub Billing” instance) — it is automatically asked whether a charge schedule of its own is active in the vehicle, the charge limit has already been reached, or the vehicle is currently asleep (in which case waking it up is triggered automatically). In addition, but explicitly only as an uncertain hint: a name comparison against active Tibber Grid Rewards controls. Without a Tessie link or without an unambiguous rejection, `block_reason` stays empty.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🔓 Automatic charge enable for detected vehicles: if the dashboard detects, by its own time correlation (not by us — deliberately ONE correlation mechanism in the stack), a vehicle at this charge point that is assigned to an active access entry in the Billing instance, its card is automatically “presented” in operating mode ② (same check as a real card presentation, all limits/time windows apply identically) — practically the result of “autocharge”, without the wallbox having to support that itself. This does not force charging to start right away, it only grants the enable; it takes effect as soon as the dashboard session’s charge point module reports this assignment.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🎫 RFID authorization/consumption limits: maintained centrally in the “OCPPHub Billing” instance, but only applies if “② Multiple users” is selected on the splitter — with “① Single user” every card is accepted, regardless of what is stored there.')],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🔒 Reservation: usable regardless of the splitter’s operating mode (backend functions `OHUBL_Reserve`/`OHUBL_CancelReservation`, the dashboard builds the user interface). As long as a reservation is active, every card presentation with a DIFFERENT idTag is rejected — visible in `reserved_by`/`reserved_until`. We check this block ourselves (regardless of whether the wallbox itself supports the OCPP core command `ReserveNow`) — some models (e.g. go-e) reject `ReserveNow` with “NotImplemented”, which only affects whatever display the wallbox itself may have, not our enforcement.')],
                    ],
                ],
                $this->connectionsPanel(),
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'CPID',
                    'caption' => OHUB_Text::t('Charge point identity (from the wallbox OCPP configuration)'),
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Label',
                    'caption' => OHUB_Text::t('Display name (empty = instance name)'),
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Deaktiviert',
                    'caption' => OHUB_Text::t('🔌 Deactivate this charge point'),
                ],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Live finding 13.09.2026: the need to switch off only THIS charge point without deactivating the whole splitter (that would paralyze all the other charge points too). When enabled: the OCPP protocol is still answered normally, but Authorize/RemoteStart/current limit/Reset are refused. A charge currently in progress is NOT interrupted, only from then on there is no new authorization/control. The instance status then shows “Inactive” (deliberate, not an error) and the entry in the stack contract carries `deactivated: true`. Corresponds exactly to `OHUBL_SetActive(false)` — the same value, whether set here or from outside (e.g. MeterHubVirtual). Differs from “Who is in control?” further below: that is ONLY about the write permission, an externally controlled charge point stays “in operation” (status 102) — only this switch here means “really switched off”.')],
                ...$this->layoutedPanels(),
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => OHUB_Text::t('🔄 Force apply (without changing the form)'), 'onClick' => "IPS_ApplyChanges(\$id); echo '" . OHUB_Text::t('✅ ApplyChanges() executed.') . "';"],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => OHUB_Text::t('Active')],
                ['code' => 104, 'icon' => 'inactive', 'caption' => OHUB_Text::t('Charge point identity missing')],
            ],
        ];

        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => OHUB_Text::t('💬 Feedback'),
                'items' => [
                    ['type' => 'Label', 'caption' => OHUB_Text::t('🧪 OCPPHub is an early beta — feedback is welcome in the Symcon forum.')],
                    ['type' => 'Button', 'caption' => OHUB_Text::t('Go to forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => OHUB_Text::t('Do not show again'), 'onClick' => 'OHUBL_DismissReviewHint($id);'],
                ],
            ];
        }

        $form['elements'][] = $this->licenseHint();

        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        // „Wozu dieses Modul?" VOR dem News-Banner (Formular-Konvention
        // Punkt 0) — die Cross-Hub-Konfliktwarnung unten bleibt bewusst noch
        // davor, da sie ein akutes Live-Problem meldet, kein Einstiegstext.
        $intro = $this->purposeIntroPanel();
        if ($intro !== null) {
            array_unshift($form['elements'], $intro);
        }

        $conflictId = $this->findConflictingChargerHubInstance();
        if ($conflictId !== 0) {
            array_unshift($form['elements'], [
                'type'  => 'RowLayout',
                'items' => [
                    ['type' => 'Label', 'caption' => OHUB_Text::t('⚠️ This wallbox (IP %s) seems to be managed by ChargerHub AT THE SAME TIME (instance “%s”). Live finding 01.09.2026: this can block the charge enable WITHOUT either side showing an error message (symptom back then: OCPP reported “Accepted” everywhere, but no current flowed, even the wallbox manufacturer’s app could not charge). Please keep only ONE channel active per wallbox — either ChargerHub (Modbus) OR OCPPHub (OCPP), not both. Heuristic based on the IP address — if ChargerHub is configured by host name, this notice may be missing even though there is a conflict.', $this->ReadAttributeString('SourceIP'), @IPS_GetName($conflictId))],
                ],
            ]);
        }

        return json_encode($form);
    }

    // „Bedienung“ direkt im Formular (analog ChargerHub, Panel „🎛️ Bedienung“): Ladefreigabe und
    // Stromlimit wirken sofort, über denselben Weg wie die Steuervariablen im WebFront (RequestAction),
    // ohne Eigenschaft und ohne „Übernehmen“. Gezeigt wird immer, wer gerade regelt, damit eine
    // Handbedienung nicht blind gegen EMS/Tibber/Überschussladen arbeitet.
    private function controlPanel(): array
    {
        $min = $this->ReadPropertyInteger('MinCurrent');
        $max = max($min, $this->ReadPropertyInteger('MaxCurrent'));
        $stationMax = $this->ReadAttributeInteger('StationMaxCurrentA');
        if ($stationMax >= $min && $stationMax < $max) {
            $max = $stationMax;
        }
        $aktuell = (int)$this->GetValue('ctl_curr_limit');
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'ControlPanel',
            'caption'  => OHUB_Text::t('🎛️ Control'),
            'expanded' => true,
            'items'    => [
                ['type' => 'Label', 'name' => 'ControlPanelHint', 'caption' => $this->controlPanelHint()],
                ['type' => 'Label', 'name' => 'ControlPanelFeedback', 'caption' => ' '],
                [
                    'type' => 'CheckBox', 'name' => 'FormCtlEnable', 'caption' => OHUB_Text::t('Charge enable (takes effect immediately)'),
                    'value' => (bool)$this->GetValue('ctl_enable'),
                    'onChange' => 'IPS_RequestAction($id, "FormCtlEnable", $FormCtlEnable);',
                ],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Current limit in amperes (takes effect immediately, %d to %d A)', $min, $max)],
                [
                    'type' => 'HorizontalSlider', 'name' => 'FormCtlCurrent', 'minimum' => $min, 'maximum' => $max,
                    'value' => min($max, max($min, $aktuell)),
                    'onChange' => 'IPS_RequestAction($id, "FormCtlCurrent", $FormCtlCurrent);',
                ],
            ],
        ];
    }

    // Hinweiszeile im Bedienpanel: wer regelt gerade.
    private function controlPanelHint(): string
    {
        $managedBy = $this->ReadPropertyString('ManagedBy');
        if ($this->demoModus()) {
            return OHUB_Text::t('ℹ️ Demo mode: changes here are not sent to the wallbox.');
        }
        if ($this->IsDeactivated()) {
            return OHUB_Text::t('⛔ This charge point is deactivated: changes here are not executed.');
        }
        if ($managedBy !== 'none' && $managedBy !== 'ems') {
            return OHUB_Text::t('⛔ %s is currently in control: changes here are not executed (write lock). Who is in control is shown under “Current range & control authority”.', $this->managedByLabel($managedBy));
        }
        if ($managedBy === 'ems') {
            return OHUB_Text::t('⚠️ The EMS is currently in control: a manual operation here may be overwritten again at the next control step.');
        }
        if ($this->ReadPropertyBoolean('EnableSurplusCharging')) {
            if ($this->IsEmsActive()) {
                return OHUB_Text::t('⚠️ The EMS is active and has priority: a manual operation here may be overwritten again at the next control step.');
            }
            return OHUB_Text::t('⚠️ The standalone surplus charging is in control (status: %s). A manual stop here stays in place until the next manual start or until the vehicle is unplugged; the current limit may be adjusted again at the next control step.', (string)$this->GetValue('surplus_status'));
        }
        return OHUB_Text::t('ℹ️ Nobody is currently in control: changes here stay in place.');
    }

    private function limitsPanel(): array
    {
        return
        [
            'type'    => 'ExpansionPanel',
            'caption' => OHUB_Text::t('⚡ Current range & control authority'),
            'items'   => [
                ['type' => 'NumberSpinner', 'name' => 'MinCurrent', 'caption' => OHUB_Text::t('Minimum charging current (A)'), 'minimum' => 6, 'maximum' => 32, 'suffix' => 'A'],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Smallest charging current that the wallbox/vehicle accepts (IEC 61851: 6 A). Below this threshold, surplus charging pauses the charge completely instead of continuing with too little current.')],
                ['type' => 'NumberSpinner', 'name' => 'MaxCurrent', 'caption' => OHUB_Text::t('Maximum charging current (A)'), 'minimum' => 6, 'maximum' => 63, 'suffix' => 'A'],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Supply cable/fuse rating of this charge point — hard upper limit for every current limit that OCPPHub sets (in addition to the wallbox’s hardware limit), regardless of what an EMS requests.')],
                [
                    'type'    => 'Select',
                    'name'    => 'ManagedBy',
                    'caption' => OHUB_Text::t('Who is in control of this charge point?'),
                    'options' => array_map(
                        fn ($key) => ['value' => $key, 'caption' => $this->managedByLabel($key)],
                        self::MANAGEDBY_ALL
                    ),
                ],
                ['type' => 'Label', 'caption' => OHUB_Text::t('⚠️ Two-controller warning: if something else already controls this wallbox — go-e controller, load management, Tibber Grid Rewards or a §14a control — OCPPHub must not write charge enable/current limit in parallel (otherwise both controllers overwrite each other). Enter here who has the authority: for everything except “Nobody”, the standalone surplus charging below automatically stays passive.')],
            ],
        ];
    }

    /** Anzeigetext einer Steuerungshoheit (die Quelltexte der Konstante sind englisch). */
    private function managedByLabel(string $key): string
    {
        return OHUB_Text::t(self::MANAGEDBY_LABELS[$key] ?? $key);
    }

    private function duplicatePanel(): array
    {
        return
        [
            'type'    => 'ExpansionPanel',
            'caption' => OHUB_Text::t('🔀 Duplicate connection (dual-writer counting)'),
            'items'   => [
                ['type' => 'Label', 'caption' => OHUB_Text::t('Only fill in if the SAME wallbox is also connected through another stack module (e.g. the same box both here as an OCPPHub charge point and as a ChargerHub instance of its own). Determines which of the two entries counts as authoritative for consumption counting for EMS/MeterHub/Dashboard — deliberately NEVER detected automatically, ALWAYS a user decision.')],
                [
                    'type'    => 'Select',
                    'name'    => 'DuplicateOfSource',
                    'caption' => OHUB_Text::t('This instance is a duplicate of …'),
                    'options' => [
                        ['value' => '', 'caption' => OHUB_Text::t('— no, this entry counts normally —')],
                        ['value' => 'chargerhub', 'caption' => OHUB_Text::t('a ChargerHub instance (select below)')],
                        ['value' => 'ocpphub', 'caption' => OHUB_Text::t('another OCPPHub charge point instance (select below)')],
                    ],
                ],
                ['type' => 'SelectInstance', 'name' => 'DuplicateOfInstanceID', 'caption' => OHUB_Text::t('Counting instance (for “ChargerHub”/“OCPPHub” above, otherwise ignored)')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('ℹ️ Effect as soon as something is selected here: the entry stays visible in `OHUB_GetFunctions()` (now carrying the field `duplicateOf`), but stack consumers (EMS/MeterHub/Dashboard) skip it themselves when counting consumption. Concerns ONLY the counting — whether this charge point may continue to write to the wallbox is decided exclusively by “Who is in control?” above (or `OHUBL_SetActive()`).')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('⚠️ Why this matters: if “Who is in control?” above is still set to “Nobody” AND the instance chosen as the counting one also controls by itself (no external load management entered there), both sides could write at the same time. For exactly this forgotten case the instance then shows a warning status (“Two controllers on one wallbox”) until “Who is in control?” is set to an external value here or there.')],
            ],
        ];
    }

    private function surplusPanel(): array
    {
        return
        [
            'type'    => 'ExpansionPanel',
            'caption' => OHUB_Text::t('☀️ PV surplus charging (standalone, only without an active EMS)'),
            'items'   => [
                ['type' => 'CheckBox', 'name' => 'EnableSurplusCharging', 'caption' => OHUB_Text::t('Enable')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Only effective if “Who is in control?” above is set to “Nobody” AND no active EMS is installed AND a MeterHub meter at the grid connection point delivers a real-time value. If an EMS is active, it always has priority — this option then automatically does not apply. A visible status appears as the variable “PV surplus charging” (`surplus_status`) as soon as this option is checked.')],
                ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => OHUB_Text::t('Control interval (s, fallback/watchdog)'), 'minimum' => 5, 'maximum' => 300, 'suffix' => 's'],
                ['type' => 'Label', 'caption' => OHUB_Text::t('The control primarily recalculates event-driven as soon as the wallbox reports a fresh measurement (MeterValues) — this timer is only the fallback in case no report arrives.')],
                ['type' => 'NumberSpinner', 'name' => 'StorageSharePercent', 'caption' => OHUB_Text::t('Share for battery storage (%)'), 'minimum' => 0, 'maximum' => 100, 'suffix' => '%'],
                ['type' => 'Label', 'caption' => OHUB_Text::t('This share of the surplus stays reserved for the battery storage and is deducted from the ampere calculation for charging. 0 % = all surplus goes into the wallbox, 100 % = nothing goes into the wallbox.')],
                ['type' => 'NumberSpinner', 'name' => 'BatteryCapacityKWh', 'caption' => OHUB_Text::t('Battery storage capacity (kWh, 0 = no storage/unknown)'), 'minimum' => 0, 'maximum' => 200, 'digits' => 1, 'suffix' => 'kWh'],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Currently only stored for information — the phase switching wait time built on it (as in ChargerHub) is not yet implemented in OCPPHub (phase switching is a stage 2 topic).')],
                ...$this->surplusMeterFieldElements(),
            ],
        ];
    }

    // SUITE.md 3a „Formular: Aufbau“ (29.10.2026): die drei Fachpanels (Stromgrenzen,
    // Doppelte Anbindung, PV-Überschussladen, Bedienung) sind in Reihenfolge und Aufklapp-Zustand
    // wählbar. „Verbindungen im Verbund“ ist eine Statuszeilen-Gruppe und bleibt fest.
    private const LAYOUT_KEYS = ['control', 'limits', 'duplicate', 'surplus'];

    private function freePanels(): array
    {
        return [
            'control'   => $this->controlPanel(),
            'limits'    => $this->limitsPanel(),
            'duplicate' => $this->duplicatePanel(),
            'surplus'   => $this->surplusPanel(),
        ];
    }

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
        foreach (self::LAYOUT_KEYS as $i => $k) {
            $r = $by[$k] ?? [];
            $open = !empty($r['Hidden']) ? 'hidden' : (!empty($r['Closed']) ? 'closed' : (!empty($r['Opened']) ? 'open' : 'std'));
            $out[$k] = [
                'open' => $open,
                'pos'  => isset($r['Pos']) ? max(1, min(99, (int)$r['Pos'])) : $i + 1,
                'idx'  => $i,
            ];
        }
        uasort($out, fn ($a, $b) => [$a['pos'], $a['idx']] <=> [$b['pos'], $b['idx']]);
        return $out;
    }

    // Fachpanels in der gewählten Reihenfolge, dahinter das Aufbau-Panel selbst.
    private function layoutedPanels(): array
    {
        $free = $this->freePanels();
        $layout = $this->formLayout();
        $out = [];
        foreach ($layout as $key => $l) {
            $panel = $free[$key];
            if ($l['open'] === 'open') {
                $panel['expanded'] = true;
            } elseif ($l['open'] === 'closed') {
                $panel['expanded'] = false;
            } elseif ($l['open'] === 'hidden') {
                // Nicht weglassen: die Felder bleiben im Formular, gespeicherte Werte bleiben erhalten.
                $panel['visible'] = false;
            }
            $out[] = $panel;
        }
        $out[] = $this->layoutPanel($layout, $free);
        return $out;
    }

    private function layoutPanel(array $layout, array $free): array
    {
        $rows = [];
        $hidden = [];
        foreach ($layout as $key => $l) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', (string)($free[$key]['caption'] ?? $key)));
            $rows[] = ['Key' => $key, 'Panel' => $name, 'Opened' => $l['open'] === 'open', 'Closed' => $l['open'] === 'closed',
                'Hidden' => $l['open'] === 'hidden', 'Pos' => $l['pos']];
            if ($l['open'] === 'hidden') {
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
                ['type' => 'Label', 'caption' => OHUB_Text::t('Here you decide which areas are expanded when the form is opened and in which order they appear. Symcon does not remember expanding and collapsing by itself, so the setting here applies every time the form is opened. It takes effect after “Apply changes” the next time the form is opened.')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('No check mark means “default”: as the module intends. The position is a number; with equal numbers the default order is kept. “Hidden” removes an area from the display without it losing anything: its settings are preserved and stay in effect (meant for areas you rarely need). To show it again, remove the check mark at “Hidden” or click “Reset to default”. If several check marks are set, “Hidden” takes precedence over “Collapsed”, which takes precedence over “Expanded”. The areas above (purpose, news, documentation, connections) and below (feedback, about this module) stay where they are and cannot be hidden.')],
                [
                    'type' => 'List', 'name' => 'FormLayout', 'caption' => OHUB_Text::t('Areas'), 'rowCount' => count($rows), 'add' => false, 'delete' => false,
                    'loadValuesFromConfiguration' => false,
                    'values' => $rows,
                    'columns' => [
                        ['caption' => OHUB_Text::t('Area'), 'name' => 'Panel', 'width' => 'auto', 'add' => ''],
                        ['caption' => OHUB_Text::t('Key'), 'name' => 'Key', 'width' => '0px', 'visible' => false, 'add' => '', 'save' => true],
                        ['caption' => OHUB_Text::t('Expanded'), 'name' => 'Opened', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => OHUB_Text::t('Collapsed'), 'name' => 'Closed', 'width' => '110px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => OHUB_Text::t('🙈 Hidden'), 'name' => 'Hidden', 'width' => '130px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => OHUB_Text::t('Position'), 'name' => 'Pos', 'width' => '100px', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 99]],
                    ],
                ],
                ['type' => 'Button', 'caption' => OHUB_Text::t('↩️ Reset to default'), 'onClick' => 'echo OHUBL_ResetFormLayout($id);'],
            ]),
        ];
    }

    // Setzt Aufklapp-Zustand und Reihenfolge der Fachpanels im offenen Formular auf den Standard
    // (gilt erst nach „Übernehmen“, keine Selbstpersistenz).
    public function ResetFormLayout(): string
    {
        $free = $this->freePanels();
        $rows = [];
        foreach (self::LAYOUT_KEYS as $i => $k) {
            $name = trim((string)preg_replace('/\s*\(.*$/u', '', (string)($free[$k]['caption'] ?? $k)));
            $rows[] = ['Key' => $k, 'Panel' => $name, 'Opened' => false, 'Closed' => false, 'Hidden' => false, 'Pos' => $i + 1];
        }
        $this->UpdateFormField('FormLayout', 'values', json_encode($rows, JSON_UNESCAPED_UNICODE));
        return OHUB_Text::t('ℹ️ Reset to default. Click “Apply changes” below to save.');
    }

    // „Über dieses Modul" (SUITE.md Formular-Konvention Punkt 5) — bewusst
    // NICHT dismissible, Wortlaut verbundweit identisch ("Variante A").
    private function licenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => OHUB_Text::t('🧡  About this module'),
            'items' => [
                ['type' => 'Label', 'caption' => OHUB_Text::t('Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('License: PolyForm Noncommercial 1.0.0 — free for private, non-commercial use; commercial use requires a separate license from the rights holder.')],
                ['type' => 'Button', 'caption' => OHUB_Text::t('View license text'), 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Do you like the module and would still like to leave something behind? I would be happy about a small donation — entirely voluntary, nothing expected in return.')],
                ['type' => 'Button', 'caption' => OHUB_Text::t('☕  Donate via PayPal'), 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            'caption' => OHUB_Text::t('👋 What is this module for?'),
            'items' => [
                ['type' => 'Label', 'caption' => OHUB_Text::t('This “OCPPHub Charge Point” instance stands for exactly one wallbox (or connector) — it holds the visible readings (charging power, energy meter, vehicle plugged in) and the control variables (charge enable, current limit) as ordinary Symcon variables. The actual OCPP communication runs through the assigned “OCPPHub Splitter” instance; here things are only configured and displayed.')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('The benefit: set charge enable/current limit from within Symcon, real charging power/energy for dashboards or an energy management system (EMS), optionally standalone PV surplus charging even without an EMS installed.')],
                ['type' => 'Label', 'caption' => OHUB_Text::t('Still missing the right wallbox, or want to create several at once? “OCPPHub Configurator” shows wallboxes that are already connected but not yet created, for one-click creation.')],
                ['type' => 'Button', 'caption' => OHUB_Text::t('Understood – do not show again'), 'onClick' => 'OHUBL_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => OHUB_Text::t('🆕 New in this module — please take a quick look and check your settings if needed:')]];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . OHUB_Text::t($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => OHUB_Text::t('Understood – do not show again'), 'onClick' => 'OHUBL_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => OHUB_Text::t('🆕 New in version %s', self::NEWS_VERSION), 'expanded' => true, 'items' => $items];
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
        }
        // Die Bezeichnungen der modul-eigenen Profile werden bei jedem Übernehmen in der aktuellen
        // Sprache gesetzt, damit auch bestehende (früher deutsch angelegte) Profile mitziehen.
        {
            $captions = [
                0 => OHUB_Text::t('Available'), 1 => OHUB_Text::t('Preparing'), 2 => OHUB_Text::t('Charging'),
                3 => OHUB_Text::t('Suspended (wallbox)'), 4 => OHUB_Text::t('Suspended (vehicle)'),
                5 => OHUB_Text::t('Finishing'), 6 => OHUB_Text::t('Reserved'),
                7 => OHUB_Text::t('Unavailable'), 8 => OHUB_Text::t('Faulted'),
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
        }
        IPS_SetVariableProfileAssociation('OHUB.Connected', false, OHUB_Text::t('No'), '', -1);
        IPS_SetVariableProfileAssociation('OHUB.Connected', true, OHUB_Text::t('Yes'), '', -1);
    }

    private function RegisterVariables(): void
    {
        // Verfügbarkeits-Fund ChargerHub 12.09.2026 — siehe UpdateLastSeen()/
        // CheckConnectivity(). Position 5 (vor allen Messwerten): ohne aktive
        // OCPP-Verbindung sind power/state/vehicle_plugged etc. ohnehin nur
        // eingefrorene Altwerte, das gehört als Erstes ins Auge.
        $this->MaintainVariable('ocpp_connected', OHUB_Text::t('Wallbox connected (OCPP)'), VARIABLETYPE_BOOLEAN, 'OHUB.Connected', 5, true);
        $this->MaintainVariable('power', OHUB_Text::t('Charging power'), VARIABLETYPE_FLOAT, 'NRG.Watt', 0, true);
        $this->MaintainVariable('energy_total', OHUB_Text::t('Total energy'), VARIABLETYPE_FLOAT, 'NRG.kWh', 10, true);
        $this->MaintainVariable('energy_session', OHUB_Text::t('Energy of this charge'), VARIABLETYPE_FLOAT, 'OHUB.kWhSession', 20, true);
        $this->MaintainVariable('state', OHUB_Text::t('Status'), VARIABLETYPE_INTEGER, 'OHUB.ChargePointStatus', 30, true);
        $this->MaintainVariable('vehicle_plugged', OHUB_Text::t('Vehicle plugged in'), VARIABLETYPE_BOOLEAN, 'OHUB.Connected', 40, true);
        $this->MaintainVariable('vehicle_name', OHUB_Text::t('Assigned vehicle'), VARIABLETYPE_STRING, '', 50, true);
        // Nur befüllt, wenn die Wallbox den OCPP-Measurand „SoC" tatsächlich
        // überträgt (nicht jede tut das) — siehe UpdateMeterValues().
        $this->MaintainVariable('vehicle_soc', OHUB_Text::t('Vehicle state of charge (SoC, if transmitted by the wallbox)'), VARIABLETYPE_FLOAT, 'NRG.Percent', 55, true);

        $this->MaintainVariable('ctl_enable', OHUB_Text::t('Charge enable'), VARIABLETYPE_BOOLEAN, '', 60, true);
        $this->MaintainVariable('ctl_curr_limit', OHUB_Text::t('Current limit'), VARIABLETYPE_INTEGER, 'OHUB.AmpereLimit', 70, true);
        $this->EnableAction('ctl_enable');
        $this->EnableAction('ctl_curr_limit');

        if ($this->ReadPropertyBoolean('EnableSurplusCharging')) {
            $this->MaintainVariable('surplus_status', OHUB_Text::t('PV surplus charging'), VARIABLETYPE_STRING, '', 80, true);
        } else {
            $this->MaintainVariable('surplus_status', OHUB_Text::t('PV surplus charging'), VARIABLETYPE_STRING, '', 80, false);
        }

        // Reservierung (Stufe 2) — siehe Reserve()/CancelReservation().
        $this->MaintainVariable('reserved_by', OHUB_Text::t('Reserved for'), VARIABLETYPE_STRING, '', 90, true);
        $this->MaintainVariable('reserved_until', OHUB_Text::t('Reserved until'), VARIABLETYPE_STRING, '', 100, true);

        // Diagnose-Feature 31.08.2026 (Ladeablehnung erklären, siehe
        // DiagnoseBlockReason()) — leer im Normalfall, nur befüllt nach
        // einer eindeutigen Ablehnung von RemoteStartTransaction/
        // SetChargingProfile durch die Wallbox.
        $this->MaintainVariable('block_reason', OHUB_Text::t('Possible reason for charge rejection'), VARIABLETYPE_STRING, '', 110, true);
    }

    // Der Splitter ist seit 0.7.0 das echte übergeordnete Gerät dieser Instanz (Symcon-Datenfluss,
    // Review 30.10.2026) — nicht mehr eine Property, die wir selbst auswerten.
    private function elternId(): int
    {
        return (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    private function resolveSplitterId(): int
    {
        return $this->elternId();
    }

    /**
     * Einmalig nach dem Übernehmen (Timer): Bestandsinstanzen waren bisher nur über die Property
     * SplitterID oder die Objektbaum-Position dem Splitter zugeordnet. Jetzt mit ihm verbinden,
     * damit der Datenfluss greift. Eine bereits verbundene Instanz bleibt unberührt; wo sich
     * kein eindeutiger Splitter ergibt, wird nichts geraten (das Verbindungs-Panel weist darauf hin).
     */
    public function VerbindungPruefen(): void
    {
        $this->SetTimerInterval('VerbindenTimer', 0);
        if ($this->elternId() !== 0) {
            return;
        }
        $kandidat = $this->ReadPropertyInteger('SplitterID');
        if ($kandidat <= 0) {
            $oben = (int)(@IPS_GetParent($this->InstanceID) ?: 0);
            if ($oben > 0 && @IPS_InstanceExists($oben) && IPS_GetInstance($oben)['ModuleInfo']['ModuleID'] === self::SPLITTER_GUID) {
                $kandidat = $oben;
            }
        }
        if ($kandidat <= 0) {
            $alle = @IPS_GetInstanceListByModuleID(self::SPLITTER_GUID) ?: [];
            if (count($alle) === 1) {
                $kandidat = (int)$alle[0];
            }
        }
        if ($kandidat > 0 && @IPS_InstanceExists($kandidat) && IPS_GetInstance($kandidat)['ModuleInfo']['ModuleID'] === self::SPLITTER_GUID) {
            if (@IPS_ConnectInstance($this->InstanceID, $kandidat)) {
                $this->LogMessage(OHUB_Text::t('Connected to splitter #%d (data flow).', $kandidat), KL_MESSAGE);
            }
        }
    }

    /**
     * Anfrage an den Splitter. Liefert `daten` der Antwort, null wenn kein Splitter verbunden ist
     * oder er die Anfrage ablehnt.
     */
    private function anSplitter(string $aktion, array $parameter = []): ?array
    {
        if ($this->elternId() <= 0) {
            return null;
        }
        $parameter['DataID'] = self::DATA_ZUM_SPLITTER;
        $parameter['Aktion'] = $aktion;
        $parameter['Cpid'] = $this->ReadPropertyString('CPID');
        $roh = $this->SendDataToParent((string)json_encode($parameter));
        $a = json_decode((string)$roh, true);
        return (is_array($a) && !empty($a['ok'])) ? (array)($a['daten'] ?? []) : null;
    }

    private function demoModus(): bool
    {
        return !empty($this->anSplitter('Vorfuehrmodus')['aktiv']);
    }

    public function RequestAction($Ident, $Value)
    {
        // Bedienpanel im Formular: dieselbe Wirkung wie die Steuervariablen, plus Rückmeldung im Formular.
        $formCtl = ['FormCtlEnable' => 'ctl_enable', 'FormCtlCurrent' => 'ctl_curr_limit'];
        if (isset($formCtl[$Ident])) {
            $this->formularBedienung($formCtl[$Ident], $Value);
            return;
        }

        $cpid = $this->ReadPropertyString('CPID');

        // Vorführmodus (01.09.2026, siehe OCPPHubSplitter::IsDemoMode()):
        // echte Ladesteuerung serverseitig ablehnen, BEVOR irgendein OCPP-
        // Befehl gesendet wird — auch ein Klick in einer öffentlichen Demo-
        // WebFront darf Dietmars echte Wallbox nie schalten. Zeigt den
        // Schalter/Regler sofort wieder auf dem tatsächlichen (unveränderten)
        // Zustand, statt optimistisch auf dem angeklickten Wert stehen zu
        // bleiben.
        if (in_array($Ident, ['ctl_enable', 'ctl_curr_limit'], true) && $this->demoModus()) {
            $this->LogMessage(OHUB_Text::t('Demo mode is active — control command “%s” to charge point %d rejected, no real charging control triggered.', $Ident, $this->InstanceID), KL_WARNING);
            $this->SetValue($Ident, $this->GetValue($Ident));
            return;
        }

        // Schreibsperre (13.09.2026, EMS' endgültige Entscheidung): externe
        // Steuerhoheit (managedBy != none/ems) ODER manuell deaktiviert.
        // NICHT mehr IsDuplicate() — Zählen und Schreiben sind orthogonal,
        // siehe OCPPHubSplitter::isWriteBlocked(). Gleiches Sofort-Feedback-
        // Muster wie oben beim Vorführmodus; der Splitter prüft dasselbe
        // nochmal direkt an der Absendestelle.
        $externallyManaged = !in_array($this->ReadPropertyString('ManagedBy'), ['none', 'ems'], true);
        if (in_array($Ident, ['ctl_enable', 'ctl_curr_limit'], true) && ($externallyManaged || $this->IsDeactivated())) {
            $this->LogMessage(OHUB_Text::t('Charge point %d is blocked (external control authority or deactivated) — control command “%s” rejected.', $this->InstanceID, $Ident), KL_WARNING);
            $this->SetValue($Ident, $this->GetValue($Ident));
            return;
        }

        switch ($Ident) {
            case 'ctl_enable':
                $this->SetValue($Ident, (bool)$Value);
                if ($cpid !== '') {
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
                        $this->WriteAttributeBoolean('ManualStopActive', false);
                        $this->anSplitter('ManuellStart');
                    } else {
                        $this->WriteAttributeBoolean('ManualStopActive', true);
                        $this->anSplitter('Stopp', ['TransaktionsId' => $this->ReadAttributeInteger('LastTransactionId')]);
                    }
                }
                break;

            case 'ctl_curr_limit':
                $clamped = max(
                    $this->ReadPropertyInteger('MinCurrent'),
                    min($this->ReadPropertyInteger('MaxCurrent'), (int)$Value)
                );
                $this->SetValue($Ident, $clamped);
                if ($cpid !== '') {
                    $this->anSplitter('Stromlimit', ['Ampere' => (float)$clamped]);
                }
                break;
        }
    }

    // Ausführung einer Bedienung aus dem Formular. Lehnt RequestAction() sie ab (Vorführmodus,
    // Schreibsperre), bleibt die Steuervariable unverändert: der Abgleich danach ist die Rückmeldung.
    private function formularBedienung(string $ziel, $wert): void
    {
        $wert = ($ziel === 'ctl_enable') ? (bool)$wert : (int)$wert;
        $namen = ['ctl_enable' => OHUB_Text::t('Charge enable'), 'ctl_curr_limit' => OHUB_Text::t('Current limit')];
        $vorher = $this->GetValue($ziel);
        $this->RequestAction($ziel, $wert);
        $nachher = $this->GetValue($ziel);
        $gezeigt = ($ziel === 'ctl_enable') ? ($wert ? OHUB_Text::t('On') : OHUB_Text::t('Off')) : $nachher . ' A';
        if ($ziel === 'ctl_curr_limit') {
            $erwartet = max($this->ReadPropertyInteger('MinCurrent'), min($this->ReadPropertyInteger('MaxCurrent'), $wert));
            $ok = ((int)$nachher === $erwartet);
        } else {
            $ok = ((bool)$nachher === $wert);
        }
        if ($ok) {
            $text = OHUB_Text::t('✅ %s: %s set.', $namen[$ziel], $gezeigt) . (($ziel === 'ctl_enable' && $wert) ? OHUB_Text::t(' Whether the wallbox really starts is shown by the status.') : '');
        } elseif ($this->demoModus() || $this->IsDeactivated() || !in_array($this->ReadPropertyString('ManagedBy'), ['none', 'ems'], true)) {
            $text = OHUB_Text::t('⚠️ %s not executed (demo mode, deactivated or write lock by another controller).', $namen[$ziel]);
        } else {
            $grund = ($ziel === 'ctl_enable') ? (string)$this->GetValue('block_reason') : '';
            $text = $grund !== '' ? OHUB_Text::t('⚠️ %s could not be set: %s.', $namen[$ziel], $grund) : OHUB_Text::t('⚠️ %s could not be set, details in the message log.', $namen[$ziel]);
        }
        $this->UpdateFormField('ControlPanelFeedback', 'caption', $text);
        $this->UpdateFormField('ControlPanelHint', 'caption', $this->controlPanelHint());
        if (!$ok) {
            // Schalter/Regler im offenen Formular auf den tatsächlichen Wert zurücksetzen.
            $feld = ($ziel === 'ctl_enable') ? 'FormCtlEnable' : 'FormCtlCurrent';
            $this->UpdateFormField($feld, 'value', $nachher);
        }
    }

    // ---------------------------------------------------------------------
    // Meldungen des Splitters (Datenfluss, Symcon-Review 30.10.2026). Der Splitter schickt
    // jede Nachricht an alle seine Kinder; wir nehmen nur die mit unserer Wallbox-Kennung an
    // und antworten sonst mit einer leeren Zeichenkette. Protokoll: .docs/datenfluss.md.
    // Die öffentlichen Funktionen darunter (UpdateMeterValues, StartTransaction, …) bleiben
    // bestehen, werden aber nur noch von hier aufgerufen.
    // ---------------------------------------------------------------------

    public function ReceiveData($JSONString)
    {
        $a = json_decode((string)$JSONString, true);
        if (!is_array($a) || !empty($a['Ziel'])) {
            return '';   // Nachrichten an die Abrechnung gehen uns nichts an
        }
        $aktion = (string)($a['Aktion'] ?? '');
        $cpid = $this->ReadPropertyString('CPID');
        if ($aktion !== 'Vertrag' && ($cpid === '' || (string)($a['Cpid'] ?? '') !== $cpid)) {
            return '';
        }
        try {
            $daten = $this->bearbeiteMeldung($aktion, $a);
        } catch (\Throwable $e) {
            $this->LogMessage(OHUB_Text::t('Message “%s” of the splitter could not be processed: %s', $aktion, $e->getMessage()), KL_WARNING);
            return '';
        }
        return $daten === null ? '' : (string)json_encode(['ok' => true, 'daten' => $daten]);
    }

    private function bearbeiteMeldung(string $aktion, array $a): ?array
    {
        $zahlOderNull = static fn ($v) => is_numeric($v) ? (float)$v : null;
        switch ($aktion) {
            case 'Vertrag':
                return ['cpid' => $this->ReadPropertyString('CPID'), 'eintrag' => $this->GetContractEntry()];
            case 'Zustand':
                $eintrag = $this->GetContractEntry();
                return [
                    'state'             => $this->GetState(),
                    'deactivated'       => $this->IsDeactivated(),
                    'externallyManaged' => (bool)($eintrag['externallyManaged'] ?? false),
                    'lastIdTag'         => $this->GetLastIdTag(),
                    'meterStartWh'      => $this->GetMeterStartWh(),
                    'reservedIdTag'     => $this->GetActiveReservationIdTag(),
                    'vehicleName'       => $this->GetVehicleName(),
                ];
            case 'Lebenszeichen':
                if ((string)($a['Ip'] ?? '') !== '') {
                    $this->SetSourceIP((string)$a['Ip']);
                }
                $this->UpdateLastSeen();
                return [];
            case 'Boot':
                $this->UpdateBootInfo((string)($a['Hersteller'] ?? ''), (string)($a['Modell'] ?? ''), (string)($a['Seriennummer'] ?? ''));
                return [];
            case 'Status':
                $this->UpdateStatus((string)($a['Status'] ?? ''), (string)($a['Fehlercode'] ?? ''));
                return [];
            case 'Messwerte':
                $this->UpdateMeterValues($zahlOderNull($a['Leistung'] ?? null), $zahlOderNull($a['Energie'] ?? null), $zahlOderNull($a['Soc'] ?? null));
                return [];
            case 'TransaktionStart':
                $this->StartTransaction((int)($a['TransaktionsId'] ?? 0), (string)($a['IdTag'] ?? ''), (int)($a['ZaehlerStart'] ?? 0));
                return [];
            case 'TransaktionStopp':
                $this->StopTransaction((int)($a['TransaktionsId'] ?? 0), (int)($a['ZaehlerStop'] ?? 0));
                return [];
            case 'Blockiert':
                $this->ReportBlockedStart((string)($a['Grund'] ?? ''));
                return [];
            case 'BlockGrundLoeschen':
                $this->ClearBlockReason();
                return [];
            case 'BlockGrundDiagnose':
                $this->DiagnoseBlockReason();
                return [];
            case 'Fahrzeug':
                $this->SetVehicleName((string)($a['Name'] ?? ''), !empty($a['Zeitkorrelation']));
                return [];
            case 'FahrzeugTessie':
                $this->SetVehicleTessieId((int)($a['Id'] ?? 0));
                return [];
            case 'AutoStartBestaetigt':
                $this->ConfirmAutoStart();
                return [];
            case 'Kapazitaeten':
                $this->SetStationCapabilities((int)($a['MaxA'] ?? -1), (int)($a['MinA'] ?? -1), (int)($a['Phasen'] ?? -1));
                return [];
        }
        return null;   // unbekannte Aktion: nicht antworten
    }

    public function UpdateBootInfo(string $vendor, string $model, string $serial): void
    {
        $this->SendDebug('OCPPHub Boot', "$vendor / $model / $serial", 0);
        // Fund MeterHub 13.09.2026: Seriennummer/Modell landeten bisher NUR
        // im flüchtigen Debug-Fenster — MeterHubVirtual braucht sie aber
        // dauerhaft für die Dual-Writer-Erkennung (sonst nur unzuverlässiger
        // Zählerstand-Vergleich). Vendor bleibt zentral beim Splitter
        // (ChargePointVendor-Attribut, für den go-e-frc-Ausweichweg).
        if ($serial !== '') {
            $this->WriteAttributeString('DeviceSerial', $serial);
        }
        if ($model !== '') {
            $this->WriteAttributeString('DeviceModel', $model);
        }
    }

    public function UpdateStatus(string $ocppStatus, string $errorCode): void
    {
        if ($errorCode !== '' && $errorCode !== 'NoError') {
            $this->LogMessage(OHUB_Text::t('Charge point %d reports an error: %s', $this->InstanceID, $errorCode), KL_WARNING);
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
                // Abstecken hebt einen manuellen Stopp auf — ein neu
                // angestecktes Fahrzeug (auch dasselbe erneut) soll wieder
                // ganz normal automatisch laden dürfen.
                $this->WriteAttributeBoolean('ManualStopActive', false);
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
        if (!$timeCorrelated || $name === '' || $this->GetValue('ctl_enable') || $this->ReadAttributeBoolean('ManualStopActive')) {
            return;
        }
        if (time() - $this->ReadAttributeInteger('LastAutoAuthAttempt') < self::AUTO_AUTH_COOLDOWN_SECONDS) {
            return;
        }
        $this->WriteAttributeInteger('LastAutoAuthAttempt', time());
        if ($this->ReadPropertyString('CPID') !== '') {
            $this->anSplitter('AutoFreigabe', ['Name' => $name]);
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

    // Verfügbarkeits-Fund ChargerHub 12.09.2026: eine bereits angelegte
    // Ladepunkt-Instanz hatte keinerlei Verbindungsüberwachung mehr — weder
    // eine sichtbare Variable noch eine Alterungsprüfung. Vom Splitter bei
    // JEDER eingehenden Nachricht dieser Wallbox aufgerufen (unabhängig vom
    // Inhalt, reiner Lebenszeichen-Nachweis), analog SetSourceIP() oben.
    public function UpdateLastSeen(): void
    {
        $this->WriteAttributeInteger('LastSeenAt', time());
        $this->SetValue('ocpp_connected', true);
    }

    // Vom Splitter nach einer GetConfiguration-Antwort aufgerufen (Vertrag
    // 1.7). -1 = Wallbox hat den Wert nicht (plausibel) geliefert — dann
    // bleibt der bisherige Wert stehen, es wird nichts überschrieben.
    public function SetStationCapabilities(int $MaxCurrentA, int $MinCurrentA, int $PhaseSwitch): void
    {
        if ($MaxCurrentA > 0) {
            $this->WriteAttributeInteger('StationMaxCurrentA', $MaxCurrentA);
        }
        if ($MinCurrentA > 0) {
            $this->WriteAttributeInteger('StationMinCurrentA', $MinCurrentA);
        }
        if ($PhaseSwitch === 0 || $PhaseSwitch === 1) {
            $this->WriteAttributeInteger('PhaseSwitchSupported', $PhaseSwitch);
        }
    }

    // Heartbeat-Intervall ist bei uns fest auf 300s eingestellt (siehe
    // OCPPHubSplitter::onBootNotification()) — reichlich Marge (3x) gegen
    // normale Jitter/einen einzelnen verpassten Heartbeat, bevor „nicht
    // verbunden" angezeigt wird.
    private const MAX_LAST_SEEN_AGE_SECONDS = 900;

    // Per ConnectivityTimer alle 60s aufgerufen (siehe ApplyChanges()) — läuft
    // IMMER, unabhängig von EnableSurplusCharging, weil Verbindungsstatus
    // unabhängig vom Überschussladen relevant ist. Erkennt das Gegenteil von
    // UpdateLastSeen(): dass seit Längerem GAR NICHTS mehr ankam.
    public function CheckConnectivity(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $lastSeen = $this->ReadAttributeInteger('LastSeenAt');
        $connected = $lastSeen > 0 && (time() - $lastSeen) < self::MAX_LAST_SEEN_AGE_SECONDS;
        if ($this->GetValue('ocpp_connected') !== $connected) {
            $this->SetValue('ocpp_connected', $connected);
        }
        $this->refreshInstanceStatus();
    }

    // Sicherheitsnetz gegen den „markiert, aber vergessen umzustellen"-Fall
    // (13.09.2026, SUITE.md Regel 9f, EMS' endgültige Entscheidung): seit
    // `duplicateOf` NICHT mehr selbst die Schreibsperre auslöst (siehe
    // OCPPHubSplitter::isWriteBlocked()), muss der Nutzer bei einer als
    // Duplikat markierten Instanz SELBST auch „Wer regelt?" umstellen —
    // sonst könnten beide Seiten (dieser Ladepunkt UND das Ziel) gleichzeitig
    // schreiben. Zeigt einen echten Warnstatus (>200, siehe SUITE.md 9f,
    // NICHT 9d — das hier ist ein Konfigurationsmangel, kein geparkter
    // Zustand), solange der Widerspruch besteht.
    // 206, nicht 205 (EMS-Abstimmung 13.09.2026 — Verbund-Konsistenz: EMS/
    // ChargerHub zeigen denselben "Zwei Regler an einer Wallbox"-Warnstatus
    // ebenfalls als 206; bei EMS ist 205 anderweitig belegt).
    private const STATUS_DUPLICATE_CONFLICT = 206;

    // FIX 13.09.2026 (EMS-Anfrage): berücksichtigt jetzt auch IsDeactivated()
    // — ein deaktivierter Ladepunkt zeigt IS_INACTIVE (104, SUITE.md Regel
    // 9d: bewusst inaktiv, kein Fehlercode >200), nicht den normalen
    // Betriebsstatus. Deaktiviert gewinnt gegen einen etwaigen Duplikat-
    // Konflikt — schreibt ohnehin nicht, also kein Konfliktrisiko von dieser
    // Seite.
    private function refreshInstanceStatus(): void
    {
        if ($this->ReadPropertyString('CPID') === '') {
            return; // Instanz ohnehin auf 104 (nicht konfiguriert), nichts zu prüfen
        }
        if ($this->IsDeactivated()) {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus($this->hasDuplicateWriteConflict() ? self::STATUS_DUPLICATE_CONFLICT : 102);
    }

    // Liest den Zieleintrag des eigenen `duplicateOf` (ChargerHub oder eine
    // andere OCPPHub-Instanz) und prüft dessen `managedBy`. Sind BEIDE Seiten
    // (wir UND das Ziel) auf 'none'/'ems' — also beide der Meinung, selbst
    // schreiben zu dürfen — ist das der gefährliche Doppel-Schreiber-Fall.
    // Zielinstanz nicht erreichbar/unbekannt -> kein Fehlalarm (fail-quiet,
    // wie die übrigen Diagnose-Features in diesem Modul).
    private function hasDuplicateWriteConflict(): bool
    {
        if (!$this->IsDuplicate()) {
            return false;
        }
        $ownManagedBy = $this->ReadPropertyString('ManagedBy');
        if (in_array($ownManagedBy, ['none', 'ems'], true) === false) {
            return false; // wir selbst sind schon delegiert, kein Risiko
        }
        $source = $this->ReadPropertyString('DuplicateOfSource');
        $targetId = $this->ReadPropertyInteger('DuplicateOfInstanceID');
        if ($targetId <= 0 || !@IPS_InstanceExists($targetId)) {
            return false;
        }
        $targetManagedBy = null;
        if ($source === 'ocpphub') {
            // Anderer Ladepunkt desselben Splitters: sein Vertragseintrag kommt über den Splitter
            // (Datenfluss), nicht über einen Funktionsaufruf auf der fremden Instanz.
            foreach ((array)($this->anSplitter('Vertraege')['liste'] ?? []) as $k) {
                if ((int)($k['eintrag']['instanceID'] ?? 0) === $targetId) {
                    $targetManagedBy = $k['eintrag']['managedBy'] ?? null;
                    break;
                }
            }
        } elseif ($source === 'chargerhub' && function_exists('CHUB_GetFunctions')) {
            try {
                $entries = CHUB_GetFunctions($targetId);
                $targetManagedBy = (is_array($entries) && isset($entries[0])) ? ($entries[0]['managedBy'] ?? null) : null;
            } catch (\Throwable $e) {
                $targetManagedBy = null;
            }
        }
        if ($targetManagedBy === null) {
            return false;
        }
        return in_array($targetManagedBy, ['none', 'ems'], true);
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
            return OHUB_Text::t('Vehicle is not responding right now (possibly in sleep mode) — wake-up triggered, please try again shortly.');
        }

        $state = json_decode((string)@TESSIE_GetVehicleState($tessieId), true);
        if (!is_array($state)) {
            return '';
        }
        if (($state['scheduledChargingActive'] ?? null) === true) {
            return OHUB_Text::t('Vehicle has its own charge schedule active (scheduled departure time).');
        }
        if (isset($state['soc'], $state['chargeLimit']) && (float)$state['soc'] >= (float)$state['chargeLimit']) {
            return OHUB_Text::t('Charge limit in the vehicle has already been reached.');
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
                $reason = (string)($control['reason'] ?? OHUB_Text::t('no reason given'));
                return OHUB_Text::t('Possibly (cannot be assigned with certainty): Tibber Grid Rewards is active for this vehicle — %s', $reason);
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

    // Dual-Writer-ZÄHLUNG (13.09.2026, siehe RegisterPropertyString('DuplicateOfSource', ...)
    // in Create()) — betrifft NUR, ob dieser Eintrag von Verbund-Konsumenten mitgezählt wird
    // (siehe getDuplicateOfForContract()). KEINE Wirkung auf das Schreiben — dafür ist
    // ausschließlich `ManagedBy` zuständig, siehe OCPPHubSplitter::isWriteBlocked().
    public function IsDuplicate(): bool
    {
        return $this->ReadPropertyString('DuplicateOfSource') !== '' && $this->ReadPropertyInteger('DuplicateOfInstanceID') > 0;
    }

    // Generischer Ein/Aus-Schalter (13.09.2026, MeterHubVirtual-Anfrage,
    // `OHUBL_SetActive(bool)` — Symcon hängt $InstanceID automatisch vor
    // diesen Parameter, siehe SUITE.md-Stolperstein „keine PHP-Standardwerte").
    // Sperrt zusammen mit externer Steuerhoheit (ManagedBy != none/ems) über
    // OCPPHubSplitter::isWriteBlocked(): ACKt das OCPP-Protokoll weiter
    // normal, aber Authorize/StartTransaction
    // wird `Blocked`, kein RemoteStart/SetCurrentLimit/Reset mehr von uns.
    // Eine beim Abschalten bereits laufende Transaktion wird NICHT gestoppt
    // (Dietmars Entscheidung über MeterHub, „zu Ende laden lassen") — ab dann
    // nur keine neue Autorisierung/Steuerung mehr.
    public function IsDeactivated(): bool
    {
        return $this->ReadPropertyBoolean('Deaktiviert');
    }

    // FIX 13.09.2026: persistiert jetzt über dieselbe Property wie der
    // Formular-Schalter „🔌 Ladepunkt deaktivieren" (siehe
    // GetConfigurationForm()) — ein Aufruf von außen (MeterHubVirtual o.ä.)
    // wirkt sich dadurch auch sofort sichtbar im Formular aus, und Dietmar
    // kann denselben Zustand genauso gut manuell in der Konsole setzen,
    // ohne den ganzen Splitter abschalten zu müssen (Live-Fund: genau das
    // hatte er mangels sichtbarem Schalter getan, WB2 dabei mit lahmgelegt).
    public function SetActive(bool $Active): string
    {
        IPS_SetProperty($this->InstanceID, 'Deaktiviert', !$Active);
        IPS_ApplyChanges($this->InstanceID);
        if (!$Active) {
            // MeterHub-Hinweis 13.09.2026 (Vergleich mit ChargerHubs eigenem
            // Verhalten beim Abschalten): ein von UNS gesetztes go-e-
            // FORCE_STATE-Lock darf den übernehmenden Kanal nicht blockieren
            // — derselbe Ausweichweg wie beim Reset-Fallback, siehe
            // OCPPHubSplitter::ClearGoeForceLock()/tryClearGoeForceLock().
            if ($this->ReadPropertyString('CPID') !== '') {
                $this->anSplitter('ForceLockLoesen');
            }
            return OHUB_Text::t('Deactivated — the charge point no longer writes to the wallbox, a charge already in progress is not interrupted.');
        }
        return OHUB_Text::t('Activated — the charge point controls/counts normally again.');
    }

    // Additiv im OHUB_GetFunctions-Vertrag (siehe GetContractEntry()): null/fehlend = zählt
    // normal, sonst {source, instanceID} — zeigt auf den Eintrag, der für Verbund-Konsumenten
    // stattdessen zählt. Feldname/-form mit MeterHub/ChargerHub abgestimmt (13.09.2026).
    private function getDuplicateOfForContract(): ?array
    {
        if (!$this->IsDuplicate()) {
            return null;
        }
        return [
            'source'     => $this->ReadPropertyString('DuplicateOfSource'),
            'instanceID' => $this->ReadPropertyInteger('DuplicateOfInstanceID'),
        ];
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
        if ($this->ReadPropertyString('CPID') === '') {
            return false;
        }
        $antwort = $this->anSplitter('Reservieren', ['IdTag' => $IdTag, 'Bis' => $UntilIso]);
        if ($antwort === null) {
            return false;
        }
        $reservationId = (int)($antwort['reservierungsId'] ?? 0);

        $this->WriteAttributeString('ReservedIdTag', $IdTag);
        $this->WriteAttributeInteger('ReservedUntilTs', $untilTs);
        $this->WriteAttributeInteger('ReservationId', $reservationId);
        $this->SetValue('reserved_by', $IdTag);
        // Verbund-Regel 9b (13.09.2026): nutzersichtbare Datumsanzeigen TT.MM.JJJJ,
        // nicht ISO — reiner Anzeigewert, die eigentliche Zeit steckt in
        // ReservedUntilTs (Unix-Timestamp), wird hier nirgends zurückgeparst.
        $this->SetValue('reserved_until', date('d.m.Y H:i', $untilTs));
        return true;
    }

    public function CancelReservation(): void
    {
        if ($this->ReadPropertyString('CPID') !== '') {
            $this->anSplitter('ReservierungAufheben', ['ReservierungsId' => $this->ReadAttributeInteger('ReservationId')]);
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
        $externallyManaged = !in_array($managedBy, ['none', 'ems'], true);
        $entry = [
            'contractVersion'   => '1.7',
            // 1.1 (Dashboard-Fund 30.08.2026): Splitter sammelt die Einträge
            // ALLER eigenen Ladepunkte über OHUB_GetFunctions() ein — anders
            // als bei ChargerHub (1 Instanz = 1 Wallbox) reicht die
            // Splitter-ID als instanceID für Konsumenten NICHT, jeder Eintrag
            // braucht seine EIGENE (Ladepunkt-)Instanz-ID für Steuerungs-
            // aufrufe wie OHUBL_ManualStart(). Additiv, kein Bruch.
            // 1.2 (Diagnose-Feature 31.08.2026): blockReasonID additiv.
            // 1.3 (ChargerHub-Abstimmung 12.09.2026, Verfügbarkeits-Fund bei
            // WB2): lastSeenAt additiv, feldgleich zu CHUB_GetFunctions —
            // derselbe Feldname wie bei ChargerHub, damit ein Konsument (EMS/
            // Dashboard) dieselbe Alterungs-Schwellenwertlogik transport-
            // unabhängig anwenden kann, ohne Modbus/OCPP zu unterscheiden.
            // 1.4 (Dietmars Entscheidung über EMS 13.09.2026, Dual-Writer-
            // Zählung): duplicateOf additiv, feldgleich zu CHUB_GetFunctions
            // — null/fehlend = zählt normal, sonst {source, instanceID} zeigt
            // auf den stattdessen zählenden Eintrag. Rein nutzergesetzt (siehe
            // IsDuplicate()), betrifft NUR das Zählen (EMS' endgültige
            // Korrektur, von Dietmar bestätigt) — NICHT das Schreiben, siehe
            // `active`/`externallyManaged` unten für die Schreibfrage.
            // 1.5 (MeterHub-Abstimmung 13.09.2026, dieselbe Dual-Writer-
            // Erkennung): deviceSerial/deviceIP additiv (aus BootNotification/
            // Quell-IP, vorher nur intern) sowie active — verlässlichere
            // Erkennung als der bloße Zählerstand-Vergleich. active = false
            // bei externer Steuerhoheit (managedBy != none/ems) ODER
            // OHUBL_SetActive(false) — NICHT bei IsDuplicate().
            // 1.6 (EMS-Klarstellung 13.09.2026, Live-Verwechslung bei
            // Dietmar): `active` bedeutet AUSSCHLIESSLICH „darf gerade
            // steuern" (EMS/wir selbst) — NICHT „Ladepunkt in Betrieb/
            // verbunden" (dafür `ocpp_connected`/`lastSeenAt`). Ein extern
            // geregelter, aber weiterhin verbundener Ladepunkt (managedBy=
            // 'other') hat `active=false`, ist aber real in Betrieb — genau
            // dieser Unterschied hatte EMS live kurz verwechselt. Verbindlich
            // (EMS 0.42.4): „abgeschaltet" heißt für Konsumenten AUSSCHLIESSLICH
            // Instanzstatus 104, nicht `active`/`externallyManaged`. Additiv
            // `deactivated` ergänzt — eindeutiger Name, deckungsgleich mit
            // Status 104, ohne dass ein Konsument extra den Instanzstatus
            // abfragen muss.
            // 1.7 (EMS-Bitte 20.09.2026): stationMaxCurrentA/stationMinCurrentA
            // (Ampere) und phaseSwitchSupported (bool) additiv und optional —
            // nur vorhanden, wenn die Wallbox sie per GetConfiguration
            // gemeldet hat (`Station-MaxCurrent`/`MinChargingCurrent` sind
            // go-e-spezifisch, `ConnectorSwitch3to1PhaseSupported` OCPP-
            // Standard); fehlt ein Feld, ist der Wert unbekannt. Bewusst
            // eigene Namen neben minCurrent/maxCurrent: diese beiden sind
            // die vom Nutzer eingestellten Regelgrenzen, jene die Hardware-
            // Angabe der Wallbox. Die Phasenzahl (numberPhases) fehlt noch —
            // OCPP 1.6 meldet sie nicht direkt, sie ließe sich nur aus
            // Strom-Messwerten je Phase während einer Ladung ableiten.
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
            'externallyManaged' => $externallyManaged,
            'vehicleNameID'     => $this->GetIDForIdent('vehicle_name'),
            'transport'         => 'ocpp',
            'ocppVersion'       => '1.6',
            'blockReasonID'     => $this->GetIDForIdent('block_reason'),
            'lastSeenAt'        => $this->ReadAttributeInteger('LastSeenAt'),
            'duplicateOf'       => $this->getDuplicateOfForContract(),
            'deviceSerial'      => $this->ReadAttributeString('DeviceSerial'),
            'deviceIP'          => $this->ReadAttributeString('SourceIP'),
            // KORRIGIERT 13.09.2026: `active` = darf dieser Eintrag gerade
            // steuern — hängt NICHT mehr an IsDuplicate() (Zählen/Schreiben
            // sind orthogonal), sondern an genau denselben zwei Gründen wie
            // die Schreibsperre selbst. Sagt NICHTS über Betrieb/Verbindung
            // aus, siehe Feldkommentar 1.6 oben.
            'active'            => !$externallyManaged && !$this->IsDeactivated(),
            // 1.6: eindeutiger, unmissverständlicher Name für „per Formular-
            // Schalter/OHUBL_SetActive(false) abgeschaltet" — deckungsgleich
            // mit Instanzstatus 104.
            'deactivated'       => $this->IsDeactivated(),
        ];
        if ($this->ReadAttributeInteger('StationMaxCurrentA') > 0) {
            $entry['stationMaxCurrentA'] = $this->ReadAttributeInteger('StationMaxCurrentA');
        }
        if ($this->ReadAttributeInteger('StationMinCurrentA') > 0) {
            $entry['stationMinCurrentA'] = $this->ReadAttributeInteger('StationMinCurrentA');
        }
        $phaseSwitch = $this->ReadAttributeInteger('PhaseSwitchSupported');
        if ($phaseSwitch === 0 || $phaseSwitch === 1) {
            $entry['phaseSwitchSupported'] = ($phaseSwitch === 1);
        }
        return $entry;
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
            $this->SetValue('surplus_status', OHUB_Text::t('Passive — control authority with: %s', $this->managedByLabel($managedBy)));
            return;
        }
        if ($this->IsEmsActive()) {
            $this->SetValue('surplus_status', OHUB_Text::t('Passive — EMS active'));
            return;
        }
        if (!$this->GetValue('vehicle_plugged')) {
            $this->SetValue('surplus_status', OHUB_Text::t('No vehicle plugged in'));
            return;
        }
        if ($this->ReadAttributeBoolean('ManualStopActive')) {
            $this->SetValue('surplus_status', OHUB_Text::t('Passive — stopped manually'));
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
            $this->SetValue('surplus_status', OHUB_Text::t('Daily override active — charging at %d A', $maxCurrent));
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
            $this->SetValue('surplus_status', OHUB_Text::t('Readings outdated — control suspended'));
            return;
        }

        $surplusW = $this->FindGridSurplusW();
        if ($surplusW === null) {
            $this->SetValue('surplus_status', OHUB_Text::t('No grid meter found (MeterHub contract)'));
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
            $this->SetValue('surplus_status', sprintf(OHUB_Text::t('Not enough surplus (%.0f W)'), $surplusW));
            return;
        }

        $clamped = (int)min($maxCurrent, $ampere);
        if (!$this->GetValue('ctl_enable')) {
            IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
        }
        if ($this->GetValue('ctl_curr_limit') !== $clamped) {
            IPS_RequestAction($this->InstanceID, 'ctl_curr_limit', $clamped);
        }
        $this->SetValue('surplus_status', sprintf(OHUB_Text::t('Charging at %d A (%.0f W surplus)'), $clamped, $surplusW));
    }

    // SUITE.md „Verbund-Verbindungen im Formular sichtbar machen" (21.09.2026):
    // je Verbindung eine live berechnete Zeile (✅/⚠️/ℹ️/⛔) mit den tatsächlich
    // geltenden Werten und ihrer Quelle. Direkt beim Bau des Formulars
    // berechnet, kein Platzhalter-Label, das nachträglich gesucht werden müsste.
    private function connectionsPanel(): array
    {
        $lines = [];

        // 1) Splitter und Wallbox
        $cpid = $this->ReadPropertyString('CPID');
        $splitterId = $this->resolveSplitterId();
        if ($cpid === '') {
            $lines[] = OHUB_Text::t('⛔ Charge point identity missing. Without it no wallbox can be assigned to this charge point.');
        }
        if ($splitterId <= 0 || !@IPS_InstanceExists($splitterId)) {
            $lines[] = OHUB_Text::t('⛔ Not connected to any OCPPHub splitter. Without it neither readings arrive nor can the wallbox be controlled. You select the splitter at the top of the form, under the interface (“Gateway”).');
        } elseif ($cpid !== '') {
            $lib = @IPS_GetLibrary(IPS_GetInstance($splitterId)['ModuleInfo']['LibraryID'])['Version'] ?? '';
            $sName = OHUB_Text::t('Splitter #%d “%s”', $splitterId, IPS_GetName($splitterId)) . ($lib !== '' ? ' (OCPPHub ' . $lib . ')' : '');
            $ts = $this->ReadAttributeInteger('LastSeenAt');
            if ($ts <= 0) {
                $lines[] = OHUB_Text::t('⚠️ %s assigned, but the wallbox “%s” has never reported there. Check the endpoint and the charge point identity on the wallbox.', $sName, $cpid);
            } else {
                $frisch = (time() - $ts) < self::MAX_LAST_SEEN_AGE_SECONDS;
                $geraet = trim($this->ReadAttributeString('DeviceModel') . ' ' . ($this->ReadAttributeString('DeviceSerial') !== '' ? OHUB_Text::t('Serial no. %s', $this->ReadAttributeString('DeviceSerial')) : ''));
                $text = ($frisch ? '✅ ' : '⚠️ ') . $sName . ': ' . OHUB_Text::t('wallbox “%s”', $cpid) . ($geraet !== '' ? ' (' . $geraet . ')' : '') . ' ' . OHUB_Text::t('last seen %s', $this->agoText($ts)) . ($frisch ? '.' : OHUB_Text::t(', is considered not connected.'));
                $max = $this->ReadAttributeInteger('StationMaxCurrentA');
                $min = $this->ReadAttributeInteger('StationMinCurrentA');
                $sw = $this->ReadAttributeInteger('PhaseSwitchSupported');
                $grenzen = [];
                if ($max > 0) {
                    $grenzen[] = OHUB_Text::t('max. %d A', $max);
                }
                if ($min > 0) {
                    $grenzen[] = OHUB_Text::t('min. %d A', $min);
                }
                if ($sw === 0 || $sw === 1) {
                    $grenzen[] = $sw === 1 ? OHUB_Text::t('phase switching supported') : OHUB_Text::t('phase switching not supported');
                }
                $text .= count($grenzen) > 0 ? ' ' . OHUB_Text::t('Reported by the wallbox: %s.', implode(', ', $grenzen)) : ' ' . OHUB_Text::t('The wallbox has not (yet) reported its current range; the settings below apply.');
                $lines[] = $text;
            }
        }

        // 2) Steuerhoheit und Überschussladen
        $managedBy = $this->ReadPropertyString('ManagedBy');
        if ($this->IsDeactivated()) {
            $lines[] = OHUB_Text::t('ℹ️ Charge point is deactivated: authorization, charge enable and current limit are not written, measuring continues.');
        }
        if (!$this->ReadPropertyBoolean('EnableSurplusCharging')) {
            $lines[] = OHUB_Text::t('ℹ️ Standalone surplus charging is off, so no grid meter is searched for.');
        } elseif ($managedBy !== 'none') {
            $lines[] = OHUB_Text::t('ℹ️ Surplus charging is enabled but stays passive: control authority is with “%s”.', $this->managedByLabel($managedBy));
        } else {
            $emsIds = @IPS_GetInstanceListByModuleID(self::EMS_GUID) ?: [];
            if (count($emsIds) > 0 && $this->IsEmsActive()) {
                $lines[] = OHUB_Text::t('✅ EMS #%d “%s” is active and has priority, the standalone surplus charging stays passive.', $emsIds[0], IPS_GetName($emsIds[0]));
            } else {
                $lines[] = count($emsIds) > 0
                    ? OHUB_Text::t('ℹ️ EMS #%d is installed but not active: the standalone surplus charging controls by itself.', $emsIds[0])
                    : OHUB_Text::t('ℹ️ No EMS found: the standalone surplus charging controls by itself.');
                $meter = $this->findGridMeter();
                if ($meter === null) {
                    $lines[] = function_exists('MHUB_GetFunctions')
                        ? OHUB_Text::t('⚠️ No MeterHub meter with real-time grid power found. Without it, surplus charging pauses. A grid meter can be selected explicitly below.')
                        : OHUB_Text::t('⚠️ MeterHub is not installed, so there is no grid meter for surplus charging. It stays paused.');
                } else {
                    $wert = @GetValue($meter['powerID']);
                    $lines[] = OHUB_Text::t('✅ Grid meter: MeterHub #%d “%s”%s, grid power currently %s.', $meter['iid'], IPS_GetName($meter['iid']), $this->ReadPropertyInteger('SurplusMeterID') > 0 ? OHUB_Text::t(' (selected explicitly)') : OHUB_Text::t(' (detected automatically through the MeterHub contract)'), is_numeric($wert) ? OHUB_Text::t('%d W (+ import, − export)', round((float)$wert)) : OHUB_Text::t('not readable'));
                }
            }
        }

        // 3) Doppelte Anbindung
        $source = $this->ReadPropertyString('DuplicateOfSource');
        $targetId = $this->ReadPropertyInteger('DuplicateOfInstanceID');
        if ($source === '') {
            $lines[] = OHUB_Text::t('ℹ️ No duplicate connection entered: this entry counts normally in the stack.');
        } elseif ($targetId <= 0 || !@IPS_InstanceExists($targetId)) {
            $lines[] = OHUB_Text::t('⚠️ Marked as a duplicate, but the counting instance is not selected or no longer exists. Until this is fixed, this entry is not assigned cleanly in the stack.');
        } else {
            $lines[] = OHUB_Text::t('✅ Duplicate of %s #%d “%s”: counting happens there. Whether this charge point may write is decided by “Who is in control?” (currently: %s).', $source === 'chargerhub' ? 'ChargerHub' : OHUB_Text::t('OCPPHub charge point'), $targetId, IPS_GetName($targetId), $this->managedByLabel($managedBy));
            if ($this->hasDuplicateWriteConflict()) {
                $lines[] = OHUB_Text::t('⚠️ Both sides may write (the control authority is not with an external controller, neither here nor at the counting instance). Please set “Who is in control?” here or there.');
            }
        }

        // 4) Fahrzeug
        $vehicleVarId = (int)@IPS_GetObjectIDByIdent('vehicle_name', $this->InstanceID);
        $fahrzeug = $vehicleVarId > 0 ? trim((string)GetValue($vehicleVarId)) : '';
        $lines[] = $fahrzeug !== ''
            ? OHUB_Text::t('✅ Vehicle detected: “%s” (assigned by the dashboard).', $fahrzeug)
            : OHUB_Text::t('ℹ️ No vehicle assigned yet. The dashboard reports the assignment as soon as a vehicle is plugged in and detected.');

        $items = [];
        foreach ($lines as $line) {
            $label = ['type' => 'Label', 'caption' => $line];
            if (str_starts_with($line, '⛔')) {
                $label['color'] = 0xFF0000;
            }
            $items[] = $label;
        }
        return ['type' => 'ExpansionPanel', 'name' => 'ConnectionsPanel', 'caption' => OHUB_Text::t('🔗 Connections in the stack'), 'expanded' => true, 'items' => $items];
    }

    // SUITE.md „Wert kommt automatisch: Eingabefeld ersetzen" (21.09.2026):
    // findet die Automatik (MeterHub-Vertrag) einen Netzzähler und das Feld ist
    // leer, wird stattdessen eine schreibgeschützte 🔗-Zeile gezeigt; das Feld
    // liegt nur in einem eingeklappten Panel für bewusstes Überschreiben.
    // Eigene Auswahl: ✏️, Feld sichtbar. Nichts gefunden: ℹ️, Feld sichtbar.
    private function surplusMeterFieldElements(): array
    {
        $field = ['type' => 'SelectInstance', 'name' => 'SurplusMeterID', 'caption' => OHUB_Text::t('Grid meter (MeterHub)'), 'moduleID' => self::METERHUB_GUID];
        $forced = $this->ReadPropertyInteger('SurplusMeterID');
        if ($forced > 0) {
            $name = @IPS_InstanceExists($forced) ? OHUB_Text::t('“%s”', IPS_GetName($forced)) : OHUB_Text::t('(no longer exists)');
            return [
                ['type' => 'Label', 'caption' => OHUB_Text::t('✏️ Grid meter: MeterHub #%d %s (own selection, takes precedence over automatic detection)', $forced, $name)],
                $field,
            ];
        }
        $meter = $this->findGridMeter();
        if ($meter !== null) {
            return [
                ['type' => 'Label', 'caption' => OHUB_Text::t('🔗 Grid meter: MeterHub #%d “%s” (automatically through the MeterHub contract, grid power in real time)', $meter['iid'], IPS_GetName($meter['iid'])), 'color' => 0x2E8B3D],
                ['type' => 'ExpansionPanel', 'caption' => OHUB_Text::t('✏️ Use own grid meter instead'), 'expanded' => false, 'items' => [$field]],
            ];
        }
        return [
            ['type' => 'Label', 'caption' => OHUB_Text::t('ℹ️ Grid meter: none found automatically (MeterHub with real-time grid power), needed for surplus charging. Please select one below.')],
            $field,
        ];
    }

    private function agoText(int $ts): string
    {
        if ($ts <= 0) {
            return OHUB_Text::t('never');
        }
        $d = max(0, time() - $ts);
        if ($d < 90) {
            return OHUB_Text::t('%d s ago', $d);
        }
        if ($d < 5400) {
            return OHUB_Text::t('%d min ago', (int)round($d / 60));
        }
        if ($d < 172800) {
            return OHUB_Text::t('%d h ago', (int)round($d / 3600));
        }
        return OHUB_Text::t('%d days ago', (int)round($d / 86400));
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
        $meter = $this->findGridMeter();
        if ($meter === null) {
            return null;
        }
        $value = @GetValue($meter['powerID']);
        return is_numeric($value) ? max(0, -(float)$value) : null;
    }

    // Wie FindGridSurplusW(), liefert aber die gefundene Quelle (Instanz und
    // Variable) statt nur den Wert — Grundlage für die Verbindungs-Statuszeile
    // im Formular, damit dort benannt werden kann, WOHER der Wert kommt.
    private function findGridMeter(): ?array
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
                    $best = ['iid' => (int)$iid, 'powerID' => $powerID, 'billing' => $billing];
                }
            }
        }
        return $best;
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
