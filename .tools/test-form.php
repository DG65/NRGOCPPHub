<?php
// Prüfstand für die Formular-Konventionen aus SUITE.md (21.09.2026):
//  - „Verbund-Verbindungen im Formular sichtbar machen": jede Verbindung hat eine
//    live berechnete Statuszeile, die im AUSGELIEFERTEN JSON steht (auch in
//    verschachtelten Panels).
//  - „Wert kommt automatisch: Eingabefeld ersetzen": liefert eine Automatik einen
//    Wert und das Feld ist leer, steht eine 🔗-Zeile da und das Auswahlfeld nur in
//    einem eingeklappten Panel; eigene Auswahl ✏️ mit sichtbarem Feld; nichts
//    automatisch ℹ️ mit sichtbarem Feld.
// Aufruf:  php .tools/test-form.php        (0 = alles grün)

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$scenario = $argv[1] ?? null;
$all = [
    'k_auto', 'k_falsch', 'k_keins', 'k_mehrere',
    'l_auto', 'l_eigen', 'l_keins',
    's_ok', 's_leer',
    'a_ok', 'a_verwaist', 'a_tessie_frei',
    'l_aufbau_std', 'l_aufbau_eigen', 'l_aufbau_unbekannt', 'l_aufbau_reset',
    'a_aufbau_std', 'a_aufbau_eigen', 'a_aufbau_reset',
];

if ($scenario === null) {
    $fails = 0;
    foreach ($all as $sc) {
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . $sc, $rc);
        $fails += $rc === 0 ? 0 : 1;
    }
    echo $fails === 0 ? "\nAlle Prüfungen grün.\n" : "\n$fails Szenario(en) rot.\n";
    exit($fails === 0 ? 0 : 1);
}

const SPLITTER = '{81D3E328-9E12-43A9-825A-F7888530868C}';
const LADEPUNKT = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';
const ABRECHNUNG = '{64980198-6B36-45D5-A84F-A0EAE9CCC63A}';
const KONFIGURATOR = '{FABC8306-1A0A-4BE4-9C3A-948ECFD28760}';
const METERHUB = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
const TESSIE = '{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}';
const EMS = '{90286A25-E6C9-4A66-BD4E-0CFB707C2C6C}';
const KR_READY = 10103;
const KL_MESSAGE = 10201;
const KL_WARNING = 10204;

// ---- Nachgebildetes IPS ------------------------------------------------------
$props = [];
$attrs = [];
$modules = [];   // id => ['guid' =>, 'name' =>, 'parent' =>, 'props' => []]
$values = [];    // id => Wert
$mhub = null;    // Rückgabe von MHUB_GetFunctions (JSON) oder null = nicht installiert
$self = 900;

class IPSModule
{
    public $InstanceID = 900;
    public function __construct() {}
    public function SendDebug(...$a) {}
    public function ReadPropertyInteger($n) { return (int) ($GLOBALS['props'][$n] ?? 0); }
    public function ReadPropertyString($n) { return (string) ($GLOBALS['props'][$n] ?? ''); }
    public function ReadPropertyBoolean($n) { return (bool) ($GLOBALS['props'][$n] ?? false); }
    public function ReadPropertyFloat($n) { return (float) ($GLOBALS['props'][$n] ?? 0.0); }
    public function ReadAttributeString($n) { return (string) ($GLOBALS['attrs'][$n] ?? ''); }
    public function ReadAttributeInteger($n) { return (int) ($GLOBALS['attrs'][$n] ?? 0); }
    public function ReadAttributeBoolean($n) { return (bool) ($GLOBALS['attrs'][$n] ?? false); }
    public function GetValue($ident) { return ''; }
    public function UpdateFormField($f, $p, $v) { $GLOBALS['updates'][] = [$f, $p, $v]; }
    // Datenfluss: Kinder (Objekte mit ReceiveData) und Eltern-Antwort (callable) legt das Szenario fest.
    public function SendDataToChildren($json) { return array_map(fn ($k) => $k->ReceiveData($json), $GLOBALS['kinder']); }
    public function SendDataToParent($json) { return ($GLOBALS['elternHandler'])($json); }
    public function LogMessage($t, $l) {}
}
$kinder = [];
// Antworten des Splitters an seine Kinder (Datenfluss), soweit ein Szenario nichts Eigenes festlegt
$elternHandler = function ($json) {
    $a = json_decode($json, true);
    switch ($a['Aktion'] ?? '') {
        case 'AbrechnungGebunden':
            $splitter = $GLOBALS['modules'][100] ?? [];
            return json_encode(['ok' => true, 'daten' => ['gebunden' => (int)($a['InstanzID'] ?? 0) === (int)($splitter['abrechnung'] ?? -1)]]);
        case 'Vorfuehrmodus':
            return json_encode(['ok' => true, 'daten' => ['aktiv' => false]]);
        case 'Vertraege':
            return json_encode(['ok' => true, 'daten' => ['liste' => []]]);
    }
    return json_encode(['ok' => false]);
};
$updates = [];

