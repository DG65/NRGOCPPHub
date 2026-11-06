<?php

// ===========================================================================
// OCPPHub Splitter — OCPP-1.6J-Central-System für IP-Symcon. Nimmt WebSocket-
// Verbindungen von Wallboxen über einen Symcon-Hook entgegen (kein externer
// Daemon, kein manuelles WebSocket-Framing) und routet nach Charge-Point-
// Identity (URL-Pfad hinter dem Hook) an die passende OCPPHub-Ladepunkt-
// Instanz.
//
// Architektur-Entscheidung (30.08.2026): Symcons eigenes WebHook/WebSocket-
// Gespann übernimmt Handshake und Framing komplett — bestätigt durch Studium
// des offiziellen symcon/OCPP-Moduls (WebHookModule + ProcessHookData() +
// WC_PushMessage() an die eingebaute WebSocket-Server-Instanz). Das Modul
// hier ist eine EIGENSTÄNDIGE Implementierung (kein Code aus symcon/OCPP
// übernommen, das Repo hat keine Lizenzdatei) — nur der grundsätzliche
// Mechanismus (RegisterHook/ProcessHookData/WC_PushMessage) ist Symcon-
// Standard-SDK-API und wurde daraus als Vorgehen übernommen.
//
// Aktueller Stand (14.09.2026): Kernprotokoll, PV-Überschussladen,
// Reservierung, RFID-Autorisierung mit Kundenverwaltung/Verbrauchslimits
// (Betriebsart ②) sind gebaut UND an echter go-e-Hardware live verifiziert
// (mehrere echte Ladevorgänge inkl. Stopp/Neustart, Kartenauflegen,
// Kundenverwaltung). Andere OCPP-1.6J-Wallboxen sind ungetestet, siehe
// Formular-Hinweis dort. NOCH NICHT enthalten (Stufe 3): Tarife/
// Kostenberechnung, Berichte/CSV-Export, Lastverteilung über mehrere eigene
// Ladepunkte hinweg — siehe .docs/pflichtenheft.md „Ausbaustufen".
// ===========================================================================

require_once __DIR__ . '/../libs/OHUB_Text.php';

class OCPPHubSplitter extends IPSModule
{
    // Eingebaute Symcon-Kern-Instanz "WebHook Control" — verwaltet alle
    // registrierten Hooks (Property "Hooks", JSON-Liste aus {Hook,TargetID})
    // UND pusht Daten asynchron an eine darüber offene WebSocket-Verbindung
    // (WC_PushMessage). GUID verifiziert gegen zwei offizielle Symcon-Quellen
    // (symcon/OCPP UND symcon/SymconMisc/libs/WebHookModule.php).
    private const WEBHOOK_CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    private const LADEPUNKT_GUID = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';
    // Datenfluss (Symcon-Review 30.10.2026): Splitter -> Kinder (Ladepunkt, Abrechnung) per
    // SendDataToChildren; die Gegenrichtung (Kind -> Splitter, ForwardData) trägt die in
    // module.json unter `implemented` stehende Schnittstelle, siehe .docs/datenfluss.md.
    private const DATA_ZUM_KIND = '{DD617475-D014-4559-BC1C-6A39FCA4EDEE}';
    private const ABRECHNUNG_GUID = '{64980198-6B36-45D5-A84F-A0EAE9CCC63A}';
    private const CHARGERHUB_GUID = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';

    private const OCPP_CALL       = 2;
    private const OCPP_CALLRESULT = 3;
    private const OCPP_CALLERROR  = 4;

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.4.1';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    // „Über dieses Modul" (14.09.2026, SUITE.md Formular-Konvention Punkt 5)
    // — seit dem Stable-Start (27.10.2026) liegt LICENSE auf `main`, dorthin
    // zeigt die URL (Dietmars Fund bei MeterHub: blind auf einen Branch ohne
    // LICENSE verlinkt zeigte die alte Lizenz).
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
        '🌐 New: German and English. The form, the messages in the log and the debug output follow the Symcon language setting.',
        '🔌 Technical: splitter, charge points, Billing and configurator now talk to each other through the Symcon data flow (messages instead of direct function calls on foreign instances), as the store review requires. For this, the charge points, Billing and the configurator hang below the splitter as their real parent device. Existing instances are connected automatically the first time you apply changes after the update. Behavior does not change.',
        'New: “🧡 About this module” at the very bottom of the form (license/donations); the feedback notice is now a panel of its own that you can hide, instead of a line of text (store convention addition).',
        'New: “👋 What is this module for?” — a new panel at the very top of the form briefly explains what this instance does and what it is good for (store convention addition, same pattern as the “What’s new” panel below it).',
        'New: 🎪 Demo mode (form field on the splitter) — for Dietmar’s planned public demo instance of the whole NRG-Stack. When enabled, OCPPHub rejects every real charging control command (RemoteStartTransaction/SetChargingProfile/Reset) on the server side, whether triggered manually, by PV surplus charging or by automatic vehicle authorization — a visitor to the demo cannot switch Dietmar’s real wallbox with it. The customer management tile is also made read-only automatically.',
        'Critical fix (live finding, discovered right after the reset/frc fallback was introduced): if RemoteStartTransaction failed BECAUSE the wallbox was already running another session (e.g. a go-e that had started locally by itself after a reset), our fallback still triggered a reset and thereby interrupted a charge that was already running fine — visible as short charging pulses instead of one stable session. The fallback now first checks whether the charge point is actually charging and only steps in if it is not.',
        'go-e fallback for a stuck charge enable: if RemoteStartTransaction fails on a go-e wallbox, OCPPHub now automatically tries to reset its private FORCE_STATE register — first through ChargerHub (CHUB_ClearForceLock(), if installed, even if its instance is deactivated), otherwise through its own deliberately minimal Modbus write, so that this also works WITHOUT ChargerHub installed. Without effect on any other manufacturer, no risk.',
        'New manufacturer-neutral fallback when a charge start is rejected: if `RemoteStartTransaction` fails, OCPPHub now automatically follows up with a standard OCPP `Reset` (soft) — a mandatory part of OCPP 1.6 that every conforming manufacturer must support, not a special route for a single brand.',
        'Critical fix (live finding): go-e repeatedly rejected `RemoteStopTransaction` during a running charge although the session demonstrably was running — so charge enable “off” had no effect. Automatic fallback: if the regular stop is rejected, OCPPHub now sends a current limit of 0 A by itself, which go-e accepts reliably and which actually ends the charge.',
        'Critical fix (live finding, first real charging attempt): every rejection of a card/access entry (not registered, blocked, expired, time window, consumption limit, reservation) now immediately shows the exact reason at the charge point (`block_reason`) — before, it ended up only invisibly in the system log. The manual “charge enable” switch also first tries the real, registered access entry of an already detected vehicle (new function OHUB_ManualStart()) instead of an internal placeholder that could never have worked under operating mode ② anyway.',
        'New diagnostic function OHUB_GetConfigurationKeys() — queries any OCPP configuration keys of a wallbox; the answer is now ALWAYS written permanently to the system log (not only on rejection), because a successful GetConfiguration response has no “status” field and was therefore only visible briefly in the debug window.',
        'New: automatic charge enable for detected vehicles (“something like autocharge”) — if the dashboard detects a vehicle with an active access entry by time correlation, its card is automatically “presented” in operating mode ② (same check as a real card presentation, all limits/time windows apply identically). Design agreed with the dashboard.',
        'New diagnostic for an unambiguous charge rejection (RemoteStartTransaction/SetChargingProfile): automatically asks the linked Tessie vehicle and optionally Tibber Grid Rewards for a possible explanation — visible at the charge point in the new variable `block_reason`.',
        'Critical fix (dashboard finding, live test): go-e rejected every manual charge start with “Rejected” without that being visible anywhere. Cause: RemoteStartTransaction lacked the connectorId field — go-e did not know which connector to start. Now hard-set to 1 (the connector that actually charges, confirmed live on WB2). In addition: every recognizable rejection (CALLERROR or a “status” other than “Accepted”) of a call we sent is now also written permanently to the system log, no longer only to the transient debug window.',
        'Critical fix (dashboard finding, live test): manual charge start via dashboard/ctl_enable failed with a PHP fatal error. Cause: the global function Symcon generates for RemoteStart() ignores PHP default values on parameters — a call with only 2 instead of 3 arguments triggered an ArgumentCountError. Default value removed from the source code so this cannot happen again.',
        'Warning added: “OCPPHub Billing” is created automatically as a child of THIS splitter instance — never create such an instance yourself in addition, a second one is never used (found live: cards/customers in a manually created second instance stayed without effect).',
        'Every card presentation (Authorize) is now additionally written to the permanent Symcon system log — before, it was only visible via SendDebug and thus irretrievably gone once the debug window was closed. Handy e.g. for looking up the idTag of a new card afterwards in order to create it in the Billing instance.',
        'Critical fix: every MeterValues message (power/energy/SoC) made the splitter crash with a fatal error — so power/energy_total NEVER arrived, regardless of the wallbox. Cause: json_decode() without associative mode for nested OCPP messages. Affected every wallbox, found live on WB2.',
        'Stage 2: new operating mode selection field (① Single user / ② Multiple users). With ②, every card presentation is checked centrally against the new “OCPPHub Billing” instance (customers, access entries, consumption limits) — it creates itself automatically.',
        'Reservation (ReserveNow/CancelReservation) added, usable regardless of the operating mode — backend functions are on the charge point (OHUBL_Reserve/CancelReservation).',
        'idTag direct assignment: if a card is already assigned to a vehicle, the vehicle name is now set immediately in operating mode ②, without waiting for the dashboard’s time correlation.',
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
        $this->RegisterPropertyBoolean('Active', true);
        // Betriebsart (Stufe 2, siehe .docs/architektur.md „Formular-Struktur"):
        // 1 = Einzelnutzer (Default, kein RFID-Zwang), 2 = Mehrere Nutzer
        // (zentrale Autorisierung über die Abrechnung-Instanz). Ersetzt die
        // ursprünglich als Splitter-Formular-Panels geplanten Einzelschalter
        // — die Kundenverwaltung selbst lebt als eigene Instanz (siehe
        // ensureAbrechnung()), diese Property schaltet nur das VERHALTEN
        // (Authorize/Limits/Reservierung) frei, keine Formular-Panels.
        $this->RegisterPropertyInteger('Betriebsart', 1);
        $this->RegisterAttributeInteger('AbrechnungID', 0);
        // Vorführmodus (01.09.2026, Anfrage Dashboard-Sitzung — Dietmar baut
        // eine öffentliche Demo-Instanz mit eigenem WebFront/Login): lehnt
        // echte OCPP-Steuerbefehle (RemoteStart/Stop/SetChargingProfile)
        // serverseitig ab, nicht nur optisch verborgen — ein Besucher der
        // Demo darf niemals Dietmars echte Wallbox schalten können. Greift
        // zentral in OCPPHubLadepunkt::RequestAction() für ctl_enable/
        // ctl_curr_limit UND blockiert dieselbe Prüfung in OCPPHubAbrechnung
        // (dort: schreibender Zugriff auf echte Kundendaten). Default AUS —
        // nur für die dedizierte Demo-Instanz gedacht, niemals versehentlich
        // in Produktion aktiv.
        $this->RegisterPropertyBoolean('Vorfuehrmodus', false);
        // Basic-Auth optional (leerer Nutzername = kein Schutz). Zugangsdaten-
        // Konvention (Verbund-Regel 7): Passwort nur als Formular-Eingabe
        // (Property), nach Übernahme gehasht ins Attribut, Property geleert.
        $this->RegisterPropertyString('BasicAuthUsername', '');
        $this->RegisterPropertyString('BasicAuthPassword', '');
        $this->RegisterAttributeString('BasicAuthPasswordHash', '');

        // Fortlaufende OCPP-TransactionId je Splitter (nicht je Ladepunkt —
        // OCPP verlangt nur Eindeutigkeit, keine Ladepunkt-Bindung).
        $this->RegisterAttributeInteger('NextTransactionId', 1);
        // OCPP verlangt beim Abbrechen einer Reservierung dieselbe
        // reservationId, mit der sie angelegt wurde — siehe ReserveNow()/
        // CancelReservation().
        $this->RegisterAttributeInteger('NextReservationId', 1);

