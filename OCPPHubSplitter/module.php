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
// STUFE 1 (siehe .docs/pflichtenheft.md „Ausbaustufen"): Kernprotokoll +
// PV-Überschussladen, KEIN RFID-Zwang (Authorize.conf immer Accepted),
// KEINE Kundenverwaltung/Tarife/Reservierung — kommt mit Betriebsart ②/③.
// UNGETESTET gegen echte Symcon-Instanz/Emulator (siehe .docs/architektur.md
// „Test-Strategie") — vor Live-Betrieb mit apostoldevel/ocpp-cs prüfen.
// ===========================================================================

class OCPPHubSplitter extends IPSModule
{
    // Eingebaute Symcon-Kern-Instanz "WebHook Control" — verwaltet alle
    // registrierten Hooks (Property "Hooks", JSON-Liste aus {Hook,TargetID})
    // UND pusht Daten asynchron an eine darüber offene WebSocket-Verbindung
    // (WC_PushMessage). GUID verifiziert gegen zwei offizielle Symcon-Quellen
    // (symcon/OCPP UND symcon/SymconMisc/libs/WebHookModule.php).
    private const WEBHOOK_CONTROL_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';

    private const LADEPUNKT_GUID = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';
    private const ABRECHNUNG_GUID = '{64980198-6B36-45D5-A84F-A0EAE9CCC63A}';
    private const CHARGERHUB_GUID = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';