function IPS_GetLibrary($g) { return ['Version' => '0.6.34']; }
function IPS_GetName($id) { return $GLOBALS['modules'][$id]['name'] ?? ('Objekt ' . $id); }
function IPS_GetParent($id) { return $GLOBALS['modules'][$id]['parent'] ?? 0; }
function IPS_InstanceExists($id) { return isset($GLOBALS['modules'][$id]); }
function IPS_GetInstance($id)
{
    return ['ModuleInfo' => ['ModuleID' => $GLOBALS['modules'][$id]['guid'] ?? '', 'LibraryID' => '{L}'], 'InstanceStatus' => 102,
        'ConnectionID' => $GLOBALS['modules'][$id]['conn'] ?? 0];
}
function IPS_GetInstanceListByModuleID($g)
{
    $out = [];
    foreach ($GLOBALS['modules'] as $id => $m) {
        if ($m['guid'] === $g) {
            $out[] = $id;
        }
    }
    return $out;
}
function IPS_GetProperty($id, $n) { return $GLOBALS['modules'][$id]['props'][$n] ?? ''; }
function IPS_GetObjectIDByIdent($ident, $parent) { return 0; }
function IPS_GetKernelRunlevel() { return KR_READY; }
function GetValue($id) { return $GLOBALS['values'][$id] ?? 0; }
function OHUB_GetAbrechnungID($splitterId) { return $GLOBALS['modules'][$splitterId]['abrechnung'] ?? 0; }
function OHUB_IsDemoMode($id) { return false; }
function OHUB_GetSeenChargePoints($splitterId) { return $GLOBALS['modules'][$splitterId]['seen'] ?? []; }
function OHUBL_GetContractEntry($id) { return $GLOBALS['modules'][$id]['entry'] ?? []; }
function MHUB_GetFunctions($id)
{
    return $GLOBALS['mhub'];
}

function add(int $id, string $guid, string $name, int $parent = 0, array $extra = []): void
{
    $GLOBALS['modules'][$id] = array_merge(['guid' => $guid, 'name' => $name, 'parent' => $parent, 'props' => []], $extra);
}

$gridMeter = json_encode(['assignments' => [['function' => 'grid', 'latency' => 'realtime', 'powerID' => 7001, 'authority' => 'billing']]]);

// ---- Szenarien ---------------------------------------------------------------
$module = null;
$expect = [];   // Liste [Beschreibung, Bedingung-Callback ($json, $form)]
$now = time();

