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
    'k_auto', 'k_eigen', 'k_keins', 'k_mehrere',
    'l_auto', 'l_eigen', 'l_keins',
    's_ok', 's_leer',
    'a_ok', 'a_verwaist',
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
}

function IPS_GetLibrary($g) { return ['Version' => '0.6.34']; }
function IPS_GetName($id) { return $GLOBALS['modules'][$id]['name'] ?? ('Objekt ' . $id); }
function IPS_GetParent($id) { return $GLOBALS['modules'][$id]['parent'] ?? 0; }
function IPS_InstanceExists($id) { return isset($GLOBALS['modules'][$id]); }
function IPS_GetInstance($id)
{
    return ['ModuleInfo' => ['ModuleID' => $GLOBALS['modules'][$id]['guid'] ?? '', 'LibraryID' => '{L}'], 'InstanceStatus' => 102];
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
        add(900, KONFIGURATOR, 'Konfigurator', 100);
        add(36981, LADEPUNKT, 'WB2', 100, ['props' => ['CPID' => 'WB2', 'SplitterID' => 100]]);
        $modules[100]['seen'] = ['WB2' => $now, 'WB3' => $now];
        break;
    case 'k_eigen':
        $module = 'Konfigurator';
        add(100, SPLITTER, 'Splitter A', 0, ['props' => ['Active' => true]]);
        add(101, SPLITTER, 'Splitter B', 0, ['props' => ['Active' => true]]);
        add(900, KONFIGURATOR, 'Konfigurator', 0);
        $props['SplitterID'] = 101;
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
        add(900, LADEPUNKT, 'WB2', 0);
        add(500, METERHUB, 'Netz-Zähler', 0);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'EnableSurplusCharging' => true, 'ManagedBy' => 'none'];
        $attrs += ['LastSeenAt' => $now - 5, 'StationMaxCurrentA' => 16, 'StationMinCurrentA' => 6, 'PhaseSwitchSupported' => 1];
        $mhub = $gridMeter;
        $values[7001] = -1234;
        break;
    case 'l_eigen':
        $module = 'Ladepunkt';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, LADEPUNKT, 'WB2', 0);
        add(500, METERHUB, 'Netz-Zähler', 0);
        add(501, METERHUB, 'Anderer Zähler', 0);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'EnableSurplusCharging' => true, 'ManagedBy' => 'none', 'SurplusMeterID' => 501];
        $mhub = $gridMeter;
        break;
    case 'l_keins':
        $module = 'Ladepunkt';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['props' => ['Active' => true]]);
        add(900, LADEPUNKT, 'WB2', 0);
        $props += ['CPID' => 'WB2', 'SplitterID' => 100, 'EnableSurplusCharging' => true, 'ManagedBy' => 'none'];
        break;
    case 's_ok':
        $module = 'Splitter';
        add(900, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 901, 'seen' => ['WB2' => $now, 'WB9' => $now]]);
        add(901, ABRECHNUNG, 'OCPPHub Splitter Abrechnung', 900);
        add(36981, LADEPUNKT, 'WB2', 900, ['props' => ['CPID' => 'WB2', 'SplitterID' => 900], 'entry' => ['lastSeenAt' => $now - 4, 'deactivated' => false]]);
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
        add(900, ABRECHNUNG, 'Abrechnung', 100);
        add(600, TESSIE, 'Kohlekasten', 0);
        $props['Fahrzeuge'] = json_encode([['id' => 1, 'name' => 'Auto', 'kennzeichen' => '', 'tessieInstanceId' => 600]]);
        break;
    case 'a_verwaist':
        $module = 'Abrechnung';
        add(100, SPLITTER, 'OCPPHub Splitter', 0, ['abrechnung' => 555]);
        add(900, ABRECHNUNG, 'Abrechnung', 0);
        $props['Fahrzeuge'] = '[]';
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
        $check('🔗-Zeile für den automatischen Splitter fehlt', $has('🔗 Splitter: #100 „OCPPHub Splitter" (automatisch'));
        $check('🔗-Zeile ist nicht grün', ($labelWith('🔗 Splitter')['node']['color'] ?? null) === $green);
        $check('Auswahlfeld liegt nicht im eingeklappten Panel', $inCollapsedPanel('SplitterID'));
        $check('Auswahlfeld darf nicht ungeschützt oben stehen', !$topLevel('SplitterID'));
        $check('Statuszeile ✅ mit Zahlen fehlt', $has('✅ Verbunden mit Splitter #100') && $has('2 Wallbox(en) gemeldet') && $has('WB3'));
        $check('alter statischer Satz ist noch da', !$has('Verbunden mit Splitter-Instanz #'));
        break;
    case 'k_eigen':
        $check('✏️-Zeile fehlt', $has('✏️ Splitter: #101 „Splitter B"'));
        $check('✏️-Zeile darf nicht grün sein', !isset($labelWith('✏️ Splitter')['node']['color']));
        $check('Auswahlfeld muss sichtbar oben stehen', $topLevel('SplitterID'));
        $check('Statuszeile fehlt', $has('Verbunden mit Splitter #101'));
        break;
    case 'k_keins':
        $check('ℹ️-Zeile fehlt', $has('ℹ️ Splitter: nichts automatisch erkannt'));
        $check('ℹ️-Zeile darf nicht grün sein', !isset($labelWith('ℹ️ Splitter')['node']['color']));
        $check('Auswahlfeld muss sichtbar sein', $topLevel('SplitterID'));
        $check('ℹ️ „kein Splitter im System" fehlt', $has('Kein OCPPHub-Splitter im System gefunden'));
        break;
    case 'k_mehrere':
        $check('⚠️ mit Auswahlhinweis fehlt (nicht raten)', $has('⚠️ Kein Splitter zugeordnet, im System gibt es #100') && $has('#101'));
        $check('Auswahlfeld muss sichtbar sein', $topLevel('SplitterID'));
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
