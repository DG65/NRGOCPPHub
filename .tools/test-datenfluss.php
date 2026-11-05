<?php
// Prüfstand für den Datenfluss zwischen den OCPPHub-Modulen (Symcon-Review 30.10.2026,
// Protokoll siehe .docs/datenfluss.md).
//
// Die echten Modulklassen laufen über einen nachgebildeten Symcon-Bus: SendDataToChildren geht an
// alle Kinder (deren ReceiveData-Antworten kommen als Array zurück), SendDataToParent geht an
// ForwardData des Elternmoduls. Schwere Modulfunktionen (Variablen, Timer) werden in Testdoppeln
// durch Aufzeichnungen ersetzt, die Nachrichtenwege sind echter Modulcode.
// Aufruf:  php .tools/test-datenfluss.php        (0 = alles grün)

if (PHP_SAPI !== 'cli') {
    exit(1);
}

const ZS = '{6ED0496A-EDD0-4600-9549-04D18B8387F5}';   // Kind -> Splitter
const ZK = '{DD617475-D014-4559-BC1C-6A39FCA4EDEE}';   // Splitter -> Kind
const G_SPLITTER = '{81D3E328-9E12-43A9-825A-F7888530868C}';
const G_LADEPUNKT = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';
const G_ABRECHNUNG = '{64980198-6B36-45D5-A84F-A0EAE9CCC63A}';
const G_KONFIGURATOR = '{FABC8306-1A0A-4BE4-9C3A-948ECFD28760}';
const G_WEBHOOK = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
const KR_READY = 10103;
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;

// ---- Nachgebildetes IPS ----------------------------------------------------------
final class Bus
{
    public static array $obj = [];     // id => Modulobjekt
    public static array $guid = [];    // id => Modul-GUID
    public static array $conn = [];    // id => Eltern-ID (0 = nicht verbunden)
    public static array $gesendet = [];// an die Wallbox gepushte OCPP-Frames [cpid, frame]
    public static array $log = [];     // LogMessage-Zeilen
    public static array $ereignisse = [];
    public static array $formfelder = []; // UpdateFormField-Aufrufe [Feld, Eigenschaft, Wert]

    public static function reset(): void
    {
        self::$obj = self::$guid = self::$conn = self::$gesendet = self::$log = self::$ereignisse = self::$formfelder = [];
    }

    public static function add(int $id, object $o, string $guid, int $conn = 0): object
    {
        $o->InstanceID = $id;
        self::$obj[$id] = $o;
        self::$guid[$id] = $guid;
        self::$conn[$id] = $conn;
        return $o;
    }

    public static function kinder(int $eltern): array
    {
        $out = [];
        foreach (self::$conn as $id => $p) {
            if ($p === $eltern) {
                $out[] = self::$obj[$id];
            }
        }
        return $out;
    }
}

// Sprache des Prüfstands: Standard Deutsch (libs/locale.json), mit OHUB_SPRACHE=en die englischen Quelltexte.
$locale = getenv('OHUB_SPRACHE') === 'en' ? [] : json_decode((string)file_get_contents(dirname(__DIR__) . '/libs/locale.json'), true)['translations']['de'];

class IPSModule
{
    public $InstanceID = 0;
    public array $props = [];
    public array $attrs = [];
    public array $werte = [];
    public function __construct($InstanceID = 0) {}
    public function Translate($text) { return $GLOBALS['locale'][$text] ?? $text; }
    public function SendDebug(...$a) {}
    public function LogMessage($t, $l) { Bus::$log[] = [$this->InstanceID, $l, $t]; }
    public function ReadPropertyInteger($n) { return (int)($this->props[$n] ?? 0); }
    public function ReadPropertyString($n) { return (string)($this->props[$n] ?? ''); }
    public function ReadPropertyBoolean($n) { return (bool)($this->props[$n] ?? false); }
    public function ReadPropertyFloat($n) { return (float)($this->props[$n] ?? 0.0); }
    public function ReadAttributeString($n) { return (string)($this->attrs[$n] ?? ''); }
    public function ReadAttributeInteger($n) { return (int)($this->attrs[$n] ?? 0); }
    public function ReadAttributeBoolean($n) { return (bool)($this->attrs[$n] ?? false); }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeInteger($n, $v) { $this->attrs[$n] = $v; }
    public function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    public function GetValue($i) { return $this->werte[$i] ?? 0; }
    public function SetValue($i, $v) { $this->werte[$i] = $v; }
    public function SetTimerInterval($n, $ms) { Bus::$ereignisse[] = ['timer', $this->InstanceID, $n, $ms]; }
    public function UpdateFormField($f, $p, $v) { Bus::$formfelder[] = [$f, $p, $v]; }
    public function SetStatus($s) {}
    public function RegisterMessage($a, $b) {}
    public function SendDataToChildren($json)
    {
        $out = [];
        foreach (Bus::kinder($this->InstanceID) as $k) {
            $out[] = method_exists($k, 'ReceiveData') ? $k->ReceiveData($json) : '';
        }
        return $out;
    }
    public function SendDataToParent($json)
    {
        $p = Bus::$conn[$this->InstanceID] ?? 0;
        return $p > 0 ? Bus::$obj[$p]->ForwardData($json) : '';
    }
}