switch ($scenario) {
    case 'k_auto':
        $module = 'Konfigurator';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, KONFIGURATOR, 'Konfigurator', 100, ['conn' => 100]);
        add(36981, LADEPUNKT, 'WB2', 100, ['props' => ['CPID' => 'WB2'], 'conn' => 100]);
        $elternHandler = fn ($json) => json_encode(['ok' => true, 'daten' => ['WB2' => $now, 'WB3' => $now]]);
        break;
    case 'k_falsch':
        // Als Gateway ist eine Instanz gewählt, die kein OCPPHub-Splitter ist
        $module = 'Konfigurator';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(200, LADEPUNKT, 'Irgendwas', 0);
        add(900, KONFIGURATOR, 'Konfigurator', 0, ['conn' => 200]);
        break;
    case 'k_keins':
        $module = 'Konfigurator';
        add(900, KONFIGURATOR, 'Konfigurator', 0);
        break;
    case 'k_mehrere':
        $module = 'Konfigurator';
        add(100, SPLITTER, 'Splitter A', 0, ['props' => ['Active' => true]]);
        add(101, SPLITTER, 'Splitter B', 0, ['props' => ['Active' => true]]);
        add(900, KONFIGURATOR, 'Konfigurator', 0);
        break;
    case 'l_auto':
        $module = 'Ladepunkt';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, LADEPUNKT, 'WB2', 0, ['conn' => 100]);
        add(500, METERHUB, 'Netz-Zähler', 0);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'EnableSurplusCharging' => true, 'ManagedBy' => 'none'];
        $attrs += ['LastSeenAt' => $now - 5, 'StationMaxCurrentA' => 16, 'StationMinCurrentA' => 6, 'PhaseSwitchSupported' => 1];
        $mhub = $gridMeter;
        $values[7001] = -1234;
        break;
    case 'l_eigen':
        $module = 'Ladepunkt';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, LADEPUNKT, 'WB2', 0, ['conn' => 100]);
        add(500, METERHUB, 'Netz-Zähler', 0);
        add(501, METERHUB, 'Anderer Zähler', 0);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'EnableSurplusCharging' => true, 'ManagedBy' => 'none', 'SurplusMeterID' => 501];
        $mhub = $gridMeter;
        break;
    case 'l_keins':
        $module = 'Ladepunkt';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, LADEPUNKT, 'WB2', 0, ['conn' => 100]);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'EnableSurplusCharging' => true, 'ManagedBy' => 'none'];
        break;
    case 's_ok':
        $module = 'Splitter';
        add(900, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 901, 'seen' => ['WB2' => $now, 'WB9' => $now]]);
        add(901, ABRECHNUNG, 'OCPPHub Splitter Abrechnung', 900);
        $kinder[] = new class($now) {
            public function __construct(private int $now) {}
            public function ReceiveData($json)
            {
                $a = json_decode($json, true);
                return ($a['Aktion'] ?? '') === 'Vertrag'
                    ? json_encode(['ok' => true, 'daten' => ['cpid' => 'WB2', 'eintrag' => ['instanceID' => 36981, 'lastSeenAt' => $this->now - 4, 'deactivated' => false]]])
                    : '';
            }
        };
        $props += ['Active' => true, 'Betriebsart' => 2];
        $attrs += ['AbrechnungID' => 901];
        $attrs['SeenChargePoints'] = json_encode(['WB2' => $now, 'WB9' => $now]);
        break;
    case 's_leer':
        $module = 'Splitter';
        add(900, SPLITTER, 'OCPPHub Splitter', 0);
        $props += ['Active' => false, 'Betriebsart' => 1];
        break;
    case 'a_ok':
        $module = 'Abrechnung';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 900, 'props' => ['Betriebsart' => 2]]);
        add(900, ABRECHNUNG, 'Abrechnung', 100, ['conn' => 100]);
        add(600, TESSIE, 'Kohlekasten', 0);
        $props['Fahrzeuge'] = json_encode([['id' => 1, 'name' => 'Auto', 'kennzeichen' => '', 'tessieInstanceId' => 600]]);
        break;
    case 'a_verwaist':
        $module = 'Abrechnung';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 555]);
        add(900, ABRECHNUNG, 'Abrechnung', 0, ['conn' => 100]);
        $props['Fahrzeuge'] = '[]';
        break;
    // Vierter Tessie-Zustand (EMS-Rückfrage 28.09.2026): Tessie ist im System
    // installiert, aber KEIN Fahrzeug hier ist damit verknüpft — eigener,
    // dritter ℹ️-Text ("… aber kein Fahrzeug hier ist verknüpft"), nicht zu
    // verwechseln mit "gar kein Tessie im System" (a_verwaist deckt das ab).
    case 'a_tessie_frei':
        $module = 'Abrechnung';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 900, 'props' => ['Betriebsart' => 1]]);
        add(900, ABRECHNUNG, 'Abrechnung', 100, ['conn' => 100]);
        add(600, TESSIE, 'Kohlekasten', 0);
        $props['Fahrzeuge'] = json_encode([['id' => 1, 'name' => 'Auto', 'kennzeichen' => '', 'tessieInstanceId' => 0]]);
        break;
    // SUITE.md 3a „Formular: Aufbau“
    case 'l_aufbau_std':
    case 'l_aufbau_eigen':
    case 'l_aufbau_unbekannt':
    case 'l_aufbau_reset':
        $module = 'Ladepunkt';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, LADEPUNKT, 'WB2', 0, ['conn' => 100]);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'ManagedBy' => 'none'];
        if ($scenario === 'l_aufbau_eigen') {
            // PV zuerst und aufgeklappt, Stromgrenzen ausgeblendet, Doppelte Anbindung zugeklappt
            $props['FormLayout'] = json_encode([
                ['Key' => 'surplus', 'Panel' => 'x', 'Opened' => true, 'Closed' => false, 'Hidden' => false, 'Pos' => 1],
                ['Key' => 'limits', 'Panel' => 'x', 'Opened' => true, 'Closed' => true, 'Hidden' => true, 'Pos' => 2],
                ['Key' => 'duplicate', 'Panel' => 'x', 'Opened' => false, 'Closed' => true, 'Hidden' => false, 'Pos' => 3],
            ]);
        } elseif ($scenario === 'l_aufbau_unbekannt') {
            // alter/unbekannter Schlüssel, ein bekannter fehlt ganz, kaputtes JSON-Element dazwischen
            $props['FormLayout'] = json_encode([
                ['Key' => 'gibt_es_nicht', 'Hidden' => true, 'Pos' => 1],
                'kaputt',
                ['Key' => 'duplicate', 'Hidden' => true, 'Pos' => 99],
            ]);
        }
        break;
    case 'a_aufbau_std':
    case 'a_aufbau_eigen':
    case 'a_aufbau_reset':
        $module = 'Abrechnung';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 900, 'props' => ['Betriebsart' => 2]]);
        add(900, ABRECHNUNG, 'Abrechnung', 100, ['conn' => 100]);
        $props['Fahrzeuge'] = '[]';
        if ($scenario === 'a_aufbau_eigen') {
            $props['FormLayout'] = json_encode([
                ['Key' => 'Zugaenge', 'Panel' => 'x', 'Hidden' => false, 'Pos' => 1],
                ['Key' => 'Kunden', 'Panel' => 'x', 'Hidden' => true, 'Pos' => 2],
                ['Key' => 'Fahrzeuge', 'Panel' => 'x', 'Hidden' => false, 'Pos' => 3],
                ['Key' => 'Gruppen', 'Panel' => 'x', 'Hidden' => false, 'Pos' => 4],
            ]);
            $attrs['ActiveAccordionPanel'] = 'Kunden';   // aktiver Reiter ist ausgeblendet -> darf nicht nach vorn wandern
        }
        break;
}