    private const OCPP_CALL       = 2;
    private const OCPP_CALLRESULT = 3;
    private const OCPP_CALLERROR  = 4;

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.2.17';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    // „Was ist neu"-Banner (Verbund-Konvention, siehe SUITE.md, Referenz
    // ChargerHub) — bei jedem nutzerrelevanten Änderungs-Bump aktualisieren,
    // NICHT bei jedem library.json-Build (sonst nervt es).
    private const NEWS_VERSION = '0.2.14';
    private const NEWS_ITEMS = [
        'Kritischer Fix (Live-Fund, direkt nach Einführung des Reset-/frc-Ausweichwegs entdeckt): schlug RemoteStartTransaction fehl, WEIL die Wallbox schon eine andere Sitzung fuhr (z. B. go-e nach einem Reset selbst lokal gestartet), löste unser Ausweichweg trotzdem einen Reset aus und unterbrach damit eine bereits laufende, funktionierende Ladung — sichtbar als kurze Ladeimpulse statt einer stabilen Sitzung. Der Ausweichweg prüft jetzt zuerst, ob am Ladepunkt schon tatsächlich geladen wird, und greift nur noch ein, wenn nicht.',
        'go-e-Ausweichweg für hängende Ladefreigabe: schlägt RemoteStartTransaction bei einer go-e-Wallbox fehl, versucht OCPPHub jetzt automatisch, deren privates FORCE_STATE-Register zurückzusetzen — zuerst über ChargerHub (CHUB_ClearForceLock(), falls installiert, auch wenn dessen Instanz deaktiviert ist), sonst per eigenem, bewusst minimalem Modbus-Schreibzugriff, damit das auch OHNE installiertes ChargerHub funktioniert. Bei jedem anderen Hersteller wirkungslos, kein Risiko.',
        'Neuer, herstellerneutraler Ausweichweg bei abgelehntem Ladestart: schlägt `RemoteStartTransaction` fehl, schickt OCPPHub jetzt automatisch einen OCPP-Standard-`Reset` (Soft) hinterher — ein Pflichtbestandteil von OCPP 1.6, den jeder konforme Hersteller unterstützen muss, kein Sonderweg für eine einzelne Marke.',
        'Kritischer Fix (Live-Fund): go-e lehnte `RemoteStopTransaction` bei einer laufenden Ladung wiederholt ab, obwohl die Sitzung nachweislich lief — Ladefreigabe „aus" hatte damit keine Wirkung. Automatischer Ausweichweg: wird der reguläre Stopp abgelehnt, schickt OCPPHub jetzt selbstständig ein Stromlimit von 0 A hinterher, das go-e zuverlässig annimmt und die Ladung tatsächlich beendet.',
        'Kritischer Fix (Live-Fund, erster echter Ladeversuch): jede Ablehnung einer Karte/eines Zugangs (nicht registriert, gesperrt, abgelaufen, Zeitfenster, Verbrauchslimit, Reservierung) zeigt jetzt sofort den genauen Grund am Ladepunkt (`block_reason`) — vorher landete das nur unsichtbar im Systemlog. Der manuelle „Ladefreigabe"-Schalter versucht außerdem zuerst den echten, registrierten Zugang eines bereits erkannten Fahrzeugs (neue Funktion OHUB_ManualStart()) statt eines internen Platzhalters, der unter Betriebsart ② ohnehin nie funktionieren konnte.',
        'Neue Diagnosefunktion OHUB_GetConfigurationKeys() — fragt beliebige OCPP-Konfigurationsschlüssel einer Wallbox ab, Antwort landet jetzt IMMER dauerhaft im Systemlog (nicht nur bei Ablehnung), da eine erfolgreiche GetConfiguration-Antwort kein "status"-Feld hat und darum bisher nur flüchtig im Debug-Fenster sichtbar war.',
        'Neu: automatische Ladefreigabe für erkannte Fahrzeuge ("so etwas wie Autocharge") — erkennt Dashboard per Zeitkorrelation ein Fahrzeug mit aktivem Zugang, wird bei Betriebsart ② automatisch dessen Karte "aufgelegt" (dieselbe Prüfung wie eine echte Kartenauflage, alle Limits/Zeitfenster gelten identisch). Design mit Dashboard abgestimmt.',
        'Neue Diagnose bei eindeutiger Ladeablehnung (RemoteStartTransaction/SetChargingProfile): fragt automatisch beim verknüpften Tessie-Fahrzeug und optional Tibber Grid Rewards nach einer möglichen Erklärung — sichtbar am Ladepunkt in der neuen Variable `block_reason`.',
        'Kritischer Fix (Dashboard-Fund, Live-Test): go-e lehnte jeden manuellen Ladestart mit "Rejected" ab, ohne dass das irgendwo sichtbar war. Ursache: RemoteStartTransaction fehlte das Feld connectorId — go-e wusste nicht, welchen Connector es starten soll. Jetzt fest auf 1 gesetzt (der tatsächlich ladende Connector, live an WB2 bestätigt). Außerdem: jede erkennbare Ablehnung (CALLERROR oder ein "status" ungleich "Accepted") auf einen von uns gesendeten Aufruf wird jetzt zusätzlich dauerhaft ins Systemlog geschrieben, nicht mehr nur ins flüchtige Debug-Fenster.',
        'Kritischer Fix (Dashboard-Fund, Live-Test): manueller Ladestart über Dashboard/ctl_enable schlug mit einem PHP-Fatal-Error ab. Ursache: Symcons generierte globale Funktion für RemoteStart() ignoriert PHP-Standardwerte auf Parametern — ein Aufruf mit nur 2 statt 3 Argumenten löste einen ArgumentCountError aus. Standardwert aus dem Quellcode entfernt, damit das nicht wieder passiert.',
        'Warnhinweis ergänzt: „OCPPHub Abrechnung" wird automatisch als Kind DIESER Splitter-Instanz angelegt — niemals selbst zusätzlich eine solche Instanz anlegen, eine zweite wird nie verwendet (live gefunden: Karten/Kunden in einer manuell angelegten zweiten Instanz blieben wirkungslos).',
        'Jede Kartenauflage (Authorize) wird jetzt zusätzlich ins dauerhafte Symcon-Systemlog geschrieben — vorher nur per SendDebug sichtbar, also unwiederbringlich weg, sobald das Debug-Fenster geschlossen war. Praktisch z. B. um den idTag einer neuen Karte nachträglich fürs Anlegen in der Abrechnung-Instanz nachzuschlagen.',
        'Kritischer Fix: jede MeterValues-Nachricht (Leistung/Energie/SoC) ließ den Splitter mit einem Fatal Error abstürzen — dadurch kamen power/energy_total NIE an, unabhängig von der Wallbox. Ursache: json_decode() ohne Assoziativ-Modus bei verschachtelten OCPP-Nachrichten. Betraf jede Wallbox, live an WB2 gefunden.',
        'Stufe 2: neues Betriebsart-Auswahlfeld (① Einzelnutzer / ② Mehrere Nutzer). Bei ② wird jede Kartenauflage zentral gegen die neue „OCPPHub Abrechnung"-Instanz geprüft (Kunden, Zugänge, Verbrauchslimits) — die legt sich automatisch selbst an.',
        'Reservierung (ReserveNow/CancelReservation) hinzugekommen, unabhängig von der Betriebsart nutzbar — Backend-Funktionen liegen am Ladepunkt (OHUBL_Reserve/CancelReservation).',
        'idTag-Direktzuordnung: ist eine Karte bereits einem Fahrzeug zugeordnet, wird der Fahrzeugname bei Betriebsart ② jetzt sofort gesetzt, ohne auf Dashboards Zeitkorrelation zu warten.',
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
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
            return $existing;
        }
        $newId = @IPS_CreateInstance(self::ABRECHNUNG_GUID);
        if (!$newId) {
            return 0;
        }
        @IPS_SetParent($newId, $this->InstanceID);
        @IPS_SetName($newId, IPS_GetName($this->InstanceID) . ' Abrechnung');
        @IPS_ApplyChanges($newId);
        $this->WriteAttributeInteger('AbrechnungID', $newId);
        return $newId;
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
                    'caption'  => '📖 Dokumentation & Hilfe (Version ' . self::VERSION . ')',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Was diese Instanz macht: Der Splitter ist das OCPP-„Central System" — die Gegenstelle, zu der sich Wallboxen per WebSocket verbinden. Er nimmt beliebig viele gleichzeitige Wallbox-Verbindungen entgegen, unterscheidet sie anhand ihrer Charge-Point-Identity (dem letzten Pfadstück der URL) und leitet jede eingehende OCPP-Nachricht an die passende „OCPPHub Ladepunkt"-Instanz weiter. Diese Splitter-Instanz selbst zeigt keine einzelne Wallbox und keine Ladeleistung — dafür ist ausschließlich die Ladepunkt-Instanz zuständig.'],
                        ['type' => 'Label', 'caption' => '📦 Instanzmodell im Überblick: Splitter (diese Instanz, genau einmal) → mehrere „OCPPHub Ladepunkt"-Instanzen (eine je Wallbox/Connector) → optional „OCPPHub Konfigurator" (praktisch fürs Ersteinrichten, zeigt bereits verbundene, aber noch nicht angelegte Wallboxen zum Ein-Klick-Anlegen). Jede Ladepunkt-Instanz muss im eigenen Formular explizit auf DIESEN Splitter zeigen (Feld „OCPPHub-Splitter") — das ist Pflicht und wird NICHT automatisch aus der Objektbaum-Position abgeleitet, weil sich Instanzen in der Konsole frei in andere Kategorien verschieben lassen, ohne dass sich an der eigentlichen Zuordnung etwas ändert.'],
                        ['type' => 'Label', 'caption' => '🔌 Wallbox einrichten: in deren eigener OCPP-Konfiguration als Backend-/Server-URL eintragen: ' . $this->hookPath() . '/<Charge-Point-Identity>. <Charge-Point-Identity> ist ein frei wählbarer Name, den die Wallbox selbst bei jeder Nachricht mitschickt (z. B. „WB1") — er muss NICHT vorher hier angelegt werden, sondern taucht nach dem ersten Verbindungsversuch automatisch im „OCPPHub Konfigurator" auf. Subprotokoll ist „ocpp1.6", Nachrichtenformat OCPP-J (JSON über WebSocket) — kein separater Port, kein externer Prozess, Symcon übernimmt den WebSocket-Handshake komplett selbst über seinen eingebauten Hook-Mechanismus.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Funktionsumfang (aktueller Stand): vollständiges OCPP-1.6J-Kernprotokoll, eigenständiges PV-Überschussladen als Fallback ohne EMS, Reservierung, sowie ab Betriebsart ② zentrale RFID-Autorisierung mit Kundenverwaltung/Verbrauchslimits (siehe „OCPPHub Abrechnung"-Instanz). Bewusst NOCH NICHT enthalten: Tarife/Kostenberechnung, Berichte/CSV-Export, Reservierungsgebühr, Lastverteilung über mehrere eigene Ladepunkte hinweg — das ist Stufe 3 (siehe `.docs/pflichtenheft.md`).'],
                        ['type' => 'Label', 'caption' => '🔎 Fehlersuche: Meldet sich eine Wallbox nicht, zuerst die Debug-Ausgabe dieser Instanz aktivieren (Konsole → diese Instanz → Debug-Meldungen) und einen Verbindungsversuch der Wallbox abwarten — jede eingehende Anfrage wird dort mit Methode und Pfad protokolliert, bevor irgendetwas geprüft wird. Kommt gar nichts an: URL/Pfad an der Wallbox prüfen. Kommt etwas an, wird aber abgelehnt: meist Basic-Auth (siehe unten) oder ein noch nicht verstandenes OCPP-Detail dieser Wallbox — dann bitte über GitHub melden.'],
                        ['type' => 'Label', 'caption' => '⚠️ Ungetestet gegen die meisten OCPP-1.6J-Wallboxen außer go-e — bei anderen Herstellern bitte Rückmeldung über GitHub geben, falls etwas nicht passt (Measurand-Namen/Einheiten in MeterValues, ChargingRateUnit bei SetChargingProfile).'],
                        ['type' => 'Label', 'caption' => '• go-e Gemini/HOME+: OCPP 1.6J ab Firmware 56.1 (besser ≥56.8), Aktivierung per App, WSS + HTTP-Basic-Auth empfohlen. Referenz-/Testhardware dieses Moduls, siehe „Was ist neu" oben für den aktuellen Live-Test-Stand.'],
                        ['type' => 'Label', 'caption' => '• KEBA P30: OCPP 1.6 nur bei der x-series — die c-series kann kein OCPP (dort ChargerHub per Modbus/UDP nutzen).'],
                        ['type' => 'Label', 'caption' => '• Alfen Eve (Single/Double Pro-line): OCPP ist dort das native Primärprotokoll, sollte gut funktionieren — noch nicht selbst getestet.'],
                        ['type' => 'Label', 'caption' => '• Heidelberg Energy Control: kann KEIN OCPP (nur Modbus RTU) — dafür ChargerHub verwenden, nicht OCPPHub.'],
                        ['type' => 'Label', 'caption' => '🧩 Verbund: OCPPHub ist das OCPP-Geschwistermodul zu ChargerHub (Modbus TCP) — beide melden Wallboxen über einen feldgleichen Vertrag (`OHUB_GetFunctions`/`CHUB_GetFunctions`) an EMS und Dashboard, sodass es für die konsumierenden Module keinen Unterschied macht, ob eine Wallbox per Modbus oder OCPP angebunden ist.'],
                    ],
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'Active',
                    'caption' => 'Aktiv',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'WebSocket-Endpunkt für Wallboxen: ' . $this->hookPath() . '/<Charge-Point-Identity>',
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'Betriebsart',
                    'caption' => 'Betriebsart',
                    'width'   => '560px',
                    'options' => [
                        ['caption' => '① Einzelnutzer — kein RFID-Zwang, jede Karte wird angenommen', 'value' => 1],
                        ['caption' => '② Mehrere Nutzer — zentrale Autorisierung über die Abrechnung-Instanz', 'value' => 2],
                    ],
                ],
                ['type' => 'Label', 'caption' => 'ℹ️ Bei ① werden Kundenverwaltung/Verbrauchslimits in der „OCPPHub Abrechnung"-Instanz zwar schon angelegt (sie existiert immer), aber NICHT ausgewertet — jede Karte lädt ungeprüft. Bei ② wird jede Kartenauflage gegen die dort gepflegten Zugänge geprüft. Reservierungen (siehe Ladepunkt-Instanz) werden unabhängig von der Betriebsart durchgesetzt, sobald eine aktiv ist.'],
                ['type' => 'Label', 'caption' => '⚠️ „OCPPHub Abrechnung" wird automatisch als DIREKTES KIND DIESER Splitter-Instanz angelegt (im Objektbaum darunter zu finden) — ein bewusst ungewöhnliches Muster (sonst legt bei uns keine Instanz von sich aus eine Konfigurationsinstanz unter sich selbst an), aber Standard in OCPPHub. Lege NIEMALS selbst zusätzlich eine „OCPPHub Abrechnung"-Instanz an (z. B. über die Modulverwaltung) — eine solche zweite Instanz wird von diesem Splitter nie verwendet, egal wo im Baum sie liegt, und jede darin gepflegte Karte/jeder Kunde bleibt wirkungslos.'],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔐 Basic-Auth (optional)',
                    'items'   => [
                        ['type' => 'Label', 'caption' => 'Nur nötig, falls Symcon aus einem nicht vollständig vertrauenswürdigen Netz erreichbar ist (z. B. wenn der WebSocket-Endpunkt über eine Portweiterleitung von außerhalb erreichbar gemacht wird) — im reinen Heimnetz meist verzichtbar. Leerer Benutzername = kein Basic-Auth, jede Wallbox darf sich verbinden.'],
                        ['type' => 'Label', 'caption' => 'Ist ein Benutzername gesetzt, muss die Wallbox in ihrer eigenen OCPP-Konfiguration genau denselben Benutzernamen und dasselbe Passwort für HTTP-Basic-Auth hinterlegen — sonst wird jede Verbindung mit HTTP 401 abgewiesen (sichtbar in der Debug-Ausgabe als „Basic-Auth abgelehnt").'],
                        ['type' => 'Label', 'caption' => 'Zugangsdaten-Konvention (Verbund-Regel 7): das Passwort wird nach der Übernahme sofort gehasht gespeichert und dieses Feld hier automatisch geleert — beim erneuten Öffnen steht hier also nie das bestehende Passwort im Klartext, auch nicht für Dich selbst. Zum Ändern einfach ein neues eintragen; leer lassen behält das bisherige Passwort bei.'],
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'BasicAuthUsername',
                            'caption' => 'Benutzername',
                        ],
                        [
                            'type'    => 'PasswordTextBox',
                            'name'    => 'BasicAuthPassword',
                            'caption' => 'Neues Passwort (leer lassen, um das bestehende zu behalten)',
                        ],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => '🔄 Übernehmen erzwingen (ohne Formularänderung)', 'onClick' => "IPS_ApplyChanges(\$id); echo '✅ ApplyChanges() ausgeführt.';"],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Deaktiviert'],
            ],
        ];

        // GitHub-Rückmeldungshinweis (Verbund-Konvention, noch kein
        // Forum-Beitrag online), einmalig ausblendbar.
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type'  => 'RowLayout',
                'name'  => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub ist früher Beta-Stand — Rückmeldungen willkommen über github.com/DG65/NRGOCPPHub.'],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'OHUB_DismissReviewHint($id);'],
                ],
            ];
        }

        // „Was ist neu"-Banner ganz oben, vor dem Doku-Panel.
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

    // „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet
    // leer), bis der Nutzer „Verstanden" klickt. Neuinstallation sieht es
    // einmalig. Muster wie ChargerHub.
    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'OHUB_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
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
            $this->SendDebug('OCPPHub', 'Instanz inaktiv — Anfrage ignoriert.', 0);
            return;
        }

        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            $this->SendDebug('OCPPHub', 'Leerer Anfrage-Body (evtl. reiner WebSocket-Handshake ohne Daten-Frame).', 0);
            return;
        }

        $prefix = $this->hookPath() . '/';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $pos = strpos($uri, $prefix);
        if ($pos === false) {
            $this->SendDebug('OCPPHub', 'Hook ohne Charge-Point-Identity: ' . $uri, 0);
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
        $this->forwardSourceIp($cpid);

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
            $this->SendDebug('OCPPHub', 'Ungültiges OCPP-J-Frame: ' . $raw, 0);
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
                    IPS_LogMessage('OCPPHub', 'Ablehnung/Fehler auf gesendeten Aufruf [' . $cpid . ']: ' . $raw);
                } elseif ($action === 'GetConfiguration') {
                    // Live-Fund 31.08.2026 (Dietmar, am eigenen Leib erlebt): eine
                    // ERFOLGREICHE Antwort ohne "status"-Feld (z. B. GetConfiguration.conf
                    // liefert nur eine Werteliste, kein status) fiel oben durch —
                    // genau die Sackgasse, die block_reason für Ladeablehnungen lösen
                    // sollte, hier für eine reine Diagnoseabfrage nochmal live erlebt.
                    // GetConfiguration wird nur gezielt/selten manuell zur Fehlersuche
                    // gesendet (nicht im Normalbetrieb) — darum hier bewusst IMMER
                    // dauerhaft geloggt, nicht nur bei Ablehnung, kein Spam-Risiko.
                    IPS_LogMessage('OCPPHub', 'Antwort auf GetConfiguration [' . $cpid . ']: ' . $raw);
                }
                // Ladeablehnung erklären (Diagnose-Feature 31.08.2026, siehe
                // .docs/architektur.md): nur bei einer EINDEUTIGEN Ablehnung
                // auf genau die beiden Aktionen, die tatsächlich einen
                // Ladevorgang anstoßen sollen — eine abgelehnte
                // ChangeConfiguration o. ä. braucht keine Fahrzeugdiagnose.
                if (in_array($action, ['RemoteStartTransaction', 'SetChargingProfile'], true)) {
                    $ladepunktId = $this->findLadepunkt($cpid);
                    if ($ladepunktId !== 0) {
                        if ($isFailure) {
                            OHUBL_DiagnoseBlockReason($ladepunktId);
                        } else {
                            // War zuvor eine Begründung gesetzt und jetzt
                            // klappt derselbe Aufruftyp doch (z. B. nach
                            // Aufwecken des Fahrzeugs) — nicht stehen lassen.
                            OHUBL_ClearBlockReason($ladepunktId);
                        }
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
                    IPS_LogMessage('OCPPHub', 'RemoteStopTransaction abgelehnt [' . $cpid . '] — versuche Stromlimit 0 A als Ausweichweg.');
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
                    $ladepunktIdForRecovery = $this->findLadepunkt($cpid);
                    $alreadyCharging = $ladepunktIdForRecovery !== 0 && OHUBL_GetState($ladepunktIdForRecovery) === 2;
                    if ($alreadyCharging) {
                        IPS_LogMessage('OCPPHub', 'RemoteStartTransaction abgelehnt [' . $cpid . '] — Ladepunkt lädt aber bereits (vermutlich andere Sitzung), Reset/frc-Ausweichweg übersprungen.');
                    } else {
                        IPS_LogMessage('OCPPHub', 'RemoteStartTransaction abgelehnt [' . $cpid . '] — sende Soft-Reset als herstellerneutralen Ausweichweg.');
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
            $this->SendDebug('OCPPHub', 'Basic-Auth-Nutzername gesetzt, aber kein Passwort hinterlegt — Zugriff vorerst ungeprüft erlaubt.', 0);
            return true;
        }
        [$gotUser, $gotPass] = $this->getBasicAuthCredentials();
        $ok = hash_equals($user, $gotUser) && password_verify($gotPass, $hash);
        if (!$ok) {
            $headerPresent = ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '') !== '';
            $this->SendDebug(
                'OCPPHub',
                'Basic-Auth abgelehnt (erhaltener Nutzername: "' . $gotUser . '", '
                    . 'Authorization-Header überhaupt vorhanden: ' . ($headerPresent ? 'ja' : 'nein') . ').',
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
        if ($this->findLadepunkt($cpid) !== 0) {
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
    private function forwardSourceIp(string $cpid): void
    {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return;
        }
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            OHUBL_SetSourceIP($ladepunktId, $ip);
        }
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

        if ($action === 'BootNotification') {
            // ERST die BootNotification.conf raus (oben), DANACH die
            // ChangeConfiguration-Aufrufe — nicht umgekehrt, manche
            // Wallbox-Firmware (u. a. Testgerät go-e) erwartet die Antwort
            // auf ihren eigenen Aufruf vor weiteren CALLs vom Central System.
            $this->requestFastMeterValues($cpid);
        }
    }

    private function onBootNotification(string $cpid, array $payload): array
    {
        $ladepunktId = $this->findLadepunkt($cpid);
        $vendor = (string)($payload['chargePointVendor'] ?? '');
        if ($ladepunktId !== 0) {
            OHUBL_UpdateBootInfo(
                $ladepunktId,
                $vendor,
                (string)($payload['chargePointModel'] ?? ''),
                (string)($payload['chargePointSerialNumber'] ?? '')
            );
        }
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
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            OHUBL_UpdateStatus(
                $ladepunktId,
                (string)($payload['status'] ?? ''),
                (string)($payload['errorCode'] ?? '')
            );
        }
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
        IPS_LogMessage('OCPPHub', 'Authorize [' . $cpid . ']: idTag="' . $idTag . '" -> ' . $status);
        return $status;
    }

    private function checkIdTagInternal(string $cpid, string $idTag): string
    {
        $ladepunktId = $this->findLadepunkt($cpid);

        // Reservierung: außerhalb des berechtigten idTags -> Blocked (siehe
        // .docs/architektur.md „Reservierung"). Geht der Prüfung unten vor,
        // auch bei Betriebsart ①, weil eine Reservierung nur sinnvoll ist,
        // wenn sie auch durchgesetzt wird.
        if ($ladepunktId !== 0) {
            $reservedIdTag = OHUBL_GetActiveReservationIdTag($ladepunktId);
            if ($reservedIdTag !== '' && $reservedIdTag !== $idTag) {
                OHUBL_ReportBlockedStart($ladepunktId, $this->explainAuthStatus('Blocked', 'reserviert'));
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
        $result = OHUBA_CheckAuthorization($abrechnungId, $idTag);
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
        if ($ladepunktId !== 0) {
            if ($status === 'Accepted') {
                OHUBL_ClearBlockReason($ladepunktId);
            } else {
                OHUBL_ReportBlockedStart($ladepunktId, $this->explainAuthStatus($status, $reason));
            }
        }

        // idTag-Direktzuordnung (Vorrang vor Dashboards Zeitkorrelation,
        // mit Dashboard abgestimmt, siehe architektur.md „Fahrzeug-
        // Zuordnung & SOC") — bei erfolgreicher Autorisierung mit
        // bekanntem Fahrzeug sofort setzen.
        if ($status === 'Accepted' && $ladepunktId !== 0 && !empty($result['vehicleName'])) {
            // $TimeCorrelated = false: diese Zuordnung läuft bereits mitten in
            // einer ECHTEN Autorisierung (Karte war schon Accepted) — eine
            // zusätzliche Auto-Autorisierung (siehe AutoAuthorizeVehicle()
            // unten) wäre hier redundant und könnte sogar ein doppeltes
            // RemoteStartTransaction auf eine bereits laufende Transaktion
            // auslösen. Der Parameter ist NUR für Dashboards eigenständige,
            // von keiner Autorisierung begleitete Fahrzeugerkennung gedacht.
            OHUBL_SetVehicleName($ladepunktId, (string)$result['vehicleName'], false);
            // Additiv (Diagnose-Feature 31.08.2026): merkt sich die
            // verknüpfte Tessie-Instanz für eine spätere Ladeablehnung-
            // Diagnose (siehe OHUBL_DiagnoseBlockReason()) — 0 = kein
            // Tessie-verknüpftes Fahrzeug, dann bleibt die Diagnose auf den
            // Tibber-Namensabgleich beschränkt.
            OHUBL_SetVehicleTessieId($ladepunktId, (int)($result['vehicleTessieInstanceId'] ?? 0));
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
        $ladepunktId = $this->findLadepunkt($cpid);
        $abrechnungId = $this->ensureAbrechnung();
        if ($abrechnungId === 0) {
            return;
        }
        $idTag = OHUBA_FindIdTagForVehicleName($abrechnungId, $vehicleName);
        if ($idTag === '') {
            if ($ladepunktId !== 0) {
                OHUBL_ReportBlockedStart($ladepunktId, 'Kein Zugang für „' . $vehicleName . '" registriert — bitte Karte anlernen oder Zugang anlegen.');
            }
            return;
        }
        if ($this->checkIdTag($cpid, $idTag) !== 'Accepted') {
            // checkIdTagInternal() hat block_reason bereits mit dem
            // konkreten Grund (gesperrt/abgelaufen/Limit/...) gesetzt.
            return;
        }
        if ($ladepunktId === 0) {
            return;
        }
        // Echten idTag verwenden (nicht 'symcon' wie beim manuellen Dashboard-
        // Start) — StartTransaction.req der Wallbox trägt ihn zurück,
        // RecordConsumption() rechnet den Verbrauch dadurch dem richtigen
        // Kunden zu.
        OHUBL_ConfirmAutoStart($ladepunktId);
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
        $ladepunktId = $this->findLadepunkt($cpid);
        $vehicleName = $ladepunktId !== 0 ? OHUBL_GetVehicleName($ladepunktId) : '';
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
            return 'Karte/Zugang ist nicht registriert.';
        }
        if ($status === 'Expired') {
            return 'Zugang ist abgelaufen.';
        }
        if ($status === 'Blocked') {
            switch ($reason) {
                case 'zugang-gesperrt':
                    return 'Zugang ist gesperrt.';
                case 'ausserhalb-zeitfenster':
                    return 'Außerhalb des erlaubten Zeitfensters für diesen Zugang.';
                case 'kein-kunde-zugeordnet':
                    return 'Zugang ist keinem aktiven Kunden zugeordnet.';
                case 'kunde-gesperrt':
                    return 'Kunde ist gesperrt.';
                case 'limit-erreicht':
                    return 'Verbrauchslimit ist erreicht.';
                case 'reserviert':
                    return 'Für diesen Zeitraum ist die Wallbox reserviert.';
                default:
                    return 'Zugang ist gesperrt.';
            }
        }
        return '';
    }

    private function onStartTransaction(string $cpid, array $payload): array
    {
        $transactionId = $this->nextTransactionId();
        $idTag = (string)($payload['idTag'] ?? '');
        $status = $this->checkIdTag($cpid, $idTag);
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            OHUBL_StartTransaction(
                $ladepunktId,
                $transactionId,
                $idTag,
                (int)($payload['meterStart'] ?? 0)
            );
        }
        return [
            'idTagInfo'     => ['status' => $status],
            'transactionId' => $transactionId,
        ];
    }

    private function onStopTransaction(string $cpid, array $payload): array
    {
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            $meterStop = (int)($payload['meterStop'] ?? 0);
            $meterStart = OHUBL_GetMeterStartWh($ladepunktId);
            OHUBL_StopTransaction(
                $ladepunktId,
                (int)($payload['transactionId'] ?? 0),
                $meterStop
            );

            // Verbrauch dem Kunden gutschreiben (Stufe 2, für die
            // Limit-Prüfung in checkIdTag()/OHUBA_CheckAuthorization()).
            // idTag ist in StopTransaction.req laut OCPP-1.6-Spezifikation
            // OPTIONAL — falls die Wallbox es nicht mitschickt, auf den bei
            // StartTransaction gemerkten idTag zurückfallen.
            if ($this->ReadPropertyInteger('Betriebsart') === 2 && $meterStop > $meterStart) {
                $idTag = (string)($payload['idTag'] ?? '') ?: OHUBL_GetLastIdTag($ladepunktId);
                $abrechnungId = $this->ensureAbrechnung();
                if ($idTag !== '' && $abrechnungId > 0) {
                    OHUBA_RecordConsumption($abrechnungId, $idTag, ($meterStop - $meterStart) / 1000.0, time());
                }
            }
        }
        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    private function onMeterValues(string $cpid, array $payload): \stdClass
    {
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
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
                OHUBL_UpdateMeterValues($ladepunktId, $powerW, $energyWh, $socPercent);
            }
        }
        return new \stdClass(); // MeterValues.conf: leeres Objekt
    }

    private function nextTransactionId(): int
    {
        $id = $this->ReadAttributeInteger('NextTransactionId');
        $this->WriteAttributeInteger('NextTransactionId', $id + 1);
        return $id;
    }

    private function findLadepunkt(string $cpid): int
    {
        foreach ($this->ownLadepunkte() as $childId) {
            if (@IPS_GetProperty($childId, 'CPID') === $cpid) {
                return $childId;
            }
        }
        return 0;
    }

    // FIX 30.08.2026 (Live-Fund, Dashboard-Diagnose + eigene Nachprüfung
    // direkt an Dietmars Instanz): `IPS_GetChildrenIDs($this->InstanceID)`
    // spiegelt NICHT zuverlässig die Splitter-Zuordnung — Instanzen lassen
    // sich in der Konsole frei in andere Kategorien verschieben (bei
    // Dietmar unter „Geräte / Module" organisiert), wodurch die
    // Objektbaum-Position von der tatsächlichen Splitter-Zugehörigkeit
    // abweicht. Live bestätigt: WB1 lag unter einer fremden Kategorie
    // (#29186), `IPS_GetChildrenIDs()` auf den Splitter lieferte leer.
    // Jetzt: alle Ladepunkt-Instanzen im System durchsuchen und über die
    // eigene `SplitterID`-Property filtern (Property, keine Objektbaum-
    // Abfrage) — mit Objektbaum-Position als Rückfall für Alt-Instanzen,
    // die noch keine SplitterID gesetzt haben.
    private function ownLadepunkte(): array
    {
        $result = [];
        foreach (@IPS_GetInstanceListByModuleID(self::LADEPUNKT_GUID) ?: [] as $childId) {
            $explicitSplitterId = (int)@IPS_GetProperty($childId, 'SplitterID');
            if ($explicitSplitterId > 0) {
                if ($explicitSplitterId === $this->InstanceID) {
                    $result[] = $childId;
                }
                continue;
            }
            // Rückfall für Instanzen ohne gesetzte SplitterID (Alt-Stand
            // vor diesem Fix, oder noch nicht konfiguriert).
            if ((int)(@IPS_GetParent($childId) ?: 0) === $this->InstanceID) {
                $result[] = $childId;
            }
        }
        return $result;
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
            IPS_LogMessage('OCPPHub', 'ChargerHub-Kooperation [' . $cpid . ']: ClearForceLock() -> ' . ($cleared ? 'erfolgreich' : 'kein Effekt') . '.');
            if ($cleared) {
                return;
            }
        }
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($ip === '') {
            return;
        }
        $ok = $this->writeModbusRegister($ip, 502, 1, 337, 0);
        IPS_LogMessage('OCPPHub', 'go-e-Ausweichweg [' . $cpid . ']: FORCE_STATE per Modbus auf 0 setzen -> ' . ($ok ? 'erfolgreich' : 'fehlgeschlagen') . '.');
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
            $this->SendDebug('OCPPHub', 'Keine WebSocket-Server-Instanz gefunden — Nachricht nicht gesendet.', 0);
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
        $entries = [];
        foreach ($this->ownLadepunkte() as $childId) {
            $entry = OHUBL_GetContractEntry($childId);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }
}