function IPS_GetKernelRunlevel() { return KR_READY; }
function IPS_InstanceExists($id) { return isset(Bus::$obj[$id]); }
function IPS_GetInstance($id)
{
    return ['ModuleInfo' => ['ModuleID' => Bus::$guid[$id] ?? '', 'LibraryID' => '{L}'], 'ConnectionID' => Bus::$conn[$id] ?? 0, 'InstanceStatus' => 102];
}
function IPS_GetParent($id) { return 0; }
function IPS_GetName($id) { return 'Instanz ' . $id; }
function IPS_GetLibrary($g) { return ['Version' => '0.7.0']; }
function IPS_ConnectInstance($kind, $eltern) { Bus::$conn[$kind] = $eltern; Bus::$ereignisse[] = ['verbunden', $kind, $eltern]; return true; }
function IPS_GetInstanceListByModuleID($g)
{
    if ($g === G_WEBHOOK) {
        return [999];
    }
    return array_keys(array_filter(Bus::$guid, fn ($x) => $x === $g));
}
function IPS_GetProperty($id, $n) { return Bus::$obj[$id]->props[$n] ?? ''; }
function IPS_SetProperty($id, $n, $v) { Bus::$obj[$id]->props[$n] = $v; return true; }
function IPS_ApplyChanges($id) { return true; }
function WC_PushMessage($ws, $pfad, $json) { Bus::$gesendet[] = [rawurldecode(substr($pfad, strrpos($pfad, '/') + 1)), json_decode($json, true)]; }

require dirname(__DIR__) . '/OCPPHubSplitter/module.php';
require dirname(__DIR__) . '/OCPPHubLadepunkt/module.php';
require dirname(__DIR__) . '/OCPPHubAbrechnung/module.php';
require dirname(__DIR__) . '/OCPPHubKonfigurator/module.php';

// ---- Testdoppel -------------------------------------------------------------------
// Ladepunkt: Handler aufzeichnen statt Variablen zu pflegen (die Nachrichtenwege bleiben echt)
class TLadepunkt extends OCPPHubLadepunkt
{
    public array $rec = [];
    public array $zustand = ['state' => 0, 'deaktiviert' => false, 'extern' => false, 'idTag' => '', 'zaehler' => 0, 'reserviert' => '', 'fahrzeug' => ''];
    private function r(string $n, ...$a): void { $this->rec[] = [$n, ...$a]; }
    public function UpdateBootInfo(string $vendor, string $model, string $serial): void { $this->r('Boot', $vendor, $model, $serial); }
    public function UpdateStatus(string $ocppStatus, string $errorCode): void { $this->r('Status', $ocppStatus, $errorCode); }
    public function UpdateMeterValues(?float $powerW, ?float $energyWh, ?float $socPercent = null): void { $this->r('Messwerte', $powerW, $energyWh, $socPercent); }
    public function StartTransaction(int $transactionId, string $idTag, int $meterStartWh): void { $this->r('Start', $transactionId, $idTag, $meterStartWh); }
    public function StopTransaction(int $transactionId, int $meterStopWh): void { $this->r('Stopp', $transactionId, $meterStopWh); }
    public function SetSourceIP(string $IP): void { $this->r('Ip', $IP); }
    public function UpdateLastSeen(): void { $this->r('Lebenszeichen'); }
    public function SetStationCapabilities(int $MaxCurrentA, int $MinCurrentA, int $PhaseSwitch): void { $this->r('Kapazitaeten', $MaxCurrentA, $MinCurrentA, $PhaseSwitch); }
    public function ReportBlockedStart(string $Reason): void { $this->r('Blockiert', $Reason); }
    public function DiagnoseBlockReason(): void { $this->r('Diagnose'); }
    public function ClearBlockReason(): void { $this->r('GrundLoeschen'); }
    public function SetVehicleName(string $name, bool $TimeCorrelated): void { $this->r('Fahrzeug', $name, $TimeCorrelated); }
    public function SetVehicleTessieId(int $TessieInstanceId): void { $this->r('Tessie', $TessieInstanceId); }
    public function ConfirmAutoStart(): void { $this->r('AutoStart'); }
    public function GetState(): int { return $this->zustand['state']; }
    public function IsDeactivated(): bool { return $this->zustand['deaktiviert']; }
    public function GetLastIdTag(): string { return $this->zustand['idTag']; }
    public function GetMeterStartWh(): int { return $this->zustand['zaehler']; }
    public function GetActiveReservationIdTag(): string { return $this->zustand['reserviert']; }
    public function GetVehicleName(): string { return $this->zustand['fahrzeug']; }
    public function GetContractEntry(): array { return ['instanceID' => $this->InstanceID, 'externallyManaged' => $this->zustand['extern'], 'lastSeenAt' => 12345, 'managedBy' => 'none']; }
}

