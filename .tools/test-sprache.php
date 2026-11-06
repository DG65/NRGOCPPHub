<?php
// Prüfstand Zweisprachigkeit (SUITE.md Konvention 9). Aufruf: php .tools/test-sprache.php  (0 = alles grün)
//  1. Jeder Text, der im Code durch OHUB_Text::t() läuft (und jede Konstante mit Anzeigetexten), steht als
//     Schlüssel in libs/locale.json; Platzhalter (%s/%d) stimmen mit der deutschen Fassung überein.
//  2. Kein deutscher Text mehr im Code (Umlaute, typische deutsche Wörter in Zeichenketten außerhalb von
//     Kennungen und Kommentaren).
//  3. Die Texte der Abrechnung-Kachel (module.html) stehen vollständig in TILE_TEXTS und in locale.json.
//  4. Jede locale.json der Module ist identisch mit libs/locale.json.
//  5. Kein Schlüssel der Tabelle ist unbenutzt.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
$tab = json_decode((string)file_get_contents($root . '/libs/locale.json'), true)['translations']['de'];
$module = ['OCPPHubSplitter', 'OCPPHubLadepunkt', 'OCPPHubKonfigurator', 'OCPPHubAbrechnung'];
$fehler = [];
$benutzt = [];

// Kennungen und Protokollwerte, die bewusst deutsch bleiben (Datenfluss-Nachrichten, Idents, Vertrags-Schlüssel).
$kennung = '/^(Aktion|Ziel|Cpid|Zugaenge|Fahrzeuge|Gruppen|Kunden|Hook|Vertrag|Vertraege|Zustand|Lebenszeichen|Blockiert|Grund|Fahrzeug|Name|Zeitkorrelation|Messwerte|Leistung|Energie|Hersteller|Modell|Seriennummer|Status|Fehlercode|Phasen|Kapazitaeten|Stopp|Stromlimit|Ampere|Reservieren|Vorfuehrmodus|Autorisierung|Abrechnung|Verbrauch|Boot|Heartbeat|Authorize|Accepted|Blocked|Expired|Invalid|Absolute|Soft|Hidden|Closed|Opened|Version|Authorization|Basic |Preparing|Charging|Finishing|Passiv|Niemand|none|aktiv|zugang-gesperrt|kein-kunde-zugeordnet|kunde-gesperrt|ausserhalb-zeitfenster|limit-erreicht|reserviert)$/u';
$deutsch = '/[äöüÄÖÜß]|\b(und|oder|nicht|kein|keine|keiner|keins|ist|sind|wird|werden|der|die|das|dem|den|für|mit|bei|von|zum|zur|eine|einen|diese|dieser|Wallbox zuerst|Ladepunkt|Zugang|Kunde|aktiv|gesperrt|abgelehnt|Anfrage|Instanz)\b/u';

