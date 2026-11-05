<?php
/**
 * Kopiert libs/locale.json (Quelle der deutschen Fassung zu den englischen Quelltexten) in die vier
 * Modulordner, denn Symcon liest locale.json nur im Ordner des jeweiligen Moduls.
 * Aufruf: php .tools/locale-bauen.php
 */
declare(strict_types=1);
$quelle = (string)file_get_contents(__DIR__ . '/../libs/locale.json');
$tabelle = json_decode($quelle, true, 512, JSON_THROW_ON_ERROR);
foreach (['OCPPHubSplitter', 'OCPPHubLadepunkt', 'OCPPHubKonfigurator', 'OCPPHubAbrechnung'] as $modul) {
    file_put_contents(__DIR__ . '/../' . $modul . '/locale.json', $quelle);
}
echo 'locale.json fuer 4 Module geschrieben (' . count($tabelle['translations']['de']) . " Texte)\n";