// Abrechnung: Antworten festlegen
class TAbrechnung extends OCPPHubAbrechnung
{
    public array $rec = [];
    public array $antwort = ['status' => 'Accepted'];
    public string $idTag = 'KARTE-1';
    public function CheckAuthorization(string $idTag): array { $this->rec[] = ['Autorisierung', $idTag]; return $this->antwort; }
    public function RecordConsumption(string $idTag, float $kWh, int $timestamp): void { $this->rec[] = ['Verbrauch', $idTag, $kWh]; }
    public function FindIdTagForVehicleName(string $VehicleName): string { $this->rec[] = ['IdTagZuFahrzeug', $VehicleName]; return $this->idTag; }
}

// Splitter, der Kind-Befehle aufzeichnet
class TSplitterRec extends OCPPHubSplitter
{
    public array $rec = [];
    public bool $demo = false;
    public function ManualStart(string $cpid): void { $this->rec[] = ['ManuellStart', $cpid]; }
    public function RemoteStop(string $cpid, int $transactionId): void { $this->rec[] = ['Stopp', $cpid, $transactionId]; }
    public function SetCurrentLimit(string $cpid, float $ampere): void { $this->rec[] = ['Stromlimit', $cpid, $ampere]; }
    public function AutoAuthorizeVehicle(string $cpid, string $vehicleName): void { $this->rec[] = ['AutoFreigabe', $cpid, $vehicleName]; }
    public function ClearGoeForceLock(string $cpid): void { $this->rec[] = ['ForceLockLoesen', $cpid]; }
    public function ReserveNow(string $cpid, string $idTag, string $expiryIso): int { $this->rec[] = ['Reservieren', $cpid, $idTag, $expiryIso]; return 77; }
    public function CancelReservation(string $cpid, int $reservationId): void { $this->rec[] = ['ReservierungAufheben', $cpid, $reservationId]; }
    public function IsDemoMode(): bool { return $this->demo; }
    public function GetSeenChargePoints(): array { return ['WB7' => 1700000000]; }
}

// ---- Rahmen -----------------------------------------------------------------------
$fails = [];
$gruen = 0;
function pruefe(string $text, bool $ok): void
{
    global $fails, $gruen;
    if ($ok) {
        $gruen++;
    } else {
        $fails[] = $text;
    }
}
function privat(object $o, string $methode, ...$args)
{
    $m = new ReflectionMethod($o, $methode);
    return $m->invoke($o, ...$args);
}
function konstante(string $klasse, string $name): string
{
    return (new ReflectionClassConstant($klasse, $name))->getValue();
}
/** Letzte vom Splitter an die Wallbox gesendete Antwort (CALLRESULT) für eine uniqueId */
function antwortFrame(string $uid): ?array
{
    foreach (array_reverse(Bus::$gesendet) as [$cpid, $f]) {
        if (($f[0] ?? null) === 3 && ($f[1] ?? null) === $uid) {
            return $f;
        }
    }
    return null;
}
function frames(string $aktion): array
{
    return array_values(array_filter(array_column(Bus::$gesendet, 1), fn ($f) => ($f[0] ?? null) === 2 && ($f[2] ?? null) === $aktion));
}

/** Aufbau: Splitter 100 mit zwei Ladepunkten (WB1, WB2) und der Abrechnung 300 als Kinder */
function aufbau(int $betriebsart = 1): array
{
    Bus::reset();
    $_SERVER['REMOTE_ADDR'] = '192.168.2.50';
    $sp = Bus::add(100, new TSplitterRec(0), G_SPLITTER);
    $sp->props = ['Active' => true, 'Betriebsart' => $betriebsart];
    $sp->attrs['AbrechnungID'] = 300;
    $l1 = Bus::add(201, new TLadepunkt(0), G_LADEPUNKT, 100);
    $l1->props = ['CPID' => 'WB1'];
    $l2 = Bus::add(202, new TLadepunkt(0), G_LADEPUNKT, 100);
    $l2->props = ['CPID' => 'WB2'];
    $ab = Bus::add(300, new TAbrechnung(0), G_ABRECHNUNG, 100);
    Bus::add(301, new TAbrechnung(0), G_ABRECHNUNG, 100);   // zweite, vom Splitter nicht geführte Abrechnung
    // Splitter für Tests der Splitter-Seite ohne aufgezeichnete Kind-Befehle: eigene Instanz, gleiche Kinder
    $echt = new OCPPHubSplitter(0);
    $echt->props = $sp->props;
    $echt->attrs = $sp->attrs;
    Bus::add(101, $echt, G_SPLITTER);
    Bus::$conn[201] = Bus::$conn[202] = Bus::$conn[300] = Bus::$conn[301] = 101;   // die Kinder hängen am echten Splitter
    return [$echt, $sp, $l1, $l2, $ab];
}