        // Zuletzt gesehene, noch nicht als Ladepunkt-Instanz angelegte
        // Charge-Point-Identities — vom Konfigurator gelesen.
        // Struktur: { "<cpid>": <unix-timestamp letzte Sichtung> }
        $this->RegisterAttributeString('SeenChargePoints', '{}');

        // uniqueId → {cpid, action, ts} für gesendete Aufrufe, siehe
        // rememberPendingCall()/resolvePendingCall() — nur für die Block-
        // Diagnose (Ladeablehnung erklären), keine vollständige Historie.
        $this->RegisterAttributeString('PendingCalls', '{}');

        // go-e-frc-Ausweichweg (01.09.2026, siehe .docs/architektur.md
        // „Ladeablehnung erklären" — Recherche zu trx/frc) — Herstellername
        // je cpid aus BootNotification gemerkt, damit der Modbus-Ausweichweg
        // NUR bei go-e versucht wird, nicht bei jedem Hersteller blind.
        // Struktur: { "<cpid>": "<chargePointVendor>" }
        $this->RegisterAttributeString('ChargePointVendor', '{}');

        // Hook-Registrierung braucht die WebHook-Control-Instanz, die beim
        // ersten ApplyChanges() nach einem Symcon-Neustart evtl. noch nicht
        // bereit ist (Muster aus symcon/SymconMisc/libs/WebHookModule.php,
        // dort ausdrücklich als Kernel-Timing-Absicherung dokumentiert) —
        // deshalb zusätzlich auf KR_READY horchen und dann erneut versuchen.
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->RegisterHook($this->hookPath());
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Zugangsdaten-Konvention (Verbund-Regel 7): Klartext-Passwort aus der
        // Formulareingabe hashen. FIX 30.08.2026 (Live-Fund): das Leeren der
        // Property lief vorher über einen rekursiven ApplyChanges()-Aufruf,
        // der die Funktion per return() VORZEITIG verließ — RegisterHook()
        // und SetStatus() liefen dadurch nie, wenn gerade ein Passwort gesetzt
        // wurde. Jetzt: Hook-Registrierung und Status laufen IMMER zuerst,
        // das Leeren der Property ist nur noch ein harmloser Nachlauf danach.
        $plainPassword = $this->ReadPropertyString('BasicAuthPassword');
        if ($plainPassword !== '') {
            $this->WriteAttributeString('BasicAuthPasswordHash', password_hash($plainPassword, PASSWORD_DEFAULT));
        }