require dirname(__DIR__) . '/OCPPHub' . $module . '/module.php';
$class = 'OCPPHub' . $module;
$m = new $class();
$json = $m->GetConfigurationForm();
$form = json_decode($json, true);

// ---- Hilfen ------------------------------------------------------------------
function find(array $nodes, callable $pred, array $trail = []): ?array
{
    foreach ($nodes as $n) {
        if (!is_array($n)) {
            continue;
        }
        if ($pred($n)) {
            return ['node' => $n, 'trail' => $trail];
        }
        foreach (['items', 'elements'] as $k) {
            if (isset($n[$k]) && is_array($n[$k])) {
                $hit = find($n[$k], $pred, array_merge($trail, [$n]));
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
    }
    return null;
}
// Reihenfolge der Panels (nach Caption-Präfix) auf der obersten Ebene von elements
function panelOrder(array $elements, array $prefixes): array
{
    $out = [];
    foreach ($elements as $n) {
        foreach ($prefixes as $p) {
            if (is_array($n) && str_starts_with($n['caption'] ?? '', $p)) {
                $out[] = $p;
            }
        }
    }
    return $out;
}

$fails = [];
$check = function (string $text, bool $ok) use (&$fails) {
    if (!$ok) {
        $fails[] = $text;
    }
};
// Text aus dem JSON holen, ohne Unicode-Escapes (json_encode ohne UNESCAPED_UNICODE)
function captions(array $nodes): string
{
    $out = '';
    foreach ($nodes as $n) {
        if (!is_array($n)) {
            continue;
        }
        if (isset($n['caption']) && is_string($n['caption'])) {
            $out .= $n['caption'] . "\n";
        }
        foreach (['items', 'elements'] as $k) {
            if (isset($n[$k]) && is_array($n[$k])) {
                $out .= captions($n[$k]);
            }
        }
    }
    return $out;
}
$flat = captions($form['elements']);
$has = fn (string $needle) => str_contains($flat, $needle);
$field = fn (string $name) => find($form['elements'], fn ($n) => ($n['name'] ?? '') === $name);
$topLevel = fn (string $name) => (function () use ($form, $name) {
    foreach ($form['elements'] as $n) {
        if (($n['name'] ?? '') === $name) {
            return true;
        }
    }
    return false;
})();
$inCollapsedPanel = function (string $name) use ($field) {
    $hit = $field($name);
    if ($hit === null || $hit['trail'] === []) {
        return false;
    }
    $parent = end($hit['trail']);
    return ($parent['type'] ?? '') === 'ExpansionPanel' && ($parent['expanded'] ?? true) === false;
};
$green = 0x2E8B3D;
$labelWith = fn (string $prefix) => find($form['elements'], fn ($n) => ($n['type'] ?? '') === 'Label' && str_starts_with($n['caption'] ?? '', $prefix));
$connectionsPanel = find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'ConnectionsPanel');

switch ($scenario) {
    case 'k_auto':
        $check('Statuszeile ✅ mit Zahlen fehlt', $has('✅ Verbunden mit Splitter #100') && $has('2 Wallbox(en) gemeldet') && $has('WB3'));
        $check('WB2 muss als schon angelegt gelten, WB3 als noch ohne Ladepunkt', $has('als Ladepunkt angelegt: WB2') && $has('noch ohne Ladepunkt: WB3'));
        $check('Das alte Auswahlfeld SplitterID darf es nicht mehr geben (Schnittstelle wählt Symcon selbst)', find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'SplitterID') === null);
        $check('alter statischer Satz ist noch da', !$has('Verbunden mit Splitter-Instanz #'));
        break;
    case 'k_falsch':
        $check('⛔ „kein Splitter“ fehlt', $has('⛔ Die gewählte Instanz #200 ist kein OCPPHub-Splitter'));
        break;
    case 'k_keins':
        $check('ℹ️ „kein Splitter im System“ fehlt', $has('Kein OCPPHub-Splitter im System gefunden'));
        break;
    case 'k_mehrere':
        $check('⚠️ mit Auswahlhinweis fehlt (nicht raten)', $has('⚠️ Kein Splitter zugeordnet, im System gibt es #100') && $has('#101'));
        break;
    case 'l_auto':
        $check('🔗-Zeile für den Netzzähler fehlt', $has('🔗 Netzzähler: MeterHub #500 „Netz-Zähler" (automatisch'));
        $check('🔗-Zeile ist nicht grün', ($labelWith('🔗 Netzzähler')['node']['color'] ?? null) === $green);
        $check('Netzzähler-Feld liegt nicht im eingeklappten Panel', $inCollapsedPanel('SurplusMeterID'));
        $check('Verbindungs-Panel fehlt', $connectionsPanel !== null);
        $check('✅ Wallbox-Zeile fehlt', $has('✅ Splitter #100') && $has('Wallbox „WB2"'));
        $check('gemeldete Stromgrenzen fehlen', $has('max. 16 A') && $has('min. 6 A') && $has('Phasenumschaltung unterstützt'));
        $check('✅ Netzzähler mit Wert fehlt', $has('✅ Netzzähler: MeterHub #500') && $has('-1234 W'));
        break;
    case 'l_eigen':
        $check('✏️-Zeile fehlt', $has('✏️ Netzzähler: MeterHub #501 „Anderer Zähler"'));
        $check('Feld muss sichtbar sein', find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'SurplusMeterID') !== null && !$inCollapsedPanel('SurplusMeterID'));
        break;
    case 'l_keins':
        $check('ℹ️-Zeile fehlt', $has('ℹ️ Netzzähler: keiner automatisch gefunden'));
        $check('Feld muss sichtbar sein', find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'SurplusMeterID') !== null && !$inCollapsedPanel('SurplusMeterID'));
        $check('⚠️ „MeterHub nicht installiert" fehlt', $has('⚠️ Kein MeterHub-Zähler') || $has('MeterHub ist nicht installiert'));
        break;
    case 's_ok':
        $check('Verbindungs-Panel fehlt', $connectionsPanel !== null);
        $check('✅ Abrechnung mit Betriebsart fehlt', $has('✅ Abrechnung #901') && $has('Betriebsart ②'));
        $check('✅ Ladepunkte fehlen', $has('✅ 1 Ladepunkt(e) zugeordnet: WB2 #36981 (verbunden'));
        $check('⚠️ Wallbox ohne Ladepunkt fehlt', $has('⚠️ Gemeldet, aber ohne Ladepunkt-Instanz: WB9'));
        break;
    case 's_leer':
        $check('⚠️ Splitter deaktiviert fehlt', $has('⚠️ Dieser Splitter ist deaktiviert'));
        $check('⚠️ keine Abrechnung fehlt', $has('⚠️ Keine Abrechnung-Instanz hinterlegt'));
        $check('ℹ️ keine Wallbox fehlt', $has('ℹ️ Noch keine Wallbox hat sich hier gemeldet'));
        break;
    case 'a_ok':
        $check('✅ Splitter-Bindung fehlt', $has('✅ Wird von Splitter #100') && $has('Betriebsart ②'));
        $check('✅ Tessie-Verknüpfung fehlt', $has('✅ Fahrzeugnamen live von Tessie übernommen') && $has('Tessie #600'));
        $check('ℹ️ Tarifquelle ehrlich fehlt', $has('ℹ️ Preis-/Tarifquelle: es gibt noch keine Kostenberechnung'));
        break;
    case 'a_verwaist':
        $check('⚠️ „kein Splitter verwendet diese Instanz" fehlt', $has('⚠️ Kein Splitter verwendet diese Instanz'));
        $check('ℹ️ kein Tessie fehlt', $has('ℹ️ Kein Tessie-Fahrzeug im System gefunden'));
        break;
    case 'a_tessie_frei':
        $check('ℹ️ „Tessie vorhanden, aber nichts verknüpft" fehlt', $has('ℹ️ Im System gibt es 1 Tessie-Fahrzeug(e), aber kein Fahrzeug hier ist verknüpft'));
        $check('darf NICHT mit „kein Tessie im System" verwechselt werden', !$has('Kein Tessie-Fahrzeug im System gefunden'));
        $check('darf NICHT als verknüpft (✅) gelten', !$has('✅ Fahrzeugnamen live von Tessie übernommen'));
        break;
    case 'l_aufbau_std':
    case 'l_aufbau_eigen':
    case 'l_aufbau_unbekannt':
        $order = panelOrder($form['elements'], ['⚡ Stromgrenzen', '🔀 Doppelte Anbindung', '☀️ PV-Überschussladen']);
        $lay = find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'LayoutPanel');
        $pan = fn (string $prefix) => find($form['elements'], fn ($n) => ($n['type'] ?? '') === 'ExpansionPanel' && str_starts_with($n['caption'] ?? '', $prefix))['node'] ?? [];
        $check('Aufbau-Panel fehlt', $lay !== null);
        $check('Aufbau-Panel muss eingeklappt sein', ($lay['node']['expanded'] ?? true) === false);
        // Reihenfolge der obersten Ebene: Aufbau-Panel nach den Fachpanels, vor Feedback und Über dieses Modul
        $names = array_map(fn ($n) => ($n['name'] ?? '') . '|' . ($n['caption'] ?? ''), $form['elements']);
        $iLay = array_search(true, array_map(fn ($x) => str_starts_with($x, 'LayoutPanel|'), $names), true);
        $iFeed = array_search(true, array_map(fn ($x) => str_starts_with($x, 'ReviewHint|'), $names), true);
        $iLic = array_search(true, array_map(fn ($x) => str_contains($x, 'Über dieses Modul'), $names), true);
        $check('Aufbau-Panel muss vor dem Forum-Hinweis stehen', $iLay !== false && $iFeed !== false && $iLay < $iFeed);
        $check('„Über dieses Modul“ muss ganz unten stehen', $iLic !== false && $iLic > $iFeed);
        $check('Feld FormLayout fehlt (Schlüsselspalte mit save)', (function () use ($lay) {
            foreach ($lay['node']['items'] ?? [] as $it) {
                if (($it['name'] ?? '') === 'FormLayout') {
                    foreach ($it['columns'] as $c) {
                        if (($c['name'] ?? '') === 'Key' && ($c['save'] ?? false) === true) { return true; }
                    }
                }
            }
            return false;
        })());
        if ($scenario === 'l_aufbau_std') {
            $check('Standardreihenfolge Stromgrenzen, Doppelte, PV', $order === ['⚡ Stromgrenzen', '🔀 Doppelte Anbindung', '☀️ PV-Überschussladen']);
            $check('im Standard darf nichts ausgeblendet sein', !isset($pan('⚡ Stromgrenzen')['visible']) && !isset($pan('☀️')['visible']));
            $check('im Standard darf nichts erzwungen aufgeklappt sein', !isset($pan('☀️')['expanded']));
            $check('Caption ohne Ausgeblendet-Zähler', str_ends_with($lay['node']['caption'], 'Aufbau'));
        } elseif ($scenario === 'l_aufbau_eigen') {
            $check('gewählte Reihenfolge PV, Stromgrenzen, Doppelte', $order === ['☀️ PV-Überschussladen', '⚡ Stromgrenzen', '🔀 Doppelte Anbindung']);
            $check('PV muss aufgeklappt sein', ($pan('☀️')['expanded'] ?? null) === true);
            $check('Stromgrenzen müssen visible:false haben, aber im Formular stehen bleiben', ($pan('⚡ Stromgrenzen')['visible'] ?? null) === false && find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'MinCurrent') !== null);
            $check('Widerspruch: ausgeblendet gewinnt vor zugeklappt und aufgeklappt', ($pan('⚡ Stromgrenzen')['visible'] ?? null) === false && ($pan('⚡ Stromgrenzen')['expanded'] ?? false) !== true);
            $check('Doppelte Anbindung muss zugeklappt sein', ($pan('🔀 Doppelte')['expanded'] ?? null) === false);
            $check('Caption zeigt „(1 ausgeblendet)“', str_contains($lay['node']['caption'], '(1 ausgeblendet)'));
            $check('Zeile mit den ausgeblendeten Bereichen fehlt', $has('🙈 Ausgeblendet: Stromgrenzen & Steuerungshoheit'));
        } else {
            $check('unbekannter Schlüssel darf nichts ändern; Doppelte steht wegen Position 99 hinten', $order === ['⚡ Stromgrenzen', '☀️ PV-Überschussladen', '🔀 Doppelte Anbindung']);
            $check('bekannter Schlüssel bleibt wirksam (Doppelte ausgeblendet)', ($pan('🔀 Doppelte')['visible'] ?? null) === false);
            $check('fehlender Schlüssel fällt auf Standard zurück (PV sichtbar)', !isset($pan('☀️')['visible']));
        }
        break;
    case 'l_aufbau_reset':
        $msg = $m->ResetFormLayout();
        $check('Reset muss Klartext zurückgeben', str_contains($msg, 'Übernehmen'));
        $check('Reset muss genau ein UpdateFormField auf FormLayout/values senden', count($updates) === 1 && $updates[0][0] === 'FormLayout' && $updates[0][1] === 'values');
        $rows = json_decode($updates[0][2] ?? '[]', true) ?: [];
        $check('Reset-Zeilen: vier Bereiche in Standardreihenfolge ohne Haken', array_map(fn ($r) => $r['Key'], $rows) === ['control', 'limits', 'duplicate', 'surplus'] && !array_filter($rows, fn ($r) => $r['Opened'] || $r['Closed'] || $r['Hidden']) && array_map(fn ($r) => $r['Pos'], $rows) === [1, 2, 3, 4]);
        break;
    case 'a_aufbau_std':
    case 'a_aufbau_eigen':
        $lay = find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'LayoutPanel');
        $row = find($form['elements'], fn ($n) => ($n['type'] ?? '') === 'RowLayout' && isset($n['items'][0]['name']) && str_starts_with($n['items'][0]['name'], 'Panel'));
        $tabs = array_map(fn ($n) => $n['name'], $row['node']['items'] ?? []);
        $vis = array_map(fn ($n) => $n['visible'] ?? true, $row['node']['items'] ?? []);
        $check('Aufbau-Panel fehlt', $lay !== null && ($lay['node']['expanded'] ?? true) === false);
        $check('Abrechnung hat keine Auf-/Zuklapp-Spalten (Ziehharmonika)', !str_contains(json_encode($lay['node'] ?? []), '"Opened"'));
        if ($scenario === 'a_aufbau_std') {
            $check('Standardreihenfolge der Reiter', $tabs === ['PanelFahrzeuge', 'PanelGruppen', 'PanelKunden', 'PanelZugaenge']);
            $check('alle Reiter sichtbar', !in_array(false, $vis, true));
        } else {
            $check('gewählte Reihenfolge Zugänge, Kunden, Fahrzeuge, Gruppen', $tabs === ['PanelZugaenge', 'PanelKunden', 'PanelFahrzeuge', 'PanelGruppen']);
            $check('Kunden muss visible:false haben und im Formular stehen bleiben', ($row['node']['items'][1]['visible'] ?? null) === false && find($form['elements'], fn ($n) => ($n['name'] ?? '') === 'Kunden') !== null);
            $check('ausgeblendeter aktiver Reiter wandert nicht ans Ende', end($tabs) !== 'PanelKunden');
        }
        break;
    case 'a_aufbau_reset':
        $msg = $m->ResetFormLayout();
        $check('Reset muss Klartext zurückgeben', str_contains($msg, 'Übernehmen'));
        $rows = json_decode($updates[0][2] ?? '[]', true) ?: [];
        $check('Reset-Zeilen: vier Reiter in Standardreihenfolge ohne Haken', array_map(fn ($r) => $r['Key'], $rows) === ['Fahrzeuge', 'Gruppen', 'Kunden', 'Zugaenge'] && !array_filter($rows, fn ($r) => $r['Hidden']));
        break;
}

if ($fails === []) {
    echo "✅ $scenario\n";
    exit(0);
}
echo "❌ $scenario\n";
foreach ($fails as $f) {
    echo "   - $f\n";
}
exit(1);