// ---- 1) Verdrahtung in module.json und Konstanten ---------------------------------------
$mj = fn (string $m) => json_decode(file_get_contents(dirname(__DIR__) . "/OCPPHub$m/module.json"), true);
pruefe('Splitter: implemented enthält die Kind->Splitter-Schnittstelle', in_array(ZS, $mj('Splitter')['implemented'], true));
pruefe('Splitter: childRequirements enthält die Splitter->Kind-Schnittstelle', in_array(ZK, $mj('Splitter')['childRequirements'], true));
pruefe('Ladepunkt: parentRequirements = Kind->Splitter, implemented = Splitter->Kind', $mj('Ladepunkt')['parentRequirements'] === [ZS] && in_array(ZK, $mj('Ladepunkt')['implemented'], true));
pruefe('Abrechnung: hängt als Gerät am Splitter (parentRequirements) und nimmt Splitter-Nachrichten an', $mj('Abrechnung')['parentRequirements'] === [ZS] && in_array(ZK, $mj('Abrechnung')['implemented'], true));
pruefe('Konfigurator: parentRequirements = Kind->Splitter und erfüllt die childRequirements des Splitters', $mj('Konfigurator')['parentRequirements'] === [ZS] && in_array(ZK, $mj('Konfigurator')['implemented'], true));
pruefe('Konstante im Splitter stimmt mit module.json überein', konstante('OCPPHubSplitter', 'DATA_ZUM_KIND') === ZK);
foreach (['Ladepunkt', 'Abrechnung', 'Konfigurator'] as $m) {
    pruefe("Konstante im $m stimmt mit module.json überein", konstante("OCPPHub$m", 'DATA_ZUM_SPLITTER') === ZS);
}
pruefe('Typ der Module: Splitter 2, Ladepunkt/Abrechnung 3, Konfigurator 5', [$mj('Splitter')['type'], $mj('Ladepunkt')['type'], $mj('Abrechnung')['type'], $mj('Konfigurator')['type']] === [2, 3, 3, 5]);

// ---- 2) Splitter -> Kinder: OCPP-Nachrichten werden zu Datenfluss-Nachrichten -------------
[$sp, , $l1, $l2, $ab] = aufbau();
privat($sp, 'handleCall', 'WB2', [2, 'u1', 'MeterValues', ['meterValue' => [['sampledValue' => [
    ['measurand' => 'Power.Active.Import', 'value' => '7.4', 'unit' => 'kW'],
    ['measurand' => 'Energy.Active.Import.Register', 'value' => '12.5', 'unit' => 'kWh'],
    ['measurand' => 'SoC', 'value' => '55'],
]]]]]);
pruefe('MeterValues: nur der Ladepunkt WB2 bekommt die Messwerte', $l1->rec === [] || !in_array('Messwerte', array_column($l1->rec, 0), true));
pruefe('MeterValues: kW und kWh werden in W und Wh umgerechnet', in_array(['Messwerte', 7400.0, 12500.0, 55.0], $l2->rec, true));
pruefe('MeterValues: Wallbox bekommt die Quittung (leeres Objekt)', antwortFrame('u1') !== null);

[$sp, , $l1, $l2, $ab] = aufbau();
privat($sp, 'handleCall', 'WB1', [2, 'u2', 'StatusNotification', ['status' => 'Charging', 'errorCode' => 'NoError']]);
pruefe('StatusNotification kommt als Status beim Ladepunkt WB1 an', in_array(['Status', 'Charging', 'NoError'], $l1->rec, true) && !in_array('Status', array_column($l2->rec, 0), true));
privat($sp, 'handleCall', 'WB2', [2, 'u3', 'BootNotification', ['chargePointVendor' => 'go-e', 'chargePointModel' => 'Gemini', 'chargePointSerialNumber' => '050308']]);
pruefe('BootNotification: Hersteller, Modell, Seriennummer kommen an', in_array(['Boot', 'go-e', 'Gemini', '050308'], $l2->rec, true));
pruefe('BootNotification: Wallbox bekommt Accepted', (antwortFrame('u3')[2]['status'] ?? '') === 'Accepted');
pruefe('BootNotification: danach werden Messintervall und Stromgrenzen abgefragt', count(frames('ChangeConfiguration')) === 2 && count(frames('GetConfiguration')) === 1);

// Lebenszeichen + Quell-IP in EINER Nachricht, bekannte Wallbox wird nicht als „gesehen“ gemerkt
[$sp, , $l1, $l2] = aufbau();
privat($sp, 'rememberSeenChargePoint', 'WB1');
pruefe('Lebenszeichen: Ladepunkt bekommt Quell-IP und Lebenszeichen', in_array(['Ip', '192.168.2.50'], $l1->rec, true) && in_array(['Lebenszeichen'], $l1->rec, true));
pruefe('Bekannte Wallbox taucht nicht in der Liste der gesehenen auf', ($sp->GetSeenChargePoints()['WB1'] ?? null) === null);
privat($sp, 'rememberSeenChargePoint', 'WB9');
pruefe('Unbekannte Wallbox (kein Ladepunkt) wird für den Konfigurator gemerkt', isset($sp->GetSeenChargePoints()['WB9']));

