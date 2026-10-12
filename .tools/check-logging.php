<?php
/**
 * check-logging.php — Prüft, dass die Modulklassen kein IPS_LogMessage() mehr
 * nutzen (Verbund-Konvention, SUITE.md Store-Review-Checkliste Punkt 15,
 * EMS-Fund 12.10.2026 bei Batteriewächter): in Modulklassen gehört
 * $this->LogMessage($text, KL_...) hin, nicht IPS_LogMessage($absender,$text) —
 * Ersteres landet im Meldungsprotokoll der Instanz selbst (Konsole "Meldungen"),
 * zweiteres nur im globalen, nicht instanzbezogenen Systemprotokoll.
 *
 * Aufruf:  php .tools/check-logging.php
 * Rückgabe: 0 = kein Fund, 1 = mindestens ein IPS_LogMessage() in einer module.php
 */

$root = dirname(__DIR__);
$files = glob($root . '/OCPPHub*/module.php');
sort($files);

$fails = [];
foreach ($files as $file) {
    $code = file_get_contents($file);
    if ($code === false) {
        continue;
    }
    if (strpos($code, 'IPS_LogMessage(') !== false) {
        $fails[] = ltrim(str_replace($root, '', $file), DIRECTORY_SEPARATOR);
    }
}

echo "Logging-Konvention — \$this->LogMessage() statt IPS_LogMessage()\n";
echo str_repeat('-', 62) . "\n";
echo 'Geprüfte Dateien: ' . count($files) . "\n\n";

if (!$fails) {
    echo "OK — kein IPS_LogMessage() in einer der vier Modulklassen.\n";
    exit(0);
}

echo 'FEHLER — IPS_LogMessage() gefunden in:' . "\n";
foreach ($fails as $f) {
    echo "  - $f\n";
}
echo "\nBitte durch \$this->LogMessage(\$text, KL_WARNING|KL_ERROR|KL_NOTIFY|KL_MESSAGE|\n";
echo "KL_DEBUG|KL_CUSTOM) ersetzen (passende Stufe wählen, Absenderstring entfällt).\n";
exit(1);