foreach ($module as $m) {
    $datei = "$root/$m/module.php";
    $tok = token_get_all((string)file_get_contents($datei));
    $n = count($tok);
    for ($i = 0; $i < $n; $i++) {
        $k = $tok[$i];
        if (!is_array($k) || $k[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }
        $roh = $k[1];
        $val = $roh[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($roh, 1, -1)) : stripcslashes(substr($roh, 1, -1));
        // vorheriges Nicht-Leerzeichen-Token
        $j = $i - 1;
        while ($j >= 0 && is_array($tok[$j]) && in_array($tok[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $j--;
        }
        $vor = $tok[$j] ?? '';
        $istT = $vor === '(' && $j >= 2 && is_array($tok[$j - 1]) && $tok[$j - 1][1] === 't' && is_array($tok[$j - 2]) && $tok[$j - 2][1] === '::';
        $imConst = false;
        for ($q = $i - 1; $q >= 0; $q--) {
            if ($tok[$q] === ';' || $tok[$q] === '{' || $tok[$q] === '}') {
                break;
            }
            if (is_array($tok[$q]) && $tok[$q][0] === T_CONST) {
                $imConst = true;
                break;
            }
        }
        // Feste Formular-Beschriftung ('caption'/'label'/'confirm' => 'English text'): die Konsole übersetzt sie
        // selbst (Store-Checkliste 25), der Text muss deshalb als Schlüssel in der Tabelle stehen.
        $q = $i + 1;
        while ($q < $n && is_array($tok[$q]) && $tok[$q][0] === T_WHITESPACE) {
            $q++;
        }
        $istCaption = false;
        if ($vor !== '' && is_array($vor) && $vor[0] === T_DOUBLE_ARROW && ($tok[$q] ?? null) !== '.') {
            $kj = $j - 1;
            while ($kj >= 0 && is_array($tok[$kj]) && $tok[$kj][0] === T_WHITESPACE) {
                $kj--;
            }
            $istCaption = is_array($tok[$kj] ?? null) && $tok[$kj][0] === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($tok[$kj][1], "'\""), ['caption', 'label', 'confirm'], true);
        }
        if ($istCaption && preg_match('/\p{L}/u', $val)) {
            $benutzt[$val] = true;
            if (!array_key_exists($val, $tab)) {
                $fehler[] = "$m:{$k[2]}: Formular-Beschriftung fehlt in locale.json: " . mb_substr($val, 0, 70);
            }
        }
        if ($istT || ($imConst && preg_match('/\p{L}{3,}/u', $val) && !preg_match('/^[{A-Za-z0-9_\-\.\/:\\\\}]+$/', $val))) {
            if (!preg_match('/\p{L}/u', $val)) {
                continue;
            }
            $benutzt[$val] = true;
            if (!array_key_exists($val, $tab)) {
                $fehler[] = "$m:{$k[2]}: Text fehlt in locale.json: " . mb_substr($val, 0, 70);
            }
        }
        // Deutsche Reste im Code (außerhalb von t() und Kennungen)?
        if (!$istT && !$imConst && !preg_match($kennung, $val) && preg_match($deutsch, $val) && !array_key_exists($val, $tab)) {
            // Lesbarer englischer Text mit zufälligem Treffer (z. B. "die" in Englisch gibt es nicht) -> melden
            $fehler[] = "$m:{$k[2]}: möglicherweise deutscher Text im Code: " . mb_substr($val, 0, 70);
        }
    }
    if (file_get_contents("$root/$m/locale.json") !== file_get_contents("$root/libs/locale.json")) {
        $fehler[] = "$m/locale.json ist nicht identisch mit libs/locale.json (php .tools/locale-bauen.php)";
    }
}

// Platzhalter
foreach ($tab as $en => $de) {
    preg_match_all('/%(?:\d+\$)?[sdf]|%\.\d+f/', $en, $a);
    preg_match_all('/%(?:\d+\$)?[sdf]|%\.\d+f/', $de, $b);
    if (count($a[0]) !== count($b[0])) {
        $fehler[] = 'Platzhalter ungleich: ' . mb_substr($en, 0, 60) . ' <-> ' . mb_substr($de, 0, 60);
    }
}

// Kachel
$html = (string)file_get_contents("$root/OCPPHubAbrechnung/module.html");
$php = (string)file_get_contents("$root/OCPPHubAbrechnung/module.php");
$schluessel = [];
foreach ([
    "/(?:title|label|zero|placeholder): '([^']*)'/",
    "/tr\('((?:[^'\\\\]|\\\\.)*)'\)/",
    "/^  (?:orphan|demo|unknown|adopt): '((?:[^'\\\\]|\\\\.)*)'/m",
] as $re) {
    preg_match_all($re, $html, $mm);
    foreach ($mm[1] as $x) {
        $schluessel[str_replace("\\'", "'", $x)] = true;
    }
}
unset($schluessel['tr']);
preg_match('/private const TILE_TEXTS = \[(.*?)\];/s', $php, $blk);
preg_match_all("/'((?:[^'\\\\]|\\\\.)*)',/", $blk[1] ?? '', $liste);
$inPhp = [];
foreach ($liste[1] as $x) {
    $inPhp[str_replace(["\\'", '\\\\'], ["'", '\\'], $x)] = true;
}
foreach (array_keys($schluessel) as $x) {
    if (!isset($inPhp[$x])) {
        $fehler[] = 'Kachel benutzt Text, der nicht in TILE_TEXTS steht: ' . mb_substr($x, 0, 60);
    }
    $benutzt[$x] = true;
    if (!array_key_exists($x, $tab)) {
        $fehler[] = 'Kacheltext fehlt in locale.json: ' . mb_substr($x, 0, 60);
    }
}
foreach (array_keys($inPhp) as $x) {
    if (!isset($schluessel[$x])) {
        $fehler[] = 'TILE_TEXTS enthält Text, den die Kachel nicht benutzt: ' . mb_substr($x, 0, 60);
    }
}
if (preg_match('/[äöüÄÖÜß]/u', preg_replace('/<!--.*?-->|\/\*.*?\*\//s', '', $html))) {
    $fehler[] = 'module.html enthält noch Umlaute außerhalb von Kommentaren';
}

// Unbenutzte Schlüssel (Warnung, kein Fehler: dynamisch zusammengesetzte Texte)
$unbenutzt = array_diff(array_keys($tab), array_keys($benutzt));
$hinweis = count($unbenutzt) > 0 ? count($unbenutzt) . ' Schlüssel der Tabelle werden nicht direkt benutzt (z. B. Konstanten, Formate): ' . implode(' | ', array_map(fn ($x) => mb_substr($x, 0, 40), array_slice($unbenutzt, 0, 6))) : '';

if ($fehler === []) {
    echo '✅ Sprache: ' . count($tab) . " Texte, alle vorhanden, Platzhalter stimmen, kein deutscher Rest im Code\n";
    if ($hinweis !== '') {
        echo "   ℹ️ $hinweis\n";
    }
    exit(0);
}
echo "❌ Sprache: " . count($fehler) . " Befund(e)\n   - " . implode("\n   - ", array_slice($fehler, 0, 60)) . "\n";
exit(1);