// Start/Stopp/Abrechnung
[$sp, , $l1, $l2, $ab] = aufbau(1);
$l2->zustand['zaehler'] = 1000;
$l2->zustand['idTag'] = 'KARTE-1';
privat($sp, 'handleCall', 'WB2', [2, 'u4', 'StartTransaction', ['idTag' => 'KARTE-1', 'meterStart' => 1000]]);
$start = antwortFrame('u4')[2] ?? [];
pruefe('StartTransaction (Betriebsart ①): Accepted und eine Transaktions-ID', ($start['idTagInfo']['status'] ?? '') === 'Accepted' && isset($start['transactionId']));
pruefe('StartTransaction kommt mit ID, Karte und Zählerstand beim Ladepunkt an', in_array(['Start', $start['transactionId'], 'KARTE-1', 1000], $l2->rec, true));
privat($sp, 'handleCall', 'WB2', [2, 'u5', 'StopTransaction', ['transactionId' => $start['transactionId'], 'meterStop' => 6000]]);
pruefe('StopTransaction kommt beim Ladepunkt an', in_array(['Stopp', $start['transactionId'], 6000], $l2->rec, true));
pruefe('Betriebsart ①: kein Verbrauch an die Abrechnung', $ab->rec === []);

[$sp, , $l1, $l2, $ab] = aufbau(2);
$l2->zustand['zaehler'] = 1000;
$l2->zustand['idTag'] = 'KARTE-1';
privat($sp, 'handleCall', 'WB2', [2, 'u6', 'StopTransaction', ['transactionId' => 5, 'meterStop' => 6000]]);
pruefe('Betriebsart ②: Verbrauch (5 kWh) geht mit der Karte an die Abrechnung', in_array(['Verbrauch', 'KARTE-1', 5.0], $ab->rec, true));
pruefe('Die Abrechnung bekommt die Nachricht nur, wenn sie ausdrücklich gemeint ist (Ladepunkte antworten nicht)', count(array_filter($l1->rec, fn ($x) => $x[0] === 'Verbrauch')) === 0);
pruefe('Eine zweite, nicht geführte Abrechnung bekommt nichts (Splitter adressiert genau seine eigene)', Bus::$obj[301]->rec === []);

// Authorize
[$sp, , $l1, $l2, $ab] = aufbau(2);
$ab->antwort = ['status' => 'Blocked', 'reason' => 'limit-erreicht'];
privat($sp, 'handleCall', 'WB2', [2, 'u7', 'Authorize', ['idTag' => 'KARTE-1']]);
pruefe('Authorize ②: Abrechnung gefragt, Ergebnis „Blocked“ an die Wallbox', in_array(['Autorisierung', 'KARTE-1'], $ab->rec, true) && (antwortFrame('u7')[2]['idTagInfo']['status'] ?? '') === 'Blocked');
pruefe('Authorize ②: der Ladepunkt erfährt den Grund im Klartext', in_array(['Blockiert', 'Verbrauchslimit ist erreicht.'], $l2->rec, true));
[$sp, , $l1, $l2, $ab] = aufbau(2);
$ab->antwort = ['status' => 'Accepted', 'vehicleName' => 'Kohlekasten', 'vehicleTessieInstanceId' => 600];
privat($sp, 'handleCall', 'WB2', [2, 'u8', 'Authorize', ['idTag' => 'KARTE-1']]);
pruefe('Authorize ② angenommen: Fahrzeugname und Tessie-ID gehen an den Ladepunkt, Blockgrund wird gelöscht', in_array(['Fahrzeug', 'Kohlekasten', false], $l2->rec, true) && in_array(['Tessie', 600], $l2->rec, true) && in_array(['GrundLoeschen'], $l2->rec, true));
[$sp, , $l1, $l2, $ab] = aufbau(1);
$l2->zustand['reserviert'] = 'ANDERE';
privat($sp, 'handleCall', 'WB2', [2, 'u9', 'Authorize', ['idTag' => 'KARTE-1']]);
pruefe('Reservierung für eine andere Karte blockiert (Zustand kommt aus dem Ladepunkt)', (antwortFrame('u9')[2]['idTagInfo']['status'] ?? '') === 'Blocked' && in_array(['Blockiert', 'Für diesen Zeitraum ist die Wallbox reserviert.'], $l2->rec, true));
[$sp, , $l1, $l2, $ab] = aufbau(1);
$l2->zustand['deaktiviert'] = true;
privat($sp, 'handleCall', 'WB2', [2, 'u10', 'Authorize', ['idTag' => 'KARTE-1']]);
pruefe('Deaktivierter Ladepunkt: Authorize wird blockiert', (antwortFrame('u10')[2]['idTagInfo']['status'] ?? '') === 'Blocked');
[$sp, , $l1, $l2, $ab] = aufbau(1);
$l2->zustand['extern'] = true;
$vorher = count(Bus::$gesendet);
$sp->RemoteStart('WB2', 'KARTE-1');
pruefe('Extern geregelter Ladepunkt: RemoteStart wird unterdrückt (Schreibsperre über den Datenfluss)', count(Bus::$gesendet) === $vorher);
$sp->RemoteStart('WB1', 'KARTE-1');
pruefe('Ladepunkt ohne Sperre: RemoteStart geht an die Wallbox', count(Bus::$gesendet) === $vorher + 1);
pruefe('Unbekannte Wallbox (kein Ladepunkt) wird nicht gesperrt', privat($sp, 'isWriteBlocked', 'WB-UNBEKANNT') === false);

