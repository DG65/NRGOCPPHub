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

    private const OCPP_CALL       = 2;
    private const OCPP_CALLRESULT = 3;
    private const OCPP_CALLERROR  = 4;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        // Basic-Auth optional (leerer Nutzername = kein Schutz). Zugangsdaten-
        // Konvention (Verbund-Regel 7): Passwort nur als Formular-Eingabe
        // (Property), nach Übernahme gehasht ins Attribut, Property geleert.
        $this->RegisterPropertyString('BasicAuthUsername', '');
        $this->RegisterPropertyString('BasicAuthPassword', '');
        $this->RegisterAttributeString('BasicAuthPasswordHash', '');

        // Fortlaufende OCPP-TransactionId je Splitter (nicht je Ladepunkt —
        // OCPP verlangt nur Eindeutigkeit, keine Ladepunkt-Bindung).
        $this->RegisterAttributeInteger('NextTransactionId', 1);

        // Zuletzt gesehene, noch nicht als Ladepunkt-Instanz angelegte
        // Charge-Point-Identities — vom Konfigurator gelesen.
        // Struktur: { "<cpid>": <unix-timestamp letzte Sichtung> }
        $this->RegisterAttributeString('SeenChargePoints', '{}');

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
        }

        $this->SetStatus($this->ReadPropertyBoolean('Active') ? 102 : 104);

        if ($plainPassword !== '') {
            @IPS_SetProperty($this->InstanceID, 'BasicAuthPassword', '');
            @IPS_ApplyChanges($this->InstanceID);
        }
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
        return json_encode([
            'elements' => [
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
                    'type'    => 'ExpansionPanel',
                    'caption' => '🔐 Basic-Auth (optional)',
                    'items'   => [
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
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Deaktiviert'],
            ],
        ]);
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

        $message = json_decode($raw);
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
                // SetChargingProfile) — Stufe 1 protokolliert nur, keine
                // Korrelationstabelle. TODO ab Stufe 2, falls nötig.
                $this->SendDebug('OCPPHub CALLRESULT/CALLERROR [' . $cpid . ']', $raw, 0);
                break;
        }
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
    }

    private function onBootNotification(string $cpid, array $payload): array
    {
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            OHUBL_UpdateBootInfo(
                $ladepunktId,
                (string)($payload['chargePointVendor'] ?? ''),
                (string)($payload['chargePointModel'] ?? ''),
                (string)($payload['chargePointSerialNumber'] ?? '')
            );
        }
        return [
            'status'      => 'Accepted',
            'currentTime' => gmdate('Y-m-d\TH:i:s\Z'),
            // MeterValues-Intervall — Lehre aus der Recherche zum offiziellen
            // Symcon-Modul: ohne aktives Hochsetzen "Datenausbeute gering".
            // Stufe 1 setzt hier fest 300s, ein ChangeConfiguration-Aufruf
            // beim Boot auf 10-30s (Regelbetrieb) folgt in einer späteren
            // Stufe.
            'interval' => 300,
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
        // Stufe 1 (Betriebsart „Einzelnutzer"): kein RFID-Zwang, jede Karte
        // wird angenommen. Zentrale Whitelist-Prüfung kommt mit Betriebsart
        // ②, siehe .docs/architektur.md „Authentifizierung".
        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    private function onStartTransaction(string $cpid, array $payload): array
    {
        $transactionId = $this->nextTransactionId();
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            OHUBL_StartTransaction(
                $ladepunktId,
                $transactionId,
                (string)($payload['idTag'] ?? ''),
                (int)($payload['meterStart'] ?? 0)
            );
        }
        return [
            'idTagInfo'     => ['status' => 'Accepted'],
            'transactionId' => $transactionId,
        ];
    }

    private function onStopTransaction(string $cpid, array $payload): array
    {
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            OHUBL_StopTransaction(
                $ladepunktId,
                (int)($payload['transactionId'] ?? 0),
                (int)($payload['meterStop'] ?? 0)
            );
        }
        return ['idTagInfo' => ['status' => 'Accepted']];
    }

    private function onMeterValues(string $cpid, array $payload): \stdClass
    {
        $ladepunktId = $this->findLadepunkt($cpid);
        if ($ladepunktId !== 0) {
            $powerW = null;
            $energyWh = null;
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
                    }
                }
            }
            if ($powerW !== null || $energyWh !== null) {
                OHUBL_UpdateMeterValues($ladepunktId, $powerW, $energyWh);
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
        foreach (@IPS_GetChildrenIDs($this->InstanceID) ?: [] as $childId) {
            $instance = @IPS_GetInstance($childId);
            if (!$instance || $instance['ModuleInfo']['ModuleID'] !== self::LADEPUNKT_GUID) {
                continue;
            }
            if (@IPS_GetProperty($childId, 'CPID') === $cpid) {
                return $childId;
            }
        }
        return 0;
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

    public function RemoteStart(string $cpid, string $idTag = 'symcon'): void
    {
        $this->sendCall($cpid, 'RemoteStartTransaction', ['idTag' => $idTag]);
    }

    public function RemoteStop(string $cpid, int $transactionId): void
    {
        $this->sendCall($cpid, 'RemoteStopTransaction', ['transactionId' => $transactionId]);
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

    private function sendCall(string $cpid, string $action, array $payload): void
    {
        $this->sendRaw($cpid, [self::OCPP_CALL, uniqid('ohub_', true), $action, $payload]);
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
        foreach (@IPS_GetChildrenIDs($this->InstanceID) ?: [] as $childId) {
            $instance = @IPS_GetInstance($childId);
            if (!$instance || $instance['ModuleInfo']['ModuleID'] !== self::LADEPUNKT_GUID) {
                continue;
            }
            $entry = OHUBL_GetContractEntry($childId);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }
        return $entries;
    }
}