        // Nur im laufenden Kernel-Betrieb registrieren — direkt beim Symcon-
        // Start ist die WebHook-Control-Instanz evtl. noch nicht bereit
        // (siehe MessageSink()/KR_READY oben, gleiches Muster wie
        // symcon/SymconMisc/libs/WebHookModule.php).
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->RegisterHook($this->hookPath());
            // Abrechnung-Instanz ist "obligatorischer" Bestandteil (siehe
            // .docs/architektur.md „Instanzmodell") — existiert unabhängig
            // von der gewählten Betriebsart, nur im Kernel-Ready-Zustand
            // anlegen (IPS_CreateInstance() sonst evtl. noch nicht sicher).
            $this->ensureAbrechnung();
        }

        $this->SetStatus($this->ReadPropertyBoolean('Active') ? 102 : 104);

        if ($plainPassword !== '') {
            @IPS_SetProperty($this->InstanceID, 'BasicAuthPassword', '');
            @IPS_ApplyChanges($this->InstanceID);
        }
    }

    // Legt bei Erstanlage eine OCPPHub-Abrechnung-Instanz an, falls noch
    // keine (gültige) referenziert ist — "obligatorischer" Bestandteil,
    // siehe .docs/architektur.md. Nur EINE je Splitter.
    private function ensureAbrechnung(): int
    {
        $existing = $this->ReadAttributeInteger('AbrechnungID');
        if ($existing > 0 && @IPS_InstanceExists($existing)) {
            $this->abrechnungVerbinden($existing);
            return $existing;
        }
        $newId = @IPS_CreateInstance(self::ABRECHNUNG_GUID);
        if (!$newId) {
            return 0;
        }
        @IPS_SetParent($newId, $this->InstanceID);
        @IPS_SetName($newId, IPS_GetName($this->InstanceID) . OHUB_Text::t(' Billing'));
        $this->abrechnungVerbinden($newId);
        @IPS_ApplyChanges($newId);
        $this->WriteAttributeInteger('AbrechnungID', $newId);
        return $newId;
    }

    // Hängt die Abrechnung als Kind an diesen Splitter (Datenfluss, Symcon-Review 30.10.2026). Bestands-
    // Abrechnungen waren bisher nur über das Attribut AbrechnungID gebunden und werden hier beim
    // nächsten Übernehmen einmalig verbunden. Eine bereits anderswo verbundene Abrechnung bleibt unberührt.
    private function abrechnungVerbinden(int $abrechnungId): void
    {
        if ((int)(IPS_GetInstance($abrechnungId)['ConnectionID'] ?? 0) === 0) {
            @IPS_ConnectInstance($abrechnungId, $this->InstanceID);
        }
    }

    // Öffentlicher Zugriff auf die tatsächliche, funktionale Bindung
    // Splitter↔Abrechnung (das Attribut, NICHT die Baumposition — Dietmars
    // Frage 01.09.2026: "gibt es keine andere Möglichkeit, als die
    // Abrechnungs-Instanz direkt unter die Splitter-Instanz zu nageln?").
    // `IPS_SetParent()` in ensureAbrechnung() ist nur ein einmaliger,
    // rein kosmetischer Startort bei Neuanlage — die Abrechnung-Instanz
    // kann jederzeit im Objektbaum verschoben werden (z. B. für eine
    // aufgeräumtere WebFront-Einordnung), ohne die Funktion zu verlieren,
    // solange diese ID hier unverändert bleibt. Abrechnung::
    // isRegisteredWithSplitter() nutzt genau das, um verwaiste/falsche
    // Instanzen zu erkennen — siehe .docs/architektur.md „Instanzmodell".
    public function GetAbrechnungID(): int
    {
        return $this->ReadAttributeInteger('AbrechnungID');
    }

    // Vorführmodus-Abfrage für Ladepunkt/Abrechnung (01.09.2026) — siehe
    // RegisterPropertyBoolean('Vorfuehrmodus', ...) in Create().
    public function IsDemoMode(): bool
    {
        return $this->ReadPropertyBoolean('Vorfuehrmodus');
    }

    // Trägt diese Instanz als Ziel für $Hook in die "Hooks"-Property der
    // eingebauten WebHook-Control-Instanz ein (Standard-Community-Muster,
    // da es dafür keine eigene WHC_RegisterHook()-API-Funktion gibt —
    // verifiziert gegen symcon/SymconMisc/libs/WebHookModule.php, eigene
    // Umsetzung statt Codeübernahme).
    private function RegisterHook(string $Hook): void
    {
        $ids = @IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL_GUID);
        if (!$ids) {
            return;
        }
        $hooks = json_decode((string)@IPS_GetProperty($ids[0], 'Hooks'), true) ?: [];
        $found = false;
        foreach ($hooks as $index => $entry) {
            if (($entry['Hook'] ?? '') === $Hook) {
                if ((int)($entry['TargetID'] ?? 0) === $this->InstanceID) {
                    return; // schon korrekt registriert
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                $found = true;
            }
        }
        if (!$found) {
            $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
        }
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
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
                        ['type' => 'Label', 'caption' => 'What this instance does: the splitter is the OCPP “Central System” — the counterpart that wallboxes connect to via WebSocket. It accepts any number of simultaneous wallbox connections, tells them apart by their charge point identity (the last path segment of the URL) and forwards every incoming OCPP message to the matching “OCPPHub Charge Point” instance. This splitter instance itself shows no individual wallbox and no charging power — that is the sole responsibility of the charge point instance.'],
                        ['type' => 'Label', 'caption' => '📦 Instance model at a glance: splitter (this instance, exactly once) → several “OCPPHub Charge Point” instances (one per wallbox/connector) → optionally “OCPPHub Configurator” (handy for the first setup, shows wallboxes that are already connected but not yet created, for one-click creation). Each charge point instance must be connected to THIS splitter (you select it at the top of the charge point form, under the interface “Gateway”) — this is NOT derived automatically from the object tree position, because instances can be moved freely to other categories in the console without anything changing in the actual assignment.'],
                        ['type' => 'Label', 'caption' => OHUB_Text::t('🔌 Set up a wallbox: enter the following as the backend/server URL in the wallbox’s own OCPP configuration: %s/<Charge-Point-Identity>. <Charge-Point-Identity> is a freely selectable name that the wallbox itself sends with every message (e.g. “WB1”) — it does NOT have to be created here beforehand, it appears automatically in the “OCPPHub Configurator” after the first connection attempt. The subprotocol is “ocpp1.6”, the message format is OCPP-J (JSON over WebSocket) — no separate port, no external process, Symcon handles the WebSocket handshake completely itself through its built-in hook mechanism.', $this->hookPath())],
                        ['type' => 'Label', 'caption' => 'ℹ️ Scope (current state): complete OCPP 1.6J core protocol, standalone PV surplus charging as a fallback without EMS, reservation, and — from operating mode ② — central RFID authorization with customer management/consumption limits (see the “OCPPHub Billing” instance). Deliberately NOT included YET: tariffs/cost calculation, reports/CSV export, reservation fee, load balancing across several of your own charge points — that is stage 3 (see `.docs/pflichtenheft.md`).'],
                        ['type' => 'Label', 'caption' => '🔎 Troubleshooting: if a wallbox does not report in, first enable the debug output of this instance (console → this instance → debug messages) and wait for a connection attempt by the wallbox — every incoming request is logged there with method and path before anything is checked. If nothing arrives at all: check the URL/path on the wallbox. If something arrives but is rejected: usually Basic Auth (see below) or an OCPP detail of this wallbox that is not yet understood — please report it on GitHub.'],
                        ['type' => 'Label', 'caption' => '⚠️ Untested against most OCPP 1.6J wallboxes except go-e — for other manufacturers, please give feedback via GitHub if something does not fit (measurand names/units in MeterValues, ChargingRateUnit in SetChargingProfile).'],
                        ['type' => 'Label', 'caption' => '• go-e Gemini/HOME+: OCPP 1.6J from firmware 56.1 (ideally 56.8 or later), activation via app, WSS + HTTP Basic Auth recommended. Reference/test hardware of this module, see “What’s new” above for the current live test status.'],
                        ['type' => 'Label', 'caption' => '• KEBA P30: OCPP 1.6 only on the x-series — the c-series cannot do OCPP (use ChargerHub via Modbus/UDP there).'],
                        ['type' => 'Label', 'caption' => '• Alfen Eve (Single/Double Pro-line): OCPP is the native primary protocol there, should work well — not tested myself yet.'],
                        ['type' => 'Label', 'caption' => '• Heidelberg Energy Control: can NOT do OCPP (Modbus RTU only) — use ChargerHub for it, not OCPPHub.'],
                        ['type' => 'Label', 'caption' => '🧩 Stack: OCPPHub is the OCPP sibling module of ChargerHub (Modbus TCP) — both report wallboxes to the EMS and the dashboard through a contract with identical fields (`OHUB_GetFunctions`/`CHUB_GetFunctions`), so it makes no difference to the consuming modules whether a wallbox is connected via Modbus or OCPP.'],
                    ],
                ],
                $this->connectionsPanel(),
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Active',
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Vorfuehrmodus',
                    'caption' => '🎪 Demo mode — real charging control disabled',
                ],
                ['type' => 'Label', 'caption' => '⚠️ ONLY intended for a publicly accessible demo instance: rejects “charge enable”/current limit as well as changes in the customer management on the server side (not just hidden) — Dietmar’s real wallbox can then no longer be switched through this instance, not even by the standalone PV surplus charging. Leave OFF for normal operation.'],
                [
                    'type'    => 'Label',
                    'caption' => OHUB_Text::t('WebSocket endpoint for wallboxes: %s/<Charge-Point-Identity>', $this->hookPath()),
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Betriebsart',
                    'caption' => 'Operating mode',
                    'width'   => '560px',
                    'options' => [
                        ['caption' => '① Single user — no RFID required, every card is accepted', 'value' => 1],
                        ['caption' => '② Multiple users — central authorization via the Billing instance', 'value' => 2],
                    ],
                ],
                ['type' => 'Label', 'caption' => 'ℹ️ With ①, the customer management/consumption limits can already be set up in the “OCPPHub Billing” instance (it always exists) but are NOT evaluated — every card charges unchecked. With ②, every card presentation is checked against the access entries maintained there. Reservations (see the charge point instance) are enforced regardless of the operating mode as soon as one is active.'],
                ['type' => 'Label', 'caption' => '⚠️ “OCPPHub Billing” is created automatically as a DIRECT CHILD OF THIS splitter instance (found below it in the object tree) — a deliberately unusual pattern (no other instance in this stack creates a configuration instance below itself on its own), but standard in OCPPHub. NEVER create an “OCPPHub Billing” instance yourself in addition (e.g. via the module management) — such a second instance is never used by this splitter, no matter where in the tree it sits, and every card/customer maintained in it stays without effect.'],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔐 Basic Auth (optional)',
                    'items'   => [
                        ['type' => 'Label', 'caption' => 'Only needed if Symcon is reachable from a network that is not fully trustworthy (e.g. if the WebSocket endpoint is made reachable from outside through a port forward) — usually unnecessary on a plain home network. An empty user name = no Basic Auth, every wallbox may connect.'],
                        ['type' => 'Label', 'caption' => 'If a user name is set, the wallbox must store exactly the same user name and password for HTTP Basic Auth in its own OCPP configuration — otherwise every connection is rejected with HTTP 401 (visible in the debug output as “Basic Auth rejected”).'],
                        ['type' => 'Label', 'caption' => 'Credentials convention (stack rule 7): after applying, the password is stored hashed immediately and this field is cleared automatically — so when you open it again, the existing password is never in plain text here, not even for you. To change it, just enter a new one; leaving it empty keeps the existing password.'],
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'BasicAuthUsername',
                            'caption' => 'User name',
                        ],
                        [
                            'type'    => 'PasswordTextBox',
                            'name'    => 'BasicAuthPassword',
                            'caption' => 'New password (leave empty to keep the existing one)',
                        ],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => '🔄 Force apply (without changing the form)', 'onClick' => "IPS_ApplyChanges(\$id); echo '" . OHUB_Text::t('✅ ApplyChanges() executed.') . "';"],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Deactivated'],
            ],
        ];

        // Forum-/Rückmeldungshinweis (Formular-Konvention Punkt 4) — noch
        // kein Symcon-Forum-Thread, deshalb GitHub als Ziel; auf ein
        // eigenes dismissibles ExpansionPanel umgestellt (14.09.2026,
        // EMS-Fund über Dietmar — war bisher ein RowLayout, Referenz
        // MeterHub::ForumHint()). Seit 16.09.2026: echter Forum-Thread
        // steht (Dietmar hat ihn selbst gepostet), Link umgestellt.
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => '💬 Feedback',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub is an early beta — feedback is welcome in the Symcon forum.'],
                    ['type' => 'Button', 'caption' => 'Go to forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Do not show again', 'onClick' => 'OHUB_DismissReviewHint($id);'],
                ],
            ];
        }

        // „🧡 Über dieses Modul" (Formular-Konvention Punkt 5, 14.09.2026,
        // EMS-Fund über Dietmar) — ganz unten, NACH dem Forum-Hinweis,
        // bewusst NICHT dismissible. Wortlaut verbundweit identisch.
        $form['elements'][] = $this->licenseHint();

        // „Was ist neu"-Banner ganz oben, vor dem Doku-Panel.
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        // „Wozu dieses Modul?" ganz vorn, VOR dem News-Banner (Formular-
        // Konvention Punkt 0).
        $intro = $this->purposeIntroPanel();
        if ($intro !== null) {
            array_unshift($form['elements'], $intro);
        }

        return json_encode($form);
    }

    // „Über dieses Modul" (SUITE.md Formular-Konvention Punkt 5) — bewusst
    // NICHT dismissible (kein name/Attribut), Wortlaut verbundweit
    // identisch ("Variante A").
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
                ['type' => 'Label', 'caption' => 'OCPPHub connects wallboxes through the manufacturer-independent standard protocol OCPP 1.6J — the wallbox connects to Symcon via WebSocket, not the other way round. This splitter instance is the counterpart (“Central System”): it accepts any number of wallbox connections and distributes incoming messages to the matching “OCPPHub Charge Point” instance.'],
                ['type' => 'Label', 'caption' => 'The benefit: set charge enable/current limit, charging power/energy as ordinary Symcon variables, standalone PV surplus charging even without an EMS, and optionally central RFID authorization with customer management/consumption limits — as a basis for dashboards, an energy management system (EMS) or simply to control a wallbox from within Symcon.'],
                ['type' => 'Label', 'caption' => 'Easy way to get started: “OCPPHub Configurator” shows wallboxes that are already connected but not yet created, for one-click creation. If a wallbox cannot do OCPP (e.g. Heidelberg Energy Control), ChargerHub (Modbus TCP) is the matching sibling module.'],
                ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'OHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function DismissReviewHint(): void
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    // „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet
    // leer), bis der Nutzer „Verstanden" klickt. Neuinstallation sieht es
    // einmalig. Muster wie ChargerHub.
    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 New in this module — please take a quick look and check your settings if needed:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . OHUB_Text::t($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'OHUB_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => OHUB_Text::t('🆕 New in version %s', self::NEWS_VERSION), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    // SUITE.md „Verbund-Verbindungen im Formular sichtbar machen" (21.09.2026):
    // je Verbindung eine live berechnete Zeile (✅/⚠️/ℹ️/⛔), nie ein
    // statischer Satz. Direkt beim Bau des Formulars berechnet, es gibt kein
    // Platzhalter-Label, das nachträglich gesucht werden müsste.
    private function connectionsPanel(): array
    {
        $lines = [];

        if (!$this->ReadPropertyBoolean('Active')) {
            $lines[] = OHUB_Text::t('⚠️ This splitter is deactivated: incoming wallbox messages are ignored, and it reports no charge points to the stack.');
        }

        // Abrechnung (Betriebsart-abhängig)
        $abrId = $this->ReadAttributeInteger('AbrechnungID');
        $betriebsart = $this->ReadPropertyInteger('Betriebsart');
        if ($abrId > 0 && @IPS_InstanceExists($abrId)) {
            $wirkung = $betriebsart === 2
                ? OHUB_Text::t('Operating mode ②: every card presentation is checked against the access entries maintained there.')
                : OHUB_Text::t('Operating mode ①: access entries maintained there are NOT evaluated, every card charges.');
            $lines[] = OHUB_Text::t('✅ Billing #%d “%s” connected. %s', $abrId, IPS_GetName($abrId), $wirkung);
        } else {
            $lines[] = OHUB_Text::t('⚠️ No Billing instance stored. It will be created automatically at the next “Apply changes”; until then there is neither customer management nor card checking.');
        }

        // Ladepunkte dieses Splitters
        $seen = $this->GetSeenChargePoints();
        $ownCpids = [];
        $rows = [];
        foreach ($this->kindVertraege() as $kind) {
            $entry = $kind['eintrag'];
            $lpId = (int)($entry['instanceID'] ?? 0);
            $cpid = $kind['cpid'];
            $ownCpids[] = $cpid;
            $ts = (int)($entry['lastSeenAt'] ?? 0);
            $frisch = $ts > 0 && (time() - $ts) < 900;
            $zustand = !empty($entry['deactivated']) ? OHUB_Text::t('deactivated') : ($frisch ? OHUB_Text::t('connected') : OHUB_Text::t('not connected'));
            $rows[] = OHUB_Text::t('%s #%d (%s, last seen %s)', $cpid !== '' ? $cpid : OHUB_Text::t('(without identity)'), $lpId, $zustand, $this->agoText($ts));
        }
        if (count($rows) > 0) {
            $lines[] = OHUB_Text::t('✅ %d charge point(s) assigned: %s.', count($rows), implode('; ', $rows));
        }
        $ohne = array_values(array_diff(array_keys($seen), $ownCpids));
        if (count($ohne) > 0) {
            $lines[] = OHUB_Text::t('⚠️ Reported, but without a charge point instance: %s. Create them with one click in the “OCPPHub Configurator”.', implode(', ', $ohne));
        }
        if (count($rows) === 0 && count($ohne) === 0) {
            $lines[] = OHUB_Text::t('ℹ️ No wallbox has reported here yet and no charge point is assigned. Enter the endpoint (see below) on the wallbox.');
        }

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

    private function hookPath(): string
    {
        return '/hook/ocpphub/' . $this->InstanceID;
    }

    // ---------------------------------------------------------------------
    // Eingehend: WebHook-Callback (Symcon-SDK-Standardmechanismus)
    // ---------------------------------------------------------------------

    protected function ProcessHookData()
    {
        // Ganz am Anfang, VOR jeder Prüfung — Diagnosehilfe (Live-Fund
        // 30.08.2026: bei einer abgelehnten Verbindung gab es bislang gar
        // keine Debug-Ausgabe, unklar ob die Anfrage OCPPHub überhaupt
        // erreicht hat).
        $this->SendDebug('OCPPHub Hook', ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?'), 0);

        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SendDebug('OCPPHub', OHUB_Text::t('Instance inactive — request ignored.'), 0);
            return;
        }

        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            $this->SendDebug('OCPPHub', OHUB_Text::t('Empty request body (possibly a pure WebSocket handshake without a data frame).'), 0);
            return;
        }

        $prefix = $this->hookPath() . '/';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $pos = strpos($uri, $prefix);
        if ($pos === false) {
            $this->SendDebug('OCPPHub', OHUB_Text::t('Hook without charge point identity: %s', $uri), 0);
            return;
        }
        $cpid = rawurldecode(trim(strtok(substr($uri, $pos + strlen($prefix)), '?')));
        if ($cpid === '') {
            return;
        }

        if (!$this->checkBasicAuth()) {
            http_response_code(401);
            return;
        }

        $this->SendDebug('OCPPHub Receive [' . $cpid . ']', $raw, 0);
        $this->rememberSeenChargePoint($cpid);

        // FIX 30.08.2026 (Live-Fund WB2, Fatal Error bei jedem MeterValues):
        // json_decode() OHNE den Assoziativ-Parameter lässt verschachtelte
        // JSON-Objekte als stdClass statt als Array durch. Der äußere OCPP-
        // J-Frame ist ein JSON-Array (dekodiert immer als PHP-Array), aber
        // das payload-Element selbst UND jedes verschachtelte Objekt darin
        // (meterValue[]/sampledValue[] bei MeterValues) wären sonst
        // stdClass — der spätere `(array)$payload`-Cast in handleCall()
        // konvertiert nur die OBERSTE Ebene, nicht die verschachtelten
        // Objekte darin. Betraf nur MeterValues (einzige Nachricht mit
        // verschachtelten Objekten) — Authorize/StartTransaction/
        // StopTransaction/BootNotification haben nur flache Payloads und
        // liefen deshalb unbemerkt weiter.
        $message = json_decode($raw, true);
        if (!is_array($message) || count($message) < 3) {
            $this->SendDebug('OCPPHub', OHUB_Text::t('Invalid OCPP-J frame: %s', $raw), 0);
            return;
        }

        switch ((int)$message[0]) {
            case self::OCPP_CALL:
                $this->handleCall($cpid, $message);
                break;
            case self::OCPP_CALLRESULT:
            case self::OCPP_CALLERROR:
                // Antworten auf von uns gesendete Aufrufe (RemoteStart/Stop,
                // SetChargingProfile) — jede erkennbare Ablehnung (CALLERROR,
                // oder ein "status" ungleich "Accepted") wird zusätzlich
                // dauerhaft geloggt (Live-Bug 31.08.2026: ein von go-e
                // abgelehntes RemoteStartTransaction blieb sonst nur im —
                // meist längst geschlossenen — Debug-Fenster sichtbar,
                // Dashboard hatte keinerlei Hinweis auf den stillen
                // Fehlschlag).
                $isFailure = (int)$message[0] === self::OCPP_CALLERROR || $this->responseIndicatesFailure($message[2] ?? null);
                $action = $this->resolvePendingCall((string)($message[1] ?? ''));
                if ($isFailure) {
                    $this->LogMessage(OHUB_Text::t('Rejection/error on a call that was sent [%s]: %s', $cpid, $raw), KL_WARNING);
                } elseif ($action === 'GetConfiguration') {
                    // Live-Fund 31.08.2026 (Dietmar, am eigenen Leib erlebt): eine
                    // ERFOLGREICHE Antwort ohne "status"-Feld (z. B. GetConfiguration.conf
                    // liefert nur eine Werteliste, kein status) fiel oben durch —
                    // genau die Sackgasse, die block_reason für Ladeablehnungen lösen
                    // sollte, hier für eine reine Diagnoseabfrage nochmal live erlebt.
                    // GetConfiguration wird nur gezielt/selten manuell zur Fehlersuche
                    // gesendet (nicht im Normalbetrieb) — darum hier bewusst IMMER
                    // dauerhaft geloggt, nicht nur bei Ablehnung, kein Spam-Risiko.
                    $this->LogMessage(OHUB_Text::t('Response to GetConfiguration [%s]: %s', $cpid, $raw), KL_MESSAGE);
                    $this->forwardStationCapabilities($cpid, $message[2] ?? null);
                }
                // Ladeablehnung erklären (Diagnose-Feature 31.08.2026, siehe
                // .docs/architektur.md): nur bei einer EINDEUTIGEN Ablehnung
                // auf genau die beiden Aktionen, die tatsächlich einen
                // Ladevorgang anstoßen sollen — eine abgelehnte
                // ChangeConfiguration o. ä. braucht keine Fahrzeugdiagnose.
                if (in_array($action, ['RemoteStartTransaction', 'SetChargingProfile'], true)) {
                    if ($isFailure) {
                        $this->anKind($cpid, 'BlockGrundDiagnose');
                    } else {
                        // War zuvor eine Begründung gesetzt und jetzt
                        // klappt derselbe Aufruftyp doch (z. B. nach
                        // Aufwecken des Fahrzeugs) — nicht stehen lassen.
                        $this->anKind($cpid, 'BlockGrundLoeschen');
                    }
                }
                // Live-Fund 01.09.2026 (Dietmar, live erlebt): go-e lehnte
                // RemoteStopTransaction wiederholt konsequent mit "Rejected"
                // ab, obwohl eine Sitzung nachweislich lief — SetChargingProfile
                // mit 0 A wurde dagegen zuverlässig angenommen und hat den
                // Ladevorgang tatsächlich beendet (Status-Übergang auf
                // "Finishing", live verifiziert). Automatischer Ausweichweg
                // statt eines manuellen Tricks, den nur wir kennen — Dietmars
                // berechtigter Einwand: "es kann doch nicht sein, dass man
                // solche Tricks und Unfähigkeiten leben soll".
                if ($action === 'RemoteStopTransaction' && $isFailure) {
                    $this->LogMessage(OHUB_Text::t('RemoteStopTransaction rejected [%s] — trying a current limit of 0 A as a fallback.', $cpid), KL_WARNING);
                    $this->SetCurrentLimit($cpid, 0.0);
                }
                // Herstellerneutraler Ausweichweg (01.09.2026): schlägt
                // RemoteStartTransaction fehl, ist die Ursache oft ein
                // interner Zustand der Wallbox, den OCPP selbst nicht
                // benennen kann (Beispiel go-e: ein privates `frc`-Register,
                // das OCPP gar nicht kennt — recherchiert, siehe
                // .docs/architektur.md „Ladeablehnung erklären"). `Reset`
                // ist dagegen echter OCPP-1.6-Kernbefehl, den JEDER konforme
                // Hersteller unterstützen muss — kein go-e-Sonderweg,
                // sondern der protokollkorrekte "bitte internen Zustand
                // aufräumen"-Schritt. Bewusst nur gesendet, nicht automatisch
                // erneut gestartet (ein Reset braucht Zeit, ein sofortiger
                // Retry würde nur wieder scheitern) — der nächste reguläre
                // Start (manuell oder Auto-Autorisierung) greift danach.
                if ($action === 'RemoteStartTransaction' && $isFailure) {
                    // Live-Fund 01.09.2026 (Dietmar, echte Oszillation
                    // beobachtet): ein abgelehntes RemoteStartTransaction
                    // heißt NICHT zwingend "nichts läuft" — die Wallbox kann
                    // bereits eine ANDERE Sitzung fahren (z. B. go-e nach
                    // einem Reset selbst lokal mit idTag "no-card"
                    // gestartet). Ein Reset/frc-Ausweichweg würde eine
                    // bereits laufende, funktionierende Ladung dann grundlos
                    // unterbrechen — genau das ist passiert (kurze
                    // Ladeimpulse statt einer stabilen Sitzung). Deshalb
                    // JETZT ERST prüfen, ob am Ladepunkt schon `Charging`
                    // ansteht, und den Ausweichweg nur auslösen, wenn nicht.
                    $alreadyCharging = (int)(($this->kindZustand($cpid)['state'] ?? 0)) === 2;
                    if ($alreadyCharging) {
                        $this->LogMessage(OHUB_Text::t('RemoteStartTransaction rejected [%s] — but the charge point is already charging (probably another session), reset/frc fallback skipped.', $cpid), KL_WARNING);
                    } else {
                        $this->LogMessage(OHUB_Text::t('RemoteStartTransaction rejected [%s] — sending a soft reset as a manufacturer-neutral fallback.', $cpid), KL_WARNING);
                        $this->Reset($cpid, 'Soft');
                        // go-e-spezifisch, additiv zum Reset oben (siehe
                        // tryClearGoeForceLock()-Docblock) — bei jedem
                        // anderen Hersteller wirkungslos (Vendor-Prüfung/
                        // Verbindungsfehlschlag), kein Risiko für
                        // Nicht-go-e-Wallboxen.
                        $this->tryClearGoeForceLock($cpid);
                    }
                }
                $this->SendDebug('OCPPHub CALLRESULT/CALLERROR [' . $cpid . ']', $raw, 0);
                break;
        }
    }

    // Erkennt eine ablehnende Antwort in einem CALLRESULT-Payload — sowohl
    // direkt ('status' auf oberster Ebene, z. B. RemoteStartTransaction.conf/
    // ChangeConfiguration.conf) als auch verschachtelt ('idTagInfo.status',
    // z. B. StartTransaction.conf). Kein Treffer ist NICHT gleichbedeutend
    // mit Erfolg (manche Antworten haben gar kein 'status'-Feld, z. B.
    // StatusNotification.conf `{}`) — bewusst konservativ, um keine
    // Falschmeldungen zu erzeugen.
    private function responseIndicatesFailure($payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }
        $status = $payload['status'] ?? $payload['idTagInfo']['status'] ?? null;
        return $status !== null && $status !== 'Accepted';
    }

    private function checkBasicAuth(): bool
    {
        $user = $this->ReadPropertyString('BasicAuthUsername');
        if ($user === '') {
            return true;
        }
        $hash = $this->ReadAttributeString('BasicAuthPasswordHash');
        if ($hash === '') {
            // Nutzername gesetzt, aber (noch) kein Passwort übernommen — NICHT
            // stillschweigend jede Verbindung sperren (das wäre ein Total-
            // Lockout ohne jede sichtbare Fehlermeldung), sondern vorerst
            // durchlassen und sichtbar loggen.
            $this->SendDebug('OCPPHub', OHUB_Text::t('Basic Auth user name set, but no password stored — access temporarily allowed unchecked.'), 0);
            return true;
        }
        [$gotUser, $gotPass] = $this->getBasicAuthCredentials();
        $ok = hash_equals($user, $gotUser) && password_verify($gotPass, $hash);
        if (!$ok) {
            $headerPresent = ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '') !== '';
            $this->SendDebug(
                'OCPPHub',
                OHUB_Text::t('Basic Auth rejected (user name received: “%s”, Authorization header present at all: %s).', $gotUser, $headerPresent ? OHUB_Text::t('yes') : OHUB_Text::t('no')),
                0
            );
        }
        return $ok;
    }

    // Live-Fund 30.08.2026: $_SERVER['PHP_AUTH_USER']/['PHP_AUTH_PW'] werden
    // NICHT in jeder PHP-/Webserver-Konfiguration automatisch aus dem
    // Authorization-Header befüllt (bekannte PHP-Falle, u. a. bei bestimmten
    // FastCGI-/Reverse-Proxy-Aufbauten wie in Docker-Betrieb) — der go-e
    // sendet die Zugangsdaten korrekt, sie kamen bei uns aber leer an, weil
    // wir nur PHP_AUTH_USER/-PW gelesen haben. Fallback: rohen
    // Authorization-Header selbst dekodieren.
    private function getBasicAuthCredentials(): array
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? '';
        if ($user !== '') {
            return [$user, $_SERVER['PHP_AUTH_PW'] ?? ''];
        }
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('apache_request_headers') ? (@apache_request_headers()['Authorization'] ?? '') : '');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(trim(substr($header, 6)), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                return explode(':', $decoded, 2);
            }
        }
        return ['', ''];
    }

    private function rememberSeenChargePoint(string $cpid): void
    {
        if ($this->lebenszeichen($cpid)) {
            return; // schon als Instanz angelegt, für den Konfigurator uninteressant
        }
        $seen = json_decode($this->ReadAttributeString('SeenChargePoints'), true);
        if (!is_array($seen)) {
            $seen = [];
        }
        $seen[$cpid] = time();
        $this->WriteAttributeString('SeenChargePoints', json_encode($seen));
    }

    // Cross-Hub-Erkennung (Live-Fund 01.09.2026, siehe .docs/architektur.md
    // „Ladeablehnung erklären" — ChargerHub und OCPPHub hatten sich an
    // derselben physischen Wallbox gegenseitig blockiert, stundenlang ohne
    // jede Fehlermeldung). Da eine Wallbox sich per WebSocket bei UNS
    // meldet (nicht umgekehrt), kennen wir ihre Quell-IP aus jeder
    // eingehenden Anfrage — reicht als Heuristik, um sie mit ChargerHubs
    // konfigurierter Modbus-IP (Property „Host") abzugleichen. Nur an den
    // Ladepunkt weitergereicht, wenn die Instanz schon existiert — vor
    // deren Anlage (nur „gesehene" Charge-Point-Identity) gibt es noch
    // keine Instanz, die die Warnung anzeigen könnte.
    // Seit 0.7.0 geht Quell-IP und Lebenszeichen in EINER Nachricht an den Ladepunkt
    // (siehe lebenszeichen()), die Antwort sagt zugleich, ob es einen Ladepunkt gibt.

    // Verfügbarkeits-Fund ChargerHub 12.09.2026 (Routine-Prüfung nach dem
    // WB2-Vorfall): rememberSeenChargePoint() oben trackt bewusst NUR noch
    // nicht angelegte Charge-Point-Identities (für den Konfigurator) — für
    // eine bereits als Instanz angelegte Wallbox gab es danach GAR KEINE
    // Verbindungsüberwachung mehr, weder sichtbar noch mit Alterungsprüfung.
    // Bei jeder eingehenden Nachricht (unabhängig vom Inhalt) an die
    // zuständige Ladepunkt-Instanz durchgereicht — siehe dortiges
    // UpdateLastSeen()/CheckConnectivity().
    // Meldet dem Ladepunkt der Wallbox „es kam etwas an" samt Quell-IP. Liefert true,
    // wenn es einen Ladepunkt für diese Kennung gibt.
    private function lebenszeichen(string $cpid): bool
    {
        return $this->anKind($cpid, 'Lebenszeichen', ['Ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')]) !== null;
    }

    // ---------------------------------------------------------------------
    // Datenfluss zu den Kindern (Ladepunkte, Abrechnung) — Symcon-Review 30.10.2026.
    // Statt Instanzen zu suchen und deren öffentliche Funktionen aufzurufen, geht jede
    // Nachricht per SendDataToChildren an ALLE angehängten Kinder; jedes Kind prüft in
    // ReceiveData, ob sie ihm gilt (Kennung `Cpid` bzw. `InstanzID`) und antwortet
    // sonst mit einer leeren Zeichenkette. Protokoll: .docs/datenfluss.md.
    // ---------------------------------------------------------------------

    /**
     * Schickt eine Aktion an die Kinder und liefert die Antwort des Kindes, das sich
     * angesprochen fühlt: `daten` (Array, bei reinen Befehlen leer) oder null, wenn kein
     * Kind geantwortet hat (es gibt keinen Ladepunkt für diese Kennung).
     */
    private function anKinder(array $nachricht): ?array
    {
        $nachricht['DataID'] = self::DATA_ZUM_KIND;
        $antworten = $this->SendDataToChildren((string)json_encode($nachricht));
        foreach ((array)$antworten as $roh) {
            if (!is_string($roh) || $roh === '') {
                continue;
            }
            $a = json_decode($roh, true);
            if (is_array($a) && !empty($a['ok'])) {
                return (array)($a['daten'] ?? []);
            }
        }
        return null;
    }

    private function anKind(string $cpid, string $aktion, array $parameter = []): ?array
    {
        return $this->anKinder(array_merge($parameter, ['Aktion' => $aktion, 'Cpid' => $cpid]));
    }

    /** Zustand des Ladepunkts einer Wallbox (null = es gibt keinen Ladepunkt für diese Kennung). */
    private function kindZustand(string $cpid): ?array
    {
        return $this->anKind($cpid, 'Zustand');
    }

    /**
     * Vertragseinträge aller angehängten Ladepunkte, je [cpid, eintrag] (für OHUB_GetFunctions
     * und das Verbindungs-Panel).
     */
    private function kindVertraege(): array
    {
        $antworten = (array)$this->SendDataToChildren((string)json_encode(['DataID' => self::DATA_ZUM_KIND, 'Aktion' => 'Vertrag']));
        $out = [];
        foreach ($antworten as $roh) {
            $a = is_string($roh) && $roh !== '' ? json_decode($roh, true) : null;
            if (is_array($a) && !empty($a['ok']) && is_array($a['daten']['eintrag'] ?? null)) {
                $out[] = ['cpid' => (string)($a['daten']['cpid'] ?? ''), 'eintrag' => $a['daten']['eintrag']];
            }
        }
        return $out;
    }

    /** Anfrage an die Abrechnung, die dieser Splitter führt (genau EINE, siehe GetAbrechnungID()). */
    private function anAbrechnung(string $aktion, array $parameter = []): ?array
    {
        $id = $this->ReadAttributeInteger('AbrechnungID');
        if ($id <= 0) {
            return null;
        }
        return $this->anKinder(array_merge($parameter, ['Aktion' => $aktion, 'Ziel' => 'Abrechnung', 'InstanzID' => $id]));
    }

    /**
     * Anfragen der Kinder (Ladepunkt, Abrechnung, Konfigurator) an den Splitter.
     * Eingang:  {"DataID": ZS, "Aktion": "...", "Cpid": "...", ...}
     * Antwort:  {"ok": true, "daten": ...} bzw. {"ok": false, "fehler": "..."}
     */
    public function ForwardData($JSONString)
    {
        $a = json_decode((string)$JSONString, true);
        if (!is_array($a)) {
            return (string)json_encode(['ok' => false, 'fehler' => OHUB_Text::t('Invalid request.')]);
        }
        try {
            return (string)json_encode(['ok' => true, 'daten' => $this->bearbeiteAnfrage($a)]);
        } catch (\Throwable $e) {
            $this->LogMessage(OHUB_Text::t('Request “%s” of a child failed: %s', (string)($a['Aktion'] ?? '?'), $e->getMessage()), KL_WARNING);
            return (string)json_encode(['ok' => false, 'fehler' => $e->getMessage()]);
        }
    }

    private function bearbeiteAnfrage(array $a): array
    {
        $cpid = (string)($a['Cpid'] ?? '');
        switch ((string)($a['Aktion'] ?? '')) {
            case 'ManuellStart':
                $this->ManualStart($cpid);
                return [];
            case 'Stopp':
                $this->RemoteStop($cpid, (int)($a['TransaktionsId'] ?? 0));
                return [];
            case 'Stromlimit':
                $this->SetCurrentLimit($cpid, (float)($a['Ampere'] ?? 0));
                return [];
            case 'AutoFreigabe':
                $this->AutoAuthorizeVehicle($cpid, (string)($a['Name'] ?? ''));
                return [];
            case 'ForceLockLoesen':
                $this->ClearGoeForceLock($cpid);
                return [];
            case 'Reservieren':
                return ['reservierungsId' => $this->ReserveNow($cpid, (string)($a['IdTag'] ?? ''), (string)($a['Bis'] ?? ''))];
            case 'ReservierungAufheben':
                $this->CancelReservation($cpid, (int)($a['ReservierungsId'] ?? 0));
                return [];
            case 'Vorfuehrmodus':
                return ['aktiv' => $this->IsDemoMode()];
            case 'GeseheneWallboxen':
                return $this->GetSeenChargePoints();
            case 'Vertraege':
                return ['liste' => $this->kindVertraege()];
            case 'AbrechnungGebunden':
                return ['gebunden' => (int)($a['InstanzID'] ?? 0) > 0 && (int)($a['InstanzID'] ?? 0) === $this->ReadAttributeInteger('AbrechnungID')];
        }
        throw new \RuntimeException(OHUB_Text::t('Unknown action.'));
    }

    // Für den Konfigurator: gesehene, aber noch nicht angelegte Charge-Point-
    // Identities.
    public function GetSeenChargePoints(): array
    {
        $seen = json_decode($this->ReadAttributeString('SeenChargePoints'), true);
        return is_array($seen) ? $seen : [];
    }

    private function handleCall(string $cpid, array $message): void
    {
        [$typeId, $uniqueId, $action, $payload] = $message + [null, null, null, []];
        $payload = (array)$payload;

        switch ($action) {
            case 'BootNotification':
                $response = $this->onBootNotification($cpid, $payload);
                break;
            case 'Heartbeat':
                $response = ['currentTime' => gmdate('Y-m-d\TH:i:s\Z')];
                break;
            case 'StatusNotification':
                $response = $this->onStatusNotification($cpid, $payload);
                break;
            case 'Authorize':
                $response = $this->onAuthorize($cpid, $payload);
                break;
            case 'StartTransaction':
                $response = $this->onStartTransaction($cpid, $payload);
                break;
            case 'StopTransaction':
                $response = $this->onStopTransaction($cpid, $payload);
                break;
            case 'MeterValues':
                $response = $this->onMeterValues($cpid, $payload);
                break;
            default:
                // Unbekannte/in Stufe 1 nicht implementierte Action — mit
                // CALLERROR quittieren, damit die Wallbox nicht auf eine nie
                // kommende Antwort wartet (OCPP-J-Pflicht laut Spezifikation).
                $this->sendRaw($cpid, [self::OCPP_CALLERROR, $uniqueId, 'NotImplemented', '', new stdClass()]);
                return;
        }

        $this->sendRaw($cpid, [self::OCPP_CALLRESULT, $uniqueId, $response]);

        // Nach einem Modul-Update bootet die Wallbox nicht neu, die Werte
        // fehlten sonst bis zum nächsten Neustart. Darum auch beim ersten
        // Heartbeat nach Modulstart nachfragen, danach höchstens alle 6 h.
        if ($action === 'Heartbeat') {
            $asked = json_decode($this->GetBuffer('StationCapsAsked'), true);
            if (!is_array($asked)) {
                $asked = [];
            }
            if (time() - (int)($asked[$cpid] ?? 0) > 6 * 3600) {
                $asked[$cpid] = time();
                $this->SetBuffer('StationCapsAsked', json_encode($asked));
                $this->requestStationCapabilities($cpid);
            }
        }

        if ($action === 'BootNotification') {
            // ERST die BootNotification.conf raus (oben), DANACH die
            // ChangeConfiguration-Aufrufe — nicht umgekehrt, manche
            // Wallbox-Firmware (u. a. Testgerät go-e) erwartet die Antwort
            // auf ihren eigenen Aufruf vor weiteren CALLs vom Central System.
            $this->requestFastMeterValues($cpid);
            $this->requestStationCapabilities($cpid);
        }
    }

    private function onBootNotification(string $cpid, array $payload): array
    {
        $vendor = (string)($payload['chargePointVendor'] ?? '');
        $this->anKind($cpid, 'Boot', [
            'Hersteller'   => $vendor,
            'Modell'       => (string)($payload['chargePointModel'] ?? ''),
            'Seriennummer' => (string)($payload['chargePointSerialNumber'] ?? ''),
        ]);
        if ($vendor !== '') {
            $vendors = json_decode($this->ReadAttributeString('ChargePointVendor'), true);
            if (!is_array($vendors)) {
                $vendors = [];
            }
            $vendors[$cpid] = $vendor;
            $this->WriteAttributeString('ChargePointVendor', json_encode($vendors));
        }
        return [
            'status'      => 'Accepted',
            'currentTime' => gmdate('Y-m-d\TH:i:s\Z'),
            'interval'    => 300, // Heartbeat-Intervall (s), NICHT MeterValues.
        ];
    }

    private function onStatusNotification(string $cpid, array $payload): \stdClass
    {
        $this->anKind($cpid, 'Status', [
            'Status'     => (string)($payload['status'] ?? ''),
            'Fehlercode' => (string)($payload['errorCode'] ?? ''),
        ]);
        return new \stdClass(); // StatusNotification.conf: leeres Objekt
    }

    private function onAuthorize(string $cpid, array $payload): array
    {
        $idTag = (string)($payload['idTag'] ?? '');
        return ['idTagInfo' => ['status' => $this->checkIdTag($cpid, $idTag)]];
    }

    // Zentrale Prüfung, verwendet sowohl bei Authorize als auch bei
    // StartTransaction (manche Wallboxen überspringen Authorize.req und
    // verlassen sich allein auf das idTagInfo in StartTransaction.conf).
    // Schreibt jede Kartenauflage ins dauerhafte Symcon-Systemlog (nicht nur
    // SendDebug, das nur bei geöffnetem Debug-Fenster live sichtbar und
    // danach unwiederbringlich weg ist) — damit ein idTag auch nachträglich
    // im Log nachschlagbar ist, z. B. um eine neue Karte in der Abrechnung-
    // Instanz anzulegen.
    private function checkIdTag(string $cpid, string $idTag): string
    {
        $status = $this->checkIdTagInternal($cpid, $idTag);
        $this->LogMessage('Authorize [' . $cpid . ']: idTag="' . $idTag . '" -> ' . $status, KL_MESSAGE);
        return $status;
    }

    private function checkIdTagInternal(string $cpid, string $idTag): string
    {
        $zustand = $this->kindZustand($cpid);
        $hatLadepunkt = $zustand !== null;

        // Schreibsperre (Duplikat/deaktiviert, siehe isWriteBlocked()) geht
        // JEDER anderen Prüfung vor — ein gesperrter Ladepunkt darf auch
        // keine neue Ladung autorisieren, unabhängig von Betriebsart/
        // Reservierung/Kundenverwaltung.
        if ($hatLadepunkt && $this->zustandGesperrt($zustand)) {
            $this->anKind($cpid, 'Blockiert', ['Grund' => OHUB_Text::t('This charge point is deactivated or marked as a duplicate — another instance is responsible.')]);
            return 'Blocked';
        }

        // Reservierung: außerhalb des berechtigten idTags -> Blocked (siehe
        // .docs/architektur.md „Reservierung"). Geht der Prüfung unten vor,
        // auch bei Betriebsart ①, weil eine Reservierung nur sinnvoll ist,
        // wenn sie auch durchgesetzt wird.
        if ($hatLadepunkt) {
            $reservedIdTag = (string)($zustand['reservedIdTag'] ?? '');
            if ($reservedIdTag !== '' && $reservedIdTag !== $idTag) {
                $this->anKind($cpid, 'Blockiert', ['Grund' => $this->explainAuthStatus('Blocked', 'reserviert')]);
                return 'Blocked';
            }
        }

        // Stufe 1 / Betriebsart ① (Einzelnutzer): kein RFID-Zwang, jede
        // Karte wird angenommen. Zentrale Whitelist-Prüfung nur bei
        // Betriebsart ②, siehe .docs/architektur.md „Authentifizierung".
        if ($this->ReadPropertyInteger('Betriebsart') !== 2) {
            return 'Accepted';
        }

        $abrechnungId = $this->ensureAbrechnung();
        if ($abrechnungId === 0) {
            // Abrechnung-Instanz konnte nicht angelegt werden — fail-open,
            // damit ein interner Fehler nicht sämtliches Laden blockiert
            // (dieselbe Abwägung wie beim Offline-Fallback, siehe
            // architektur.md „Verfügbarkeit / Offline-Verhalten").
            return 'Accepted';
        }
        $result = (array)($this->anAbrechnung('Autorisierung', ['IdTag' => $idTag]) ?? []);
        $status = (string)($result['status'] ?? 'Invalid');
        $reason = (string)($result['reason'] ?? '');

        // Live-Fund 01.09.2026 (Dietmars erster echter Ladeversuch): eine
        // abgelehnte Autorisierung landete bis hierher NUR im Systemlog
        // (IPS_LogMessage in checkIdTag()), nicht sichtbar am Ladepunkt
        // selbst — genau die Art stiller Fehlschlag, die block_reason
        // eigentlich verhindern sollte. Anders als bei DiagnoseBlockReason()
        // (Tessie/Tibber-Rätselraten für eine vom Charger selbst
        // abgelehnte RemoteStartTransaction/SetChargingProfile OHNE
        // erkennbaren Grund) kennen wir den Grund hier bereits exakt aus
        // unserer eigenen Zugänge-Prüfung — kein Rätselraten nötig.
        if ($hatLadepunkt) {
            if ($status === 'Accepted') {
                $this->anKind($cpid, 'BlockGrundLoeschen');
            } else {
                $this->anKind($cpid, 'Blockiert', ['Grund' => $this->explainAuthStatus($status, $reason)]);
            }
        }

        // idTag-Direktzuordnung (Vorrang vor Dashboards Zeitkorrelation,
        // mit Dashboard abgestimmt, siehe architektur.md „Fahrzeug-
        // Zuordnung & SOC") — bei erfolgreicher Autorisierung mit
        // bekanntem Fahrzeug sofort setzen.
        if ($status === 'Accepted' && $hatLadepunkt && !empty($result['vehicleName'])) {
            // $TimeCorrelated = false: diese Zuordnung läuft bereits mitten in
            // einer ECHTEN Autorisierung (Karte war schon Accepted) — eine
            // zusätzliche Auto-Autorisierung (siehe AutoAuthorizeVehicle()
            // unten) wäre hier redundant und könnte sogar ein doppeltes
            // RemoteStartTransaction auf eine bereits laufende Transaktion
            // auslösen. Der Parameter ist NUR für Dashboards eigenständige,
            // von keiner Autorisierung begleitete Fahrzeugerkennung gedacht.
            $this->anKind($cpid, 'Fahrzeug', ['Name' => (string)$result['vehicleName'], 'Zeitkorrelation' => false]);
            // Additiv (Diagnose-Feature 31.08.2026): merkt sich die
            // verknüpfte Tessie-Instanz für eine spätere Ladeablehnung-
            // Diagnose (siehe OHUBL_DiagnoseBlockReason()) — 0 = kein
            // Tessie-verknüpftes Fahrzeug, dann bleibt die Diagnose auf den
            // Tibber-Namensabgleich beschränkt.
            $this->anKind($cpid, 'FahrzeugTessie', ['Id' => (int)($result['vehicleTessieInstanceId'] ?? 0)]);
        }

        return $status;
    }

    // "So etwas wie Autocharge" (Dietmars Wunsch 31.08.2026, Design mit
    // Dashboard abgestimmt): Dashboard ruft OHUBL_SetVehicleName() künftig
    // auch UNABHÄNGIG von einer echten Kartenauflage auf, sobald es per
    // eigener Zeitkorrelation ein Fahrzeug erkennt (Ladepunkt reicht das als
    // $TimeCorrelated=true hierher durch). Wir suchen dann selbst einen
    // passenden Zugang und jagen dessen idTag durch DIESELBE checkIdTag()-
    // Prüfung wie eine echte Kartenauflage — keine eigene, laxere Logik,
    // alle Regeln (Limits/Zeitfenster/Reservierung/Kunde aktiv) gelten
    // identisch, inklusive der bestehenden persistenten Ablehnungs-
    // Protokollierung. Bewusst NUR bei Betriebsart ② — bei ① ist ohnehin
    // schon alles offen, hier gibt es nichts zu automatisieren.
    public function AutoAuthorizeVehicle(string $cpid, string $vehicleName): void
    {
        if ($vehicleName === '' || $this->ReadPropertyInteger('Betriebsart') !== 2) {
            return;
        }
        // $ladepunktId schon hier auflösen (nicht erst nach dem Accepted-
        // Fall wie zuvor) — wird jetzt auch für den "kein Zugang gefunden"-
        // Fehlerpfad gebraucht (Live-Fund 01.09.2026: dieser Fall blieb
        // bislang komplett stumm, kein block_reason, keine Erklärung).
        $abrechnungId = $this->ensureAbrechnung();
        if ($abrechnungId === 0) {
            return;
        }
        $idTag = (string)($this->anAbrechnung('IdTagZuFahrzeug', ['Name' => $vehicleName])['idTag'] ?? '');
        if ($idTag === '') {
            $this->anKind($cpid, 'Blockiert', ['Grund' => OHUB_Text::t('No access entry registered for “%s” — please teach in the card or create an access entry.', $vehicleName)]);
            return;
        }
        if ($this->checkIdTag($cpid, $idTag) !== 'Accepted') {
            // checkIdTagInternal() hat block_reason bereits mit dem
            // konkreten Grund (gesperrt/abgelaufen/Limit/...) gesetzt.
            return;
        }
        // Echten idTag verwenden (nicht 'symcon' wie beim manuellen Dashboard-
        // Start) — StartTransaction.req der Wallbox trägt ihn zurück,
        // RecordConsumption() rechnet den Verbrauch dadurch dem richtigen
        // Kunden zu.
        if ($this->anKind($cpid, 'AutoStartBestaetigt') === null) {
            return;   // kein Ladepunkt für diese Kennung
        }
        $this->RemoteStart($cpid, $idTag);
    }

    // Für den manuellen "Ladefreigabe"-Schalter (RequestAction('ctl_enable',
    // true) im Ladepunkt). Live-Fund 01.09.2026 (Dietmars Rückfrage "das muss
    // doch aber auch anders funktionieren!"): der Schalter sendete bisher
    // immer den Platzhalter 'symcon', der unter Betriebsart ② zu Recht als
    // nicht-registrierte Karte abgelehnt wird — der Schalter hatte damit
    // unter "Mehrere Nutzer" praktisch nie eine Chance zu funktionieren,
    // sobald ein Fahrzeug bereits erkannt war. Jetzt: ist ein Fahrzeug
    // bekannt UND Betriebsart ②, denselben Weg wie die Auto-Autorisierung
    // nehmen (dessen ECHTEN, registrierten Zugang suchen und darüber
    // starten) — sonst (kein Fahrzeug bekannt, oder Betriebsart ①, wo
    // ohnehin jede Karte akzeptiert wird) weiter der einfache
    // 'symcon'-Weg.
    public function ManualStart(string $cpid): void
    {
        $vehicleName = (string)($this->kindZustand($cpid)['vehicleName'] ?? '');
        if ($vehicleName !== '' && $this->ReadPropertyInteger('Betriebsart') === 2) {
            $this->AutoAuthorizeVehicle($cpid, $vehicleName);
            return;
        }
        $this->RemoteStart($cpid, 'symcon');
    }

    // Zentrale Übersetzung der Abrechnung-Statuscodes (siehe
    // OCPPHubAbrechnung::CheckAuthorization()) in einen für Dietmar direkt
    // verständlichen Text — hier ist der Grund bereits exakt bekannt, anders
    // als bei DiagnoseBlockReason() (das für eine vom Charger selbst ohne
    // erkennbaren Grund abgelehnte RemoteStartTransaction/SetChargingProfile
    // bei Tessie/Tibber nachfragen muss).
    private function explainAuthStatus(string $status, string $reason): string
    {
        if ($status === 'Invalid') {
            return OHUB_Text::t('Card/access entry is not registered.');
        }
        if ($status === 'Expired') {
            return OHUB_Text::t('Access entry has expired.');
        }
        if ($status === 'Blocked') {
            switch ($reason) {
                case 'zugang-gesperrt':
                    return OHUB_Text::t('Access entry is blocked.');
                case 'ausserhalb-zeitfenster':
                    return OHUB_Text::t('Outside the permitted time window for this access entry.');
                case 'kein-kunde-zugeordnet':
                    return OHUB_Text::t('Access entry is not assigned to an active customer.');
                case 'kunde-gesperrt':
                    return OHUB_Text::t('Customer is blocked.');
                case 'limit-erreicht':
                    return OHUB_Text::t('Consumption limit has been reached.');
                case 'reserviert':
                    return OHUB_Text::t('The wallbox is reserved for this period.');
                default:
                    return OHUB_Text::t('Access entry is blocked.');
            }
        }
        return '';
    }

    private function onStartTransaction(string $cpid, array $payload): array
    {
        $transactionId = $this->nextTransactionId();
        $idTag = (string)($payload['idTag'] ?? '');
        $status = $this->checkIdTag($cpid, $idTag);
        $this->anKind($cpid, 'TransaktionStart', [
            'TransaktionsId' => $transactionId,
            'IdTag'          => $idTag,
            'ZaehlerStart'   => (int)($payload['meterStart'] ?? 0),
        ]);
        return [
            'idTagInfo'     => ['status' => $status],
            'transactionId' => $transactionId,
        ];
    }

    private function onStopTransaction(string $cpid, array $payload): array
    {
        $zustand = $this->kindZustand($cpid);
        if ($zustand !== null) {
            $meterStop = (int)($payload['meterStop'] ?? 0);
            $meterStart = (int)($zustand['meterStartWh'] ?? 0);
            $this->anKind($cpid, 'TransaktionStopp', [
                'TransaktionsId' => (int)($payload['transactionId'] ?? 0),
                'ZaehlerStop'    => $meterStop,
            ]);

            // Verbrauch dem Kunden gutschreiben (Stufe 2, für die
            // Limit-Prüfung in checkIdTag()/OHUBA_CheckAuthorization()).
            // idTag ist in StopTransaction.req laut OCPP-1.6-Spezifikation
            // OPTIONAL — falls die Wallbox es nicht mitschickt, auf den bei
            // StartTransaction gemerkten idTag zurückfallen.
            if ($this->ReadPropertyInteger('Betriebsart') === 2 && $meterStop > $meterStart) {
                $idTag = (string)($payload['idTag'] ?? '') ?: (string)($zustand['lastIdTag'] ?? '');
                $abrechnungId = $this->ensureAbrechnung();
                if ($idTag !== '' && $abrechnungId > 0) {
                    $this->anAbrechnung('Verbrauch', ['IdTag' => $idTag, 'Kwh' => ($meterStop - $meterStart) / 1000.0, 'Zeit' => time()]);
                }
            }
        }
        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    private function onMeterValues(string $cpid, array $payload): \stdClass
    {
        $powerW = null;
        $energyWh = null;
        $socPercent = null;
        foreach ((array)($payload['meterValue'] ?? []) as $mv) {
            foreach ((array)($mv['sampledValue'] ?? []) as $sv) {
                $measurand = $sv['measurand'] ?? 'Energy.Active.Import.Register';
                $value = is_numeric($sv['value'] ?? null) ? (float)$sv['value'] : null;
                if ($value === null) {
                    continue;
                }
                $unit = $sv['unit'] ?? '';
                if ($measurand === 'Power.Active.Import') {
                    $powerW = ($unit === 'kW') ? $value * 1000 : $value;
                } elseif ($measurand === 'Energy.Active.Import.Register') {
                    $energyWh = ($unit === 'kWh') ? $value * 1000 : $value;
                } elseif ($measurand === 'SoC') {
                    // Nicht jede Wallbox/jedes Fahrzeug liefert das (eher
                    // DC-Laden/ISO 15118) — siehe OCPPHubLadepunkt::
                    // UpdateMeterValues()-Kommentar zum vehicleSocID-
                    // Vertragsstatus.
                    $socPercent = $value;
                }
            }
        }
        if ($powerW !== null || $energyWh !== null || $socPercent !== null) {
            $this->anKind($cpid, 'Messwerte', ['Leistung' => $powerW, 'Energie' => $energyWh, 'Soc' => $socPercent]);
        }
        return new \stdClass(); // MeterValues.conf: leeres Objekt
    }

    private function nextTransactionId(): int
    {
        $id = $this->ReadAttributeInteger('NextTransactionId');
        $this->WriteAttributeInteger('NextTransactionId', $id + 1);
        return $id;
    }


    // Schreibsperre für einen Ladepunkt — vor JEDEM echten Steuerbefehl UND
    // vor jeder Authorize/StartTransaction-Prüfung geprüft, analog zur
    // Vorführmodus-Sperre.
    // KORRIGIERT 13.09.2026 (EMS' endgültige Entscheidung, von Dietmar direkt
    // bestätigt, NACHDEM `duplicateOf` kurzzeitig hier mit reingezählt hatte):
    // Zählen (`duplicateOf`) und Schreiben sind orthogonale Fragen. Wer
    // schreibt, entscheidet AUSSCHLIESSLICH `managedBy` (über die schon
    // vorhandene `externallyManaged`-Ableitung: alles außer 'none'/'ems')
    // plus unsere eigene Deaktivierung. Bei Dietmars WB1 z. B. misst OCPP
    // (duplicateOf NICHT gesetzt auf unserer Seite), geregelt wird aber über
    // ChargerHub (managedBy='other' auf unserer Seite) — zwei unabhängige
    // Tatsachen.
    private function isWriteBlocked(string $cpid): bool
    {
        $zustand = $this->kindZustand($cpid);
        return $zustand !== null && $this->zustandGesperrt($zustand);
    }

    private function zustandGesperrt(array $zustand): bool
    {
        return !empty($zustand['deactivated']) || !empty($zustand['externallyManaged']);
    }

    // Diagnose-Hilfsfunktion (Live-Fund 31.08.2026, Dietmar: "man steht wie der
    // Ochs vorm Berg, wenn man keine Rückmeldung bekommt") — fragt beliebige
    // OCPP-Konfigurationsschlüssel einer Wallbox ab, z. B. zur Klärung von
    // AuthorizeRemoteTxRequests, ohne für jede Ad-hoc-Diagnose einen Rohbefehl
    // per WC_PushMessage() von Hand zu basteln (der dann NICHT durch sendCall()
    // läuft und darum auch nicht in der PendingCalls-Korrelation/dem
    // persistenten Logging auftaucht — genau das ist mir hier live passiert).
    // $keys leer = alle Schlüssel abfragen (GetConfiguration.req ohne "key").
    // Antwort landet dauerhaft im Systemlog (siehe handleCall() CALLRESULT/
    // CALLERROR), nicht nur im flüchtigen Debug-Fenster.
    public function GetConfigurationKeys(string $cpid, array $keys): void
    {
        $this->sendCall($cpid, 'GetConfiguration', $keys === [] ? [] : ['key' => $keys]);
    }

    // ---------------------------------------------------------------------
    // Ausgehend — aufgerufen von OCPPHubLadepunkt (Parent-Instanz-ID via
    // IPS_GetParent()) oder von einer späteren Steuerungs-/Skript-Ebene.
    // Die eigentlichen Dashboard-Backend-Funktionen (ManualStart/ManualStop/
    // SetDailyOverride) liegen bewusst auf OCPPHubLadepunkt, nicht hier —
    // Dashboard soll nur die eine Ladepunkt-Instanz-ID brauchen, keine
    // zusätzliche Splitter-ID auflösen müssen. Siehe .docs/architektur.md
    // „Bedienung: Backend-Funktion für Dashboard".
    // ---------------------------------------------------------------------

    // KEIN Standardwert auf $idTag (bewusst, Live-Bug 31.08.2026, Dashboard-Fund):
    // Symcons generierte globale Funktion (`OHUB_RemoteStart($InstanceID, ...)`)
    // ignoriert PHP-Standardwerte auf Instanzmethoden-Parametern komplett
    // (verifiziert per ReflectionFunction auf der generierten Funktion — jeder
    // Parameter ist dort zwingend, unabhängig vom hier deklarierten Default) —
    // ein Aufrufer, der sich auf einen Default verlässt, bekommt einen
    // ArgumentCountError. Jeder Aufrufer muss $idTag explizit übergeben
    // (z. B. 'symcon' für manuell/EMS-ausgelöste Starts ohne echte Karte).
    // Live-Bug 31.08.2026 (Dashboard-Fund, go-e antwortete sofort mit
    // {"status":"Rejected"}, kein Timeout): OCPP 1.6 erlaubt `connectorId` auf
    // RemoteStartTransaction.req zwar als optional, go-e lehnt die Anfrage aber
    // strukturell ab, wenn es fehlt — vermutlich weil WB2 zwei Connectors meldet
    // (0 = ganze Wallbox, 1 = der tatsächliche Stecker) und ohne connectorId
    // nicht weiß, welchen es starten soll. Live bestätigt: die ECHTE
    // StartTransaction (durch Kartenauflegen ausgelöst) läuft immer mit
    // "connectorId":1 — genau das jetzt auch hier fest mitgeben (Ladepunkt
    // bildet aktuell nur einen einzelnen Connector pro Instanz ab, siehe
    // .docs/architektur.md „Instanzmodell" — Mehr-Connector-Adressierung ist
    // Stufe-3-Thema).
    public function RemoteStart(string $cpid, string $idTag): void
    {
        // Vorführmodus-Sperre HIER am eigentlichen Absende-Punkt (nicht nur
        // am Ladepunkt-Schalter) — greift dadurch auch für die automatische
        // Fahrzeug-Autorisierung (AutoAuthorizeVehicle()), die diesen Weg
        // direkt aufruft, ohne über OCPPHubLadepunkt::RequestAction() zu
        // laufen. Siehe IsDemoMode().
        if ($this->IsDemoMode()) {
            $this->LogMessage(OHUB_Text::t('Demo mode is active — RemoteStartTransaction [%s] suppressed, no real charging control sent.', $cpid), KL_WARNING);
            return;
        }
        if ($this->isWriteBlocked($cpid)) {
            $this->LogMessage(OHUB_Text::t('Charge point [%s] is blocked (duplicate or deactivated) — RemoteStartTransaction suppressed.', $cpid), KL_WARNING);
            return;
        }
        $this->sendCall($cpid, 'RemoteStartTransaction', ['connectorId' => 1, 'idTag' => $idTag]);
    }

    public function RemoteStop(string $cpid, int $transactionId): void
    {
        $this->sendCall($cpid, 'RemoteStopTransaction', ['transactionId' => $transactionId]);
    }

    // OCPP-1.6-Kernbefehl (Pflichtbestandteil, jeder konforme Hersteller muss
    // ihn unterstützen) — herstellerneutraler "internen Zustand aufräumen"-
    // Schritt, automatisch als Ausweichweg bei abgelehntem
    // RemoteStartTransaction genutzt (siehe handleCall() CALLRESULT/
    // CALLERROR). $Type: 'Soft' oder 'Hard' laut Spezifikation — bewusst
    // ohne PHP-Standardwert, jeder Aufrufer muss ihn explizit mitgeben
    // (Lehre aus dem RemoteStart()-ArgumentCountError-Fund).
    public function Reset(string $cpid, string $Type): void
    {
        if ($this->IsDemoMode()) {
            $this->LogMessage(OHUB_Text::t('Demo mode is active — Reset [%s] suppressed.', $cpid), KL_WARNING);
            return;
        }
        if ($this->isWriteBlocked($cpid)) {
            $this->LogMessage(OHUB_Text::t('Charge point [%s] is blocked (duplicate or deactivated) — Reset suppressed.', $cpid), KL_WARNING);
            return;
        }
        $this->sendCall($cpid, 'Reset', ['type' => $Type]);
    }

    // go-e-spezifischer Ausweichweg (01.09.2026, Dietmars ausdrücklicher
    // Wunsch: "entweder oder installieren, nicht Modbus UND OCPP" — OCPPHub
    // soll für go-e-Wallboxen auch OHNE installiertes ChargerHub
    // funktionieren). Recherchiert (siehe .docs/architektur.md): der
    // go-e-App-Startknopf setzt Transaktion UND das private Register
    // `frc`/`FORCE_STATE` gleichzeitig — RemoteStartTransaction über OCPP
    // löst nur die Transaktion aus. `frc` liegt außerhalb des OCPP-
    // Standards, ein Modbus-Schreibzugriff ist der einzige Weg, es
    // zurückzusetzen. Bewusst NUR dieser eine, schmale Zweck (ein Register
    // schreiben) — kein allgemeiner Modbus-Client, keine laufende
    // Überwachung, kein Ersatz für ChargerHub. Zwei Wege probiert:
    // (1) ChargerHubs eigene CHUB_ClearForceLock() nutzen, FALLS eine
    //     passende Instanz existiert (auch wenn deren Active=false ist,
    //     siehe deren Zusage) — vermeidet doppelte Logik.
    // (2) Fehlt ChargerHub komplett, selbst per rohem Modbus-TCP-Schreiben
    //     (Funktion 6, Register 337, Wert 0) — funktioniert nur bei go-e
    //     (BootNotification-Herstellername geprüft), bei jedem anderen
    //     Hersteller/falscher IP schlägt der Verbindungsversuch einfach
    //     folgenlos fehl (kurzer Timeout, alles in try/catch).
    // ChargerHub-Hinweise 01.09.2026 aus eigener Erfahrung mit demselben
    // Register: (a) Modbus muss am go-e erst per App/HTTP-API aktiviert
    // werden (`men=true`) — meldet der Verbindungsversuch "abgelehnt"/
    // "Port zu", ist das der wahrscheinlichste Grund, kein Fehler hier im
    // Code. (b) Firmware 60.3 hatte vertauschte Bytes bei 32-Bit-Werten
    // (Float64-Register wie das Energie-Limit) — betrifft FORCE_STATE als
    // reines U16 vermutlich nicht, nur relevant, falls dieser Code je auf
    // weitere Register erweitert wird.
    // Öffentlicher Zugriff für OCPPHubLadepunkt::SetActive() (13.09.2026,
    // MeterHub-Hinweis nach Dietmars Entscheidung „zu Ende laden lassen"):
    // beim Abschalten zugunsten eines anderen Kanals (typischerweise
    // ChargerHub) denselben go-e-frc-Ausweichweg auslösen wie beim
    // Reset-Ausweichweg — sonst könnte ein von UNS gesetztes FORCE_STATE-
    // Lock den übernehmenden Kanal blockieren, exakt der Fehler aus der
    // Nacht 01./02.09.2026 (siehe architektur.md „Root Cause").
    public function ClearGoeForceLock(string $cpid): void
    {
        $this->tryClearGoeForceLock($cpid);
    }

    private function tryClearGoeForceLock(string $cpid): void
    {
        $vendors = json_decode($this->ReadAttributeString('ChargePointVendor'), true);
        $vendor = is_array($vendors) ? (string)($vendors[$cpid] ?? '') : '';
        if (strcasecmp($vendor, 'go-e') !== 0) {
            return;
        }
        $chargerHubId = $this->findChargerHubInstanceForCurrentIp();
        if ($chargerHubId !== 0 && function_exists('CHUB_ClearForceLock')) {
            $cleared = CHUB_ClearForceLock($chargerHubId);
            $this->LogMessage(OHUB_Text::t('ChargerHub cooperation [%s]: ClearForceLock() -> %s.', $cpid, $cleared ? OHUB_Text::t('successful') : OHUB_Text::t('no effect')), KL_MESSAGE);
            if ($cleared) {
                return;
            }
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return;
        }
        $ok = $this->writeModbusRegister($ip, 502, 1, 337, 0);
        $this->LogMessage(OHUB_Text::t('go-e fallback [%s]: setting FORCE_STATE to 0 via Modbus -> %s.', $cpid, $ok ? OHUB_Text::t('successful') : OHUB_Text::t('failed')), KL_MESSAGE);
    }

    // Findet eine ChargerHub-Instanz mit derselben IP wie die aktuelle
    // eingehende Verbindung — Wiederverwendung derselben Heuristik wie die
    // Cross-Hub-Erkennung im Ladepunkt-Formular (dort für die Warnung, hier
    // für die Kooperation). `$_SERVER['REMOTE_ADDR']` ist innerhalb
    // desselben ProcessHookData()-Aufrufs verfügbar, in dem auch die
    // Ablehnung verarbeitet wird — kein separates Zwischenspeichern nötig.
    private function findChargerHubInstanceForCurrentIp(): int
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
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

    // Bewusst minimaler, roher Modbus-TCP-Client — NUR "ein Register
    // schreiben" (Funktion 6), kein allgemeiner Modbus-Stack. Kurzer
    // Verbindungs-Timeout (2 s), damit ein falscher/nicht erreichbarer Host
    // die OCPP-Nachrichtenverarbeitung nicht spürbar blockiert. Gibt
    // `false` bei jedem Fehler zurück (Verbindung, Timeout, unerwartete
    // Antwort) — nie eine Exception nach außen.
    private function writeModbusRegister(string $ip, int $port, int $unitId, int $register, int $value): bool
    {
        $socket = @stream_socket_client('tcp://' . $ip . ':' . $port, $errno, $errstr, 2.0);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, 2);
        // FC 16 (Write Multiple Registers, 0x10) — NICHT FC 6 (Write Single
        // Register). ChargerHub-Korrektur 01.09.2026, gegen die
        // Herstellerquelle geprüft: go-e unterstützt FC 6 laut offizieller
        // Doku gar nicht, Schreibzugriffe laufen dort ausschließlich über
        // FC 16, auch für ein einzelnes Register (Quantity=1, ByteCount=2).
        // Ohne diesen Hinweis wäre der erste Live-Test schlicht am
        // falschen Funktionscode gescheitert.
        $pdu = chr(0x10) . pack('n', $register) . pack('n', 1) . chr(2) . pack('n', $value);
        $mbap = pack('n', random_int(1, 65535)) . pack('n', 0x0000) . pack('n', strlen($pdu) + 1) . chr($unitId);
        $written = @fwrite($socket, $mbap . $pdu);
        if ($written === false) {
            @fclose($socket);
            return false;
        }
        $response = @fread($socket, 260);
        @fclose($socket);
        // FC16-Erfolgsantwort echot FunctionCode+StartAddress+Quantity
        // (KEIN ByteCount/Value wie bei FC6) — reicht als Erfolgsprüfung.
        return is_string($response) && strlen($response) >= 8 && ord($response[7]) === 0x10;
    }

    // $ampere: gewünschtes Stromlimit. connectorId 0 = ganze Wallbox (bei
    // Wallboxen mit nur einem Connector üblich) — Mehr-Connector-Adressierung
    // ist Stufe-2-Thema.
    public function SetCurrentLimit(string $cpid, float $ampere): void
    {
        if ($this->IsDemoMode()) {
            $this->LogMessage(OHUB_Text::t('Demo mode is active — SetChargingProfile [%s] suppressed.', $cpid), KL_WARNING);
            return;
        }
        if ($this->isWriteBlocked($cpid)) {
            $this->LogMessage(OHUB_Text::t('Charge point [%s] is blocked (duplicate or deactivated) — SetChargingProfile suppressed.', $cpid), KL_WARNING);
            return;
        }
        // TxDefaultProfile mit einer einzigen Periode — reicht für ein
        // reines Stromlimit ohne Zeitplan. chargingRateUnit 'A' vs. 'W' und
        // numberPhases sind laut .docs/architektur.md je Hersteller zu
        // verifizieren (dort als offener Punkt vermerkt) — hier bewusst nur
        // 'A', bis ein Live-Test das Gegenteil zeigt.
        $this->sendCall($cpid, 'SetChargingProfile', [
            'connectorId'         => 0,
            'csChargingProfiles'  => [
                'chargingProfileId'      => 1,
                'stackLevel'             => 0,
                'chargingProfilePurpose' => 'TxDefaultProfile',
                'chargingProfileKind'    => 'Absolute',
                'chargingSchedule'       => [
                    'chargingRateUnit'       => 'A',
                    'chargingSchedulePeriod' => [
                        ['startPeriod' => 0, 'limit' => $ampere],
                    ],
                ],
            ],
        ]);
    }

    // Reservierung (Stufe 2, siehe .docs/architektur.md „Reservierung") —
    // aufgerufen von OCPPHubLadepunkt::Reserve()/CancelReservation(). Gibt
    // die vergebene reservationId zurück, die der Ladepunkt für ein
    // späteres CancelReservation() vorhält (OCPP verlangt die reservationId
    // beim Abbrechen).
    public function ReserveNow(string $cpid, string $idTag, string $expiryIso): int
    {
        $reservationId = $this->nextReservationId();
        $this->sendCall($cpid, 'ReserveNow', [
            'connectorId'   => 0,
            'expiryDate'    => $expiryIso,
            'idTag'         => $idTag,
            'reservationId' => $reservationId,
        ]);
        return $reservationId;
    }

    public function CancelReservation(string $cpid, int $reservationId): void
    {
        $this->sendCall($cpid, 'CancelReservation', ['reservationId' => $reservationId]);
    }

    private function nextReservationId(): int
    {
        $id = $this->ReadAttributeInteger('NextReservationId');
        $this->WriteAttributeInteger('NextReservationId', $id + 1);
        return $id;
    }

    // Leichte Korrelation gesendete-uniqueId → Aktion (nur für die Block-
    // Diagnose gebraucht, siehe handleCall()/OCPP_CALLRESULT — KEINE
    // vollständige Aufruf-Historie). Attribut statt Property (Laufzeitdaten),
    // Einträge älter als 5 Minuten werden bei jedem Schreiben verworfen,
    // falls nie eine Antwort kam (Wallbox offline o. ä.).
    private const PENDING_CALL_MAX_AGE = 300;

    private function sendCall(string $cpid, string $action, array $payload): void
    {
        $uniqueId = uniqid('ohub_', true);
        $this->rememberPendingCall($uniqueId, $cpid, $action);
        $this->sendRaw($cpid, [self::OCPP_CALL, $uniqueId, $action, $payload]);
    }

    private function rememberPendingCall(string $uniqueId, string $cpid, string $action): void
    {
        $pending = json_decode($this->ReadAttributeString('PendingCalls'), true);
        if (!is_array($pending)) {
            $pending = [];
        }
        $now = time();
        foreach ($pending as $id => $entry) {
            if ($now - (int)($entry['ts'] ?? 0) > self::PENDING_CALL_MAX_AGE) {
                unset($pending[$id]);
            }
        }
        $pending[$uniqueId] = ['cpid' => $cpid, 'action' => $action, 'ts' => $now];
        $this->WriteAttributeString('PendingCalls', json_encode($pending));
    }

    // Liefert die Aktion zur uniqueId und entfernt den Eintrag (einmal
    // abgeholt, nicht mehr gebraucht) — leerer String, falls unbekannt/
    // schon verworfen (z. B. bei einem sehr späten CALLRESULT).
    private function resolvePendingCall(string $uniqueId): string
    {
        $pending = json_decode($this->ReadAttributeString('PendingCalls'), true);
        if (!is_array($pending) || !isset($pending[$uniqueId])) {
            return '';
        }
        $action = (string)($pending[$uniqueId]['action'] ?? '');
        unset($pending[$uniqueId]);
        $this->WriteAttributeString('PendingCalls', json_encode($pending));
        return $action;
    }

    // Vertrag 1.7 (EMS-Bitte 20.09.2026): was die Wallbox selbst über
    // Stromgrenzen und Phasenumschaltung sagt, nur lesend (GetConfiguration
    // mit gezielten Schlüsseln). `Station-MaxCurrent`/`MinChargingCurrent`
    // sind go-e-spezifisch (live an WB2 gesehen), `ConnectorSwitch3to1Phase
    // Supported` ist ein OCPP-1.6-Standardschlüssel. Kennt eine Wallbox einen
    // Schlüssel nicht, steht er in `unknownKey` und bleibt einfach unbekannt.
    private function requestStationCapabilities(string $cpid): void
    {
        $this->sendCall($cpid, 'GetConfiguration', ['key' => ['Station-MaxCurrent', 'MinChargingCurrent', 'ConnectorSwitch3to1PhaseSupported']]);
    }

    // Wertet eine GetConfiguration-Antwort aus (auch die der manuellen
    // Diagnoseabfrage) und reicht nur plausible Werte an den Ladepunkt
    // weiter; -1 = unbekannt. Nichts wird geraten.
    private function forwardStationCapabilities(string $cpid, $payload): void
    {
        if (!is_array($payload)) {
            return;
        }
        $values = [];
        foreach ((array)($payload['configurationKey'] ?? []) as $kv) {
            if (is_array($kv) && isset($kv['key'])) {
                $values[(string)$kv['key']] = trim((string)($kv['value'] ?? ''));
            }
        }
        $ampere = static function ($v, int $lo, int $hi): int {
            if ($v === null || !is_numeric($v)) {
                return -1;
            }
            $a = (int)round((float)$v);
            return ($a >= $lo && $a <= $hi) ? $a : -1;
        };
        $max = $ampere($values['Station-MaxCurrent'] ?? null, 6, 63);
        $min = $ampere($values['MinChargingCurrent'] ?? null, 6, 32);
        $switch = -1;
        if (isset($values['ConnectorSwitch3to1PhaseSupported'])) {
            $s = strtolower($values['ConnectorSwitch3to1PhaseSupported']);
            $switch = ($s === 'true') ? 1 : (($s === 'false') ? 0 : -1);
        }
        if ($max === -1 && $min === -1 && $switch === -1) {
            return;
        }
        $this->anKind($cpid, 'Kapazitaeten', ['MaxA' => $max, 'MinA' => $min, 'Phasen' => $switch]);
    }

    // Erzwingt kurze MeterValues-Intervalle (ChargerHub-Empfehlung
    // 30.08.2026, nach Prüfung des Live-Tests): ohne das kommt die eigene
    // Ladeleistung nur selten rein — die Überschussregelung in
    // OCPPHubLadepunkt::Update() rechnet dann mit veralteten Werten (siehe
    // dortige „Frische-Wache") bzw. setzt aus. MeterValuesSampledData wird
    // zusätzlich explizit gesetzt (Lehre aus der Recherche zum offiziellen
    // Symcon-Modul: „Datenausbeute gering", u. a. weil MeterValues nur bei
    // Statuswechsel/vollen kWh kam). Antworten (CALLRESULT/CALLERROR)
    // werden aktuell nur geloggt (siehe handleCall) — falls eine Wallbox
    // einen Konfigurationsschlüssel ablehnt, bleibt das vorerst nur im
    // Debug sichtbar, keine automatische Fallback-Logik in Stufe 1.
    private function requestFastMeterValues(string $cpid): void
    {
        $this->sendCall($cpid, 'ChangeConfiguration', ['key' => 'MeterValueSampleInterval', 'value' => '10']);
        $this->sendCall($cpid, 'ChangeConfiguration', ['key' => 'MeterValuesSampledData', 'value' => 'Power.Active.Import,Energy.Active.Import.Register']);
    }

    private function sendRaw(string $cpid, array $frame): void
    {
        $wsInstances = @IPS_GetInstanceListByModuleID(self::WEBHOOK_CONTROL_GUID);
        if (!$wsInstances) {
            $this->SendDebug('OCPPHub', OHUB_Text::t('No WebSocket server instance found — message not sent.'), 0);
            return;
        }
        $json = json_encode($frame);
        $this->SendDebug('OCPPHub Transmit [' . $cpid . ']', (string)$json, 0);
        WC_PushMessage($wsInstances[0], $this->hookPath() . '/' . rawurlencode($cpid), (string)$json);
    }

    // ---------------------------------------------------------------------
    // Verbund-Vertrag — feldgleich zu CHUB_GetFunctions 1.2 + additiv
    // transport/ocppVersion (siehe .docs/architektur.md „Vertrag
    // OHUB_GetFunctions"). NOCH NICHT final mit der EMS-Sitzung
    // gegenprogrammiert — Feldnamen können sich vor 1.0 noch ändern.
    // ---------------------------------------------------------------------

    public function GetFunctions(): array
    {
        // FIX 13.09.2026 (EMS-Fund): ein deaktivierter Splitter (Active=false,
        // ProcessHookData() ignoriert dann jede eingehende Nachricht komplett)
        // lieferte hier trotzdem weiter alle Ladepunkt-Einträge mit ihrem
        // letzten (potenziell noch frischen) lastSeenAt/Status — Konsumenten
        // hätten OCPPHub für aktiv gehalten, bis die Werte irgendwann
        // veralteten. Bei Active=false gibt es OFFENSICHTLICH keine
        // funktionierende Anbindung mehr, also leere Liste statt veralteter
        // Daten.
        if (!$this->ReadPropertyBoolean('Active')) {
            return [];
        }
        return array_map(static fn ($k) => $k['eintrag'], $this->kindVertraege());
    }
}