// Stromgrenzen aus GetConfiguration
[$sp, , $l1, $l2] = aufbau();
privat($sp, 'forwardStationCapabilities', 'WB2', ['configurationKey' => [
    ['key' => 'Station-MaxCurrent', 'value' => '16'], ['key' => 'MinChargingCurrent', 'value' => '6'], ['key' => 'ConnectorSwitch3to1PhaseSupported', 'value' => 'true'],
]]);
pruefe('Stromgrenzen und Phasenumschaltung kommen beim richtigen Ladepunkt an', in_array(['Kapazitaeten', 16, 6, 1], $l2->rec, true) && !in_array('Kapazitaeten', array_column($l1->rec, 0), true));

// Vertrag / GetFunctions
[$sp, , $l1, $l2] = aufbau();
$funktionen = $sp->GetFunctions();
pruefe('GetFunctions sammelt die Vertragseinträge aller Ladepunkte über den Datenfluss', count($funktionen) === 2 && array_column($funktionen, 'instanceID') === [201, 202]);
$sp->props['Active'] = false;
pruefe('Inaktiver Splitter liefert keine Einträge', $sp->GetFunctions() === []);

// Ohne Ladepunkte darf nichts passieren
Bus::reset();
$leer = Bus::add(100, new OCPPHubSplitter(0), G_SPLITTER);
$leer->props = ['Active' => true, 'Betriebsart' => 1];
privat($leer, 'handleCall', 'WB1', [2, 'u11', 'MeterValues', ['meterValue' => []]]);
pruefe('Splitter ohne Kinder: Nachricht wird beantwortet, nichts bricht', antwortFrame('u11') !== null && $leer->GetFunctions() === []);

// ---- 3) Kinder -> Splitter: Befehle gehen als Nachricht an den Elternteil -------------------
Bus::reset();
$rec = Bus::add(100, new TSplitterRec(0), G_SPLITTER);
$lp = Bus::add(201, new OCPPHubLadepunkt(0), G_LADEPUNKT, 100);
$lp->props = ['CPID' => 'WB2', 'ManagedBy' => 'none', 'MinCurrent' => 6, 'MaxCurrent' => 16];
$lp->RequestAction('ctl_enable', true);
pruefe('Ladefreigabe an: Splitter bekommt „ManuellStart“ mit der Kennung', in_array(['ManuellStart', 'WB2'], $rec->rec, true));
$lp->attrs['LastTransactionId'] = 42;
$lp->RequestAction('ctl_enable', false);
pruefe('Ladefreigabe aus: Splitter bekommt „Stopp“ mit der Transaktions-ID', in_array(['Stopp', 'WB2', 42], $rec->rec, true));
pruefe('Manueller Stopp wird im Ladepunkt gemerkt', $lp->attrs['ManualStopActive'] === true);
$lp->RequestAction('ctl_curr_limit', 12);
pruefe('Stromlimit: Splitter bekommt den auf die Grenzen begrenzten Wert', in_array(['Stromlimit', 'WB2', 12.0], $rec->rec, true));
$lp->RequestAction('ctl_curr_limit', 99);
pruefe('Stromlimit über Maximum wird auf das Maximum begrenzt', in_array(['Stromlimit', 'WB2', 16.0], $rec->rec, true));

$rec->rec = [];
$rec->demo = true;
$lp->RequestAction('ctl_enable', true);
pruefe('Vorführmodus: der Splitter wird gefragt, es geht kein Steuerbefehl raus', $rec->rec === []);
$rec->demo = false;

$lp->props['ManagedBy'] = 'other';
$lp->RequestAction('ctl_enable', true);
pruefe('Extern geregelt: der Ladepunkt sendet selbst nichts', $rec->rec === []);
$lp->props['ManagedBy'] = 'none';

