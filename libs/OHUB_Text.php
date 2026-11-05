<?php

declare(strict_types=1);

// ===========================================================================
// Uebersetzung fuer alle vier OCPPHub-Module. Die Quelltexte stehen auf
// Englisch im Code, die deutsche Fassung in locale.json (Symcons uebliches
// Muster). libs/locale.json ist die Quelle; php .tools/locale-bauen.php kopiert
// sie in die vier Modulordner (Symcon liest locale.json nur dort).
//
// Symcons Translate() liest bei JEDEM Aufruf die locale.json (SUITE.md
// Stolperstein 24). Jeder Text fragt Symcon deshalb hoechstens EINMAL je
// Prozess und merkt sich die Antwort; danach kostet er nur einen Array-Zugriff.
// ===========================================================================

final class OHUB_Text
{
    /** @var callable[] Uebersetzer der zuletzt gebauten Instanzen, neueste zuerst (loeschen -> naechster springt ein) */
    private static $kette = [];
    /** @var array<string,string> gemerkte Uebersetzungen */
    private static $gemerkt = [];
    /** @var int Zaehler fuer den Pruefstand: wie oft wurde Symcon wirklich gefragt */
    public static $anfragen = 0;

    /**
     * Uebersetzer eines Moduls eintragen: Symcons Translate() einer Instanz. Alle Instanzen einer Bibliothek
     * laufen im selben Prozess; wird die Instanz geloescht, deren Translate() gerade zuständig war, springt
     * die naechste ein (sonst erschiene ploetzlich Englisch in der deutschen Oberflaeche).
     */
    public static function setzen(?callable $translate): void
    {
        self::$gemerkt = [];
        if ($translate === null) {
            self::$kette = [];
            return;
        }
        array_unshift(self::$kette, $translate);
        self::$kette = array_slice(self::$kette, 0, 8);
    }

    private static function uebersetzen(string $text): string
    {
        if (isset(self::$gemerkt[$text])) {
            return self::$gemerkt[$text];
        }
        foreach (self::$kette as $translate) {
            try {
                self::$anfragen++;
                return self::$gemerkt[$text] = (string)$translate($text);
            } catch (\Throwable $e) {
                // Instanz waehrend des Aufrufs geloescht: naechsten Uebersetzer versuchen.
            }
        }
        return $text;
    }

    /** Text uebersetzen; mit Argumenten wie sprintf (Platzhalter %s/%d, ein Prozentzeichen als %%). */
    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::uebersetzen($text);
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(function ($a) {
            return is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a);
        }, $args));
        return $aus === false ? $uebersetzt : $aus;
    }
}