// Bedienpanel im Formular (analog ChargerHub): gleicher Weg wie die Steuervariablen, plus Rückmeldung
$lp->werte['block_reason'] = '';
$rec->rec = [];
Bus::$formfelder = [];
$feld = function (string $name, string $eig = 'caption') {
    foreach (array_reverse(Bus::$formfelder) as $f) {
        if ($f[0] === $name && $f[1] === $eig) {
            return $f[2];
        }
    }
    return null;
};
$lp->RequestAction('FormCtlEnable', true);
pruefe('Bedienpanel Ladefreigabe: Splitter bekommt „ManuellStart“, Rückmeldung ✅', in_array(['ManuellStart', 'WB2'], $rec->rec, true) && str_contains((string)$feld('ControlPanelFeedback'), '✅ Ladefreigabe: An'));
$lp->RequestAction('FormCtlCurrent', 99);
pruefe('Bedienpanel Stromlimit über Maximum: wird begrenzt und als gesetzt gemeldet', in_array(['Stromlimit', 'WB2', 16.0], $rec->rec, true) && str_contains((string)$feld('ControlPanelFeedback'), '✅ Stromlimit: 16 A'));
$lp->props['ManagedBy'] = 'other';
$rec->rec = [];
$lp->RequestAction('FormCtlEnable', false);
pruefe('Bedienpanel bei fremdem Regler: nichts gesendet, Rückmeldung ⚠️, Schalter springt zurück', $rec->rec === [] && str_contains((string)$feld('ControlPanelFeedback'), 'nicht ausgeführt') && $feld('FormCtlEnable', 'value') === true);
pruefe('Bedienpanel nennt den Regler im Hinweis', str_contains((string)$feld('ControlPanelHint'), '⛔ Gerade regelt'));
$lp->props['ManagedBy'] = 'none';
$rec->demo = true;
$rec->rec = [];
$lp->RequestAction('FormCtlEnable', false);
pruefe('Bedienpanel im Vorführmodus: nichts gesendet, Rückmeldung ⚠️', $rec->rec === [] && str_contains((string)$feld('ControlPanelFeedback'), 'nicht ausgeführt'));
$rec->demo = false;
$panel = privat($lp, 'controlPanel');
pruefe('Bedienpanel hat Schalter und Regler mit Grenzen aus den Einstellungen', ($panel['items'][2]['name'] ?? '') === 'FormCtlEnable' && ($panel['items'][4]['maximum'] ?? 0) === 16 && ($panel['items'][4]['minimum'] ?? 0) === 6);

pruefe('Reservieren: Splitter bekommt Karte und Zeitpunkt, die Reservierungs-ID kommt zurück', $lp->Reserve('KARTE-1', date('c', time() + 3600)) === true && $lp->attrs['ReservationId'] === 77 && in_array('Reservieren', array_column($rec->rec, 0), true));
$lp->CancelReservation();
pruefe('Reservierung aufheben: Splitter bekommt die Reservierungs-ID', in_array(['ReservierungAufheben', 'WB2', 77], $rec->rec, true));
$lp->SetActive(false);
pruefe('Ladepunkt abschalten: Splitter löst ein go-e-Force-Lock', in_array(['ForceLockLoesen', 'WB2'], $rec->rec, true));

// Ohne Elternteil
Bus::reset();
$allein = Bus::add(201, new OCPPHubLadepunkt(0), G_LADEPUNKT, 0);
$allein->props = ['CPID' => 'WB2', 'ManagedBy' => 'none', 'MinCurrent' => 6, 'MaxCurrent' => 16];
$allein->RequestAction('ctl_enable', true);
pruefe('Ohne Splitter: Ladefreigabe bricht nicht ab', true);
pruefe('Ohne Splitter: Reservieren liefert false statt eines Fehlers', $allein->Reserve('K', date('c', time() + 3600)) === false);

// ---- 4) Abrechnung und Konfigurator ---------------------------------------------------------
Bus::reset();
$sp = Bus::add(100, new TSplitterRec(0), G_SPLITTER);
$sp->attrs['AbrechnungID'] = 300;
$ab = Bus::add(300, new OCPPHubAbrechnung(0), G_ABRECHNUNG, 100);
$zweite = Bus::add(301, new OCPPHubAbrechnung(0), G_ABRECHNUNG, 100);
pruefe('Abrechnung: der Splitter bestätigt, dass er SIE als seine Abrechnung führt', privat($ab, 'isRegisteredWithSplitter') === true);
pruefe('Eine zweite, vom Splitter nicht geführte Abrechnung erkennt das (wirkungslose Eingaben)', privat($zweite, 'isRegisteredWithSplitter') === false);
$sp->demo = true;
pruefe('Vorführmodus: nur die geführte Abrechnung ist im Vorführmodus', privat($ab, 'isDemoMode') === true && privat($zweite, 'isDemoMode') === false);
$sp->demo = false;
pruefe('Abrechnung ohne Splitter: nicht gebunden, kein Fehler', (function () {
    $a = Bus::add(400, new OCPPHubAbrechnung(0), G_ABRECHNUNG, 0);
    return privat($a, 'isRegisteredWithSplitter') === false && privat($a, 'isDemoMode') === false;
})());

$k = Bus::add(500, new OCPPHubKonfigurator(0), G_KONFIGURATOR, 100);
pruefe('Konfigurator: gesehene Wallboxen kommen vom Splitter über den Datenfluss', privat($k, 'geseheneWallboxen') === ['WB7' => 1700000000]);
$k2 = Bus::add(501, new OCPPHubKonfigurator(0), G_KONFIGURATOR, 0);
pruefe('Konfigurator ohne Splitter: leere Liste statt Fehler', privat($k2, 'geseheneWallboxen') === []);

// Splitter-Anfragen: unbekannte Aktion, kaputtes JSON
$r = json_decode($sp->ForwardData('kein json'), true);
pruefe('ForwardData: kaputtes JSON wird sauber abgelehnt', ($r['ok'] ?? null) === false);
$r = json_decode($sp->ForwardData(json_encode(['Aktion' => 'GibtEsNicht'])), true);
pruefe('ForwardData: unbekannte Aktion wird sauber abgelehnt', ($r['ok'] ?? null) === false);

// ---- 5) Migration bestehender Instanzen (Verbindung herstellen) ------------------------------------
$verbinde = function (array $instanzen, int $zielId, string $klasse, string $guid, array $props = [], int $conn = 0) {
    Bus::reset();
    foreach ($instanzen as $id => $g) {
        Bus::add($id, new OCPPHubSplitter(0), $g);
    }
    $o = Bus::add($zielId, new $klasse(0), $guid, $conn);
    $o->props = $props;
    $o->VerbindungPruefen();
    return Bus::$conn[$zielId];
};
pruefe('Migration Ladepunkt: Property SplitterID wird zur echten Verbindung', $verbinde([100 => G_SPLITTER, 101 => G_SPLITTER], 201, 'OCPPHubLadepunkt', G_LADEPUNKT, ['SplitterID' => 101]) === 101);
pruefe('Migration Ladepunkt: genau ein Splitter im System genügt', $verbinde([100 => G_SPLITTER], 201, 'OCPPHubLadepunkt', G_LADEPUNKT) === 100);
pruefe('Migration Ladepunkt: bei zwei Splittern ohne Hinweis wird NICHT geraten', $verbinde([100 => G_SPLITTER, 101 => G_SPLITTER], 201, 'OCPPHubLadepunkt', G_LADEPUNKT) === 0);
pruefe('Migration Ladepunkt: bereits verbundene Instanz bleibt unberührt', $verbinde([100 => G_SPLITTER, 101 => G_SPLITTER], 201, 'OCPPHubLadepunkt', G_LADEPUNKT, ['SplitterID' => 101], 100) === 100);
pruefe('Migration Ladepunkt: SplitterID zeigt auf eine Nicht-Splitter-Instanz -> nicht verbinden', $verbinde([100 => G_LADEPUNKT, 101 => G_SPLITTER, 102 => G_SPLITTER], 201, 'OCPPHubLadepunkt', G_LADEPUNKT, ['SplitterID' => 100]) === 0);
pruefe('Migration Konfigurator: Property SplitterID wird zur echten Verbindung', $verbinde([100 => G_SPLITTER, 101 => G_SPLITTER], 500, 'OCPPHubKonfigurator', G_KONFIGURATOR, ['SplitterID' => 100]) === 100);
pruefe('Migration Konfigurator: bei zwei Splittern ohne Hinweis wird NICHT geraten', $verbinde([100 => G_SPLITTER, 101 => G_SPLITTER], 500, 'OCPPHubKonfigurator', G_KONFIGURATOR) === 0);

Bus::reset();
$sp = Bus::add(100, new OCPPHubSplitter(0), G_SPLITTER);
Bus::add(300, new OCPPHubAbrechnung(0), G_ABRECHNUNG, 0);
$sp->attrs['AbrechnungID'] = 300;
privat($sp, 'ensureAbrechnung');
pruefe('Migration Abrechnung: der Splitter verbindet seine Bestands-Abrechnung beim Übernehmen', Bus::$conn[300] === 100);
Bus::add(301, new OCPPHubAbrechnung(0), G_ABRECHNUNG, 55);
$sp->attrs['AbrechnungID'] = 301;
privat($sp, 'ensureAbrechnung');
pruefe('Migration Abrechnung: eine anderswo verbundene Abrechnung bleibt unberührt', Bus::$conn[301] === 55);

// ---- 6) Keine direkten Funktionsaufrufe zwischen den Modulen mehr -------------------------------------
$quelltext = '';
foreach (['Splitter', 'Ladepunkt', 'Abrechnung', 'Konfigurator'] as $m) {
    $code = file_get_contents(dirname(__DIR__) . "/OCPPHub$m/module.php");
    // Kommentare und Zeichenketten entfernen, damit Hinweise im Text nicht zählen
    $teile = '';
    foreach (token_get_all($code) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        $teile .= is_array($t) ? $t[1] : $t;
    }
    foreach (['OHUB', 'OHUBL', 'OHUBA', 'OHUBK'] as $praefix) {
        pruefe("$m ruft keine {$praefix}_-Funktion einer anderen Instanz auf", preg_match('/\b' . $praefix . '_[A-Za-z]+\s*\(/', $teile) !== 1);
    }
    pruefe("$m sucht keine eigenen Kinder/Eltern über die Instanzliste", !preg_match('/IPS_GetInstanceListByModuleID\(\s*self::(LADEPUNKT|ABRECHNUNG|SPLITTER)_GUID/', $teile) || $m === 'Konfigurator' || $m === 'Ladepunkt');
}

// ---- Ergebnis ---------------------------------------------------------------------------------------------
if ($fails === []) {
    echo "✅ Datenfluss: $gruen Prüfungen grün\n";
    exit(0);
}
echo "❌ Datenfluss: " . count($fails) . " von " . ($gruen + count($fails)) . " Prüfungen rot\n";
foreach ($fails as $f) {
    echo "   - $f\n";
}
exit(1);
