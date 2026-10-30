<?php

// ===========================================================================
// OCPPHub Konfigurator — zeigt Charge-Point-Identities, die sich bereits per
// OCPP beim Splitter gemeldet haben, aber noch keine Ladepunkt-Instanz
// haben. Klick auf „Erstellen" legt eine OCPPHub-Ladepunkt-Instanz mit
// vorausgefüllter CPID an.
// FIX 30.08.2026 (Live-Fund: Konfigurator blieb leer): ursprünglich verlassen
// auf IPS_GetParent() für die Splitter-Zuordnung (setzt voraus, dass die
// automatische Parent-Verbindung beim Anlegen tatsächlich gegriffen hat —
// war von Anfang an als UNGETESTETE Annahme markiert und hat sich als
// nicht zuverlässig herausgestellt). Jetzt zusätzlich explizites
// Auswahlfeld „SplitterID", das Vorrang vor IPS_GetParent() hat.
// Aktueller Stand (14.09.2026): live verifiziert, mehrfach zum Anlegen
// echter Ladepunkt-Instanzen (WB1/WB2) genutzt, siehe OCPPHubSplitter-Header.
// ===========================================================================

class OCPPHubKonfigurator extends IPSModule
{
    private const LADEPUNKT_GUID = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.2.0';
    private const SPLITTER_GUID = '{81D3E328-9E12-43A9-825A-F7888530868C}';
    // Datenfluss (Symcon-Review 30.10.2026), siehe .docs/datenfluss.md
    private const DATA_ZUM_SPLITTER = '{6ED0496A-EDD0-4600-9549-04D18B8387F5}';
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';
    // „Über dieses Modul" (14.09.2026, SUITE.md Formular-Konvention Punkt
    // 5) — siehe OCPPHubSplitter für den LICENSE-Branch-Stolperstein.
    private const LICENSE_URL = 'https://github.com/DG65/NRGOCPPHub/blob/main/LICENSE';
    // Symcon-Forum-Vorstellungs-Thread (16.09.2026, von Dietmar selbst
    // gepostet, siehe forum-ankuendigung-ocpphub.md).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/beta-modul-nrg-stack-ocpphub-wallboxen-per-ocpp-1-6j-anbinden-mit-pv-ueberschussladen-und-kundenverwaltung/144409';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    // „Was ist neu"-Banner (Verbund-Konvention, siehe SUITE.md, Referenz
    // ChargerHub) — bei jedem nutzerrelevanten Änderungs-Bump aktualisieren,
    // NICHT bei jedem library.json-Build (sonst nervt es).
    private const NEWS_VERSION = '0.2.0';
    private const NEWS_ITEMS = [
        '🔌 Technik: Der Konfigurator ist jetzt ein Gerät unter dem Splitter und fragt die gesehenen Wallboxen über den Symcon-Datenfluss ab. Den Splitter wählst du oben bei der Schnittstelle („Gateway“); das Feld „OCPPHub-Splitter“ entfällt. Bestehende Instanzen werden beim ersten Übernehmen nach dem Update automatisch verbunden.',
        'Neu: „🧡 Über dieses Modul" ganz unten im Formular (Lizenz/Spenden), Feedback-Hinweis jetzt als eigenes ausblendbares Panel statt einer Textzeile (Store-Konventions-Ergänzung).',
        'Neu: „👋 Wozu dieses Modul?" — ein neues Panel ganz oben im Formular erklärt kurz, was diese Instanz macht und wofür sie gut ist (Store-Konventions-Ergänzung, gleiches Muster wie das „Was ist neu"-Panel darunter).',
        'Splitter-Zuordnung jetzt auch manuell wählbar (Auswahlfeld oben), falls die automatische Erkennung über die Instanz-Verschachtelung nicht greift.',
        'Neu angelegte Ladepunkt-Instanzen bekommen ihre Splitter-Zuordnung jetzt direkt beim Erstellen korrekt mit — vorher musste sie am Ladepunkt selbst nachträglich gesetzt werden.',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SplitterID', 0);   // nur noch für die einmalige Migration (VerbindungPruefen)
        $this->RegisterTimer('VerbindenTimer', 0, 'OHUBK_VerbindungPruefen($_IPS[\'TARGET\']);');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        $this->RegisterAttributeString('SeenNews', '');
        // „Wozu dieses Modul?" (14.09.2026, EMS-Fund über Dietmar — Store-
        // Konventions-Prüfung, Formular-Konvention Punkt 0, Referenz
        // MeterHub): ganz oben VOR dem News-Panel, aufgeklappt, einmalig
        // dismissible.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
        if ($this->resolveSplitterId() === 0) {
            $this->SetTimerInterval('VerbindenTimer', 2000);
        }
    }

    // Der Splitter ist das echte übergeordnete Gerät dieser Instanz (Symcon-Datenfluss, Review
    // 30.10.2026) — er wird im Formular oben über die Schnittstelle („Gateway“) gewählt.
    private function resolveSplitterId(): int
    {
        return (int)(IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    /**
     * Einmalig nach dem Übernehmen (Timer): Bestandsinstanzen hingen bisher nur über die Property
     * SplitterID oder die Objektbaum-Position am Splitter. Jetzt mit ihm verbinden. Wo sich kein
     * eindeutiger Splitter ergibt, wird nichts geraten (die Statuszeile weist darauf hin).
     */
    public function VerbindungPruefen(): void
    {
        $this->SetTimerInterval('VerbindenTimer', 0);
        if ($this->resolveSplitterId() !== 0) {
            return;
        }
        $kandidat = $this->ReadPropertyInteger('SplitterID');
        if ($kandidat <= 0) {
            $oben = (int)(@IPS_GetParent($this->InstanceID) ?: 0);
            if ($oben > 0 && @IPS_InstanceExists($oben) && IPS_GetInstance($oben)['ModuleInfo']['ModuleID'] === self::SPLITTER_GUID) {
                $kandidat = $oben;
            }
        }
        if ($kandidat <= 0) {
            $alle = @IPS_GetInstanceListByModuleID(self::SPLITTER_GUID) ?: [];
            if (count($alle) === 1) {
                $kandidat = (int)$alle[0];
            }
        }
        if ($kandidat > 0 && @IPS_InstanceExists($kandidat) && IPS_GetInstance($kandidat)['ModuleInfo']['ModuleID'] === self::SPLITTER_GUID) {
            if (@IPS_ConnectInstance($this->InstanceID, $kandidat)) {
                $this->LogMessage('Mit Splitter #' . $kandidat . ' verbunden (Datenfluss).', KL_MESSAGE);
            }
        }
    }

    // Der Splitter verlangt von allen seinen Kindern die Schnittstelle „Splitter -> Kind“. Der Konfigurator
    // braucht keine Meldungen des Splitters, nimmt sie nur entgegen und antwortet nie.
    public function ReceiveData($JSONString)
    {
        return '';
    }

    /** Gesehene, noch nicht angelegte Wallboxen — Anfrage an den Splitter über den Datenfluss. */
    private function geseheneWallboxen(): array
    {
        if ($this->resolveSplitterId() <= 0) {
            return [];
        }
        $roh = $this->SendDataToParent((string)json_encode(['DataID' => self::DATA_ZUM_SPLITTER, 'Aktion' => 'GeseheneWallboxen']));
        $a = json_decode((string)$roh, true);
        return (is_array($a) && !empty($a['ok'])) ? (array)($a['daten'] ?? []) : [];
    }

    // SUITE.md „Verbund-Verbindungen im Formular sichtbar machen" (21.09.2026):
    // live berechnete Statuszeile zur Splitter-Verbindung, nie ein statischer
    // Satz. Zustände: ✅ verbunden (mit Zahlen), ⚠️ verbunden, aber nichts
    // Brauchbares bzw. mehrdeutig, ℹ️ nicht gefunden, ⛔ Angabe ungültig.
    private function splitterStatusLine(int $splitterId, array $seenRows): string
    {
        $all = @IPS_GetInstanceListByModuleID(self::SPLITTER_GUID) ?: [];
        if ($splitterId <= 0) {
            if (count($all) === 0) {
                return 'ℹ️ Kein OCPPHub-Splitter im System gefunden. Zuerst eine „OCPPHub Splitter"-Instanz anlegen und diesen Konfigurator darunter anlegen; ohne Splitter zeigt diese Liste keine Wallboxen.';
            }
            $nennen = implode(', ', array_map(fn ($id) => '#' . $id . ' „' . IPS_GetName($id) . '"', $all));
            return '⚠️ Kein Splitter zugeordnet, im System gibt es ' . $nennen . '. Den Splitter oben bei der Schnittstelle („Gateway“) wählen, es wird nichts geraten.';
        }
        if (!@IPS_InstanceExists($splitterId) || IPS_GetInstance($splitterId)['ModuleInfo']['ModuleID'] !== self::SPLITTER_GUID) {
            return '⛔ Die gewählte Instanz #' . $splitterId . ' ist kein OCPPHub-Splitter (oder existiert nicht mehr). Bitte die Schnittstelle oben neu wählen.';
        }
        $name = '#' . $splitterId . ' „' . IPS_GetName($splitterId) . '"';
        $lib = @IPS_GetLibrary(IPS_GetInstance($splitterId)['ModuleInfo']['LibraryID'])['Version'] ?? '';
        $version = $lib !== '' ? ' (OCPPHub ' . $lib . ')' : '';
        if (!(bool)@IPS_GetProperty($splitterId, 'Active')) {
            return '⚠️ Verbunden mit Splitter ' . $name . $version . ', aber der ist deaktiviert: Wallboxen können sich dort nicht melden, die Liste bleibt so, wie sie zuletzt war.';
        }
        $angelegt = [];
        $fehlt = [];
        foreach ($seenRows as $row) {
            if ((int)$row['instanceID'] > 0) {
                $angelegt[] = $row['cpid'];
            } else {
                $fehlt[] = $row['cpid'];
            }
        }
        if (count($seenRows) === 0) {
            return 'ℹ️ Verbunden mit Splitter ' . $name . $version . ', aber noch keine Wallbox hat sich dort gemeldet. Endpunkt an der Wallbox eintragen (steht im Splitter-Formular), danach erscheint sie hier.';
        }
        $text = '✅ Verbunden mit Splitter ' . $name . $version . '. ' . count($seenRows) . ' Wallbox(en) gemeldet: ';
        $text .= count($angelegt) > 0 ? 'als Ladepunkt angelegt: ' . implode(', ', $angelegt) : 'noch keine als Ladepunkt angelegt';
        $text .= count($fehlt) > 0 ? '; noch ohne Ladepunkt: ' . implode(', ', $fehlt) . ' (unten „Erstellen").' : '.';
        return $text;
    }

    private function splitterStatusLabel(string $line): array
    {
        $label = ['type' => 'Label', 'name' => 'SplitterStatus', 'caption' => $line];
        if (str_starts_with($line, '⛔')) {
            $label['color'] = 0xFF0000;
        }
        return $label;
    }

    public function GetConfigurationForm()
    {
        $splitterId = $this->resolveSplitterId();

        // FIX 30.08.2026 (Live-Fund, Dashboard-Diagnose + eigene Nachprüfung
        // an Dietmars Instanz): IPS_GetChildrenIDs($splitterId) spiegelt
        // NICHT zuverlässig die Splitter-Zuordnung (Objektbaum-Position ≠
        // tatsächliche Splitter-Zugehörigkeit, siehe OCPPHubSplitter
        // ownLadepunkte()-Kommentar). Alle Ladepunkt-Instanzen im System
        // durchsuchen und über die SplitterID-Property filtern.
        // Vorhandene Ladepunkte dieses Splitters: Instanzen, die über den Datenfluss an ihm hängen
        // (Gerätesuche des Konfigurators, daher die Instanzliste). Bestandsinstanzen, die noch
        // nicht verbunden wurden, zählen über ihre alte Property SplitterID mit.
        $existing = [];
        if ($splitterId > 0) {
            foreach (@IPS_GetInstanceListByModuleID(self::LADEPUNKT_GUID) ?: [] as $childId) {
                $verbunden = (int)(IPS_GetInstance($childId)['ConnectionID'] ?? 0);
                $matches = $verbunden > 0
                    ? $verbunden === $splitterId
                    : (int)@IPS_GetProperty($childId, 'SplitterID') === $splitterId;
                if ($matches) {
                    $existing[@IPS_GetProperty($childId, 'CPID')] = $childId;
                }
            }
        }

        $values = [];
        if ($splitterId > 0) {
            foreach ($this->geseheneWallboxen() as $cpid => $lastSeen) {
                $values[] = [
                    'name'       => $cpid,
                    'cpid'       => $cpid,
                    'lastSeen'   => date('d.m.Y H:i:s', $lastSeen),
                    'instanceID' => $existing[$cpid] ?? 0,
                    'create'     => [
                        'moduleID'      => self::LADEPUNKT_GUID,
                        'name'          => $cpid,
                        // SplitterID direkt mitgeben (Pflichtfeld am
                        // Ladepunkt, siehe dortiger FIX-Kommentar) — sonst
                        // müsste man sie nach dem Erstellen von Hand
                        // nachtragen.
                        'configuration' => ['CPID' => $cpid, 'SplitterID' => $splitterId],
                    ],
                ];
            }
        }

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖 Dokumentation & Hilfe (Version ' . self::VERSION . ')',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Was diese Instanz macht: reine Ersteinrichtungs-Hilfe, keine eigene Funktion im laufenden Betrieb. Zeigt Charge-Point-Identities, die sich bereits per OCPP beim ausgewählten Splitter gemeldet haben, aber noch keine eigene „OCPPHub Ladepunkt"-Instanz haben — spart das manuelle Anlegen samt korrekter Splitter-Zuordnung.'],
                        ['type' => 'Label', 'caption' => '1️⃣ Wallbox zuerst in ihrer eigenen OCPP-Konfiguration auf den Splitter-Endpunkt einstellen (Backend-URL, siehe Splitter-Instanz, Panel „Dokumentation & Hilfe" dort). Sobald sie sich einmal gemeldet hat, erscheint ihre Charge-Point-Identity unten in der Liste „Gesehene Wallboxen" — auch wenn die Verbindung danach wieder getrennt wird.'],
                        ['type' => 'Label', 'caption' => '2️⃣ In der Zeile der gewünschten Wallbox auf „Erstellen" klicken. Das legt eine neue „OCPPHub Ladepunkt"-Instanz an, mit Charge-Point-Identity UND Splitter-Zuordnung bereits korrekt vorausgefüllt — im Ladepunkt-Formular ist danach nichts weiter zwingend nötig, es sei denn, Stromgrenzen oder PV-Überschussladen sollen von den Standardwerten abweichen.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ Den Splitter wählst du oben bei der Schnittstelle („Gateway“) dieser Instanz; sie ist ein Gerät unter dem „OCPPHub Splitter“ (Symcon-Datenfluss). Wird kein Splitter erkannt, zeigt die Statuszeile unten das an. Bestandsinstanzen werden beim ersten Übernehmen nach dem Update automatisch verbunden, wenn sich ein eindeutiger Splitter ergibt.'],
                    ],
                ],
                $this->splitterStatusLabel($this->splitterStatusLine($splitterId, $values)),
                [
                    'type'     => 'Configurator',
                    'name'     => 'ChargePointList',
                    'caption'  => 'Gesehene Wallboxen',
                    'rowCount' => 10,
                    'delete'   => false,
                    'sort'     => ['column' => 'lastSeen', 'direction' => 'descending'],
                    'columns'  => [
                        ['caption' => 'Charge-Point-Identity', 'name' => 'cpid', 'width' => '300px'],
                        ['caption' => 'Zuletzt gesehen', 'name' => 'lastSeen', 'width' => '200px'],
                    ],
                    'values' => $values,
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
            ],
        ];

        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => '💬 Feedback',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub ist früher Beta-Stand — Rückmeldungen willkommen im Symcon-Forum.'],
                    ['type' => 'Button', 'caption' => 'Zum Forum-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'OHUBK_DismissReviewHint($id);'],
                ],
            ];
        }

        $form['elements'][] = $this->licenseHint();

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
    // NICHT dismissible, Wortlaut verbundweit identisch ("Variante A").
    private function licenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            'caption' => '👋 Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'Der Konfigurator ist eine reine Einrichtungshilfe: er zeigt Wallboxen, die sich bereits per OCPP bei einer „OCPPHub Splitter"-Instanz gemeldet haben, aber noch keine eigene „OCPPHub Ladepunkt"-Instanz haben — Ein-Klick-Anlegen statt Charge-Point-Identity und Splitter-Zuordnung von Hand einzutippen.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: besonders beim Ersteinrichten mehrerer Wallboxen spart das wiederholtes manuelles Anlegen. Für den laufenden Betrieb wird der Konfigurator nicht mehr gebraucht — die eigentliche Steuerung/Anzeige läuft komplett über die Ladepunkt-Instanzen selbst.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'OHUBK_AckPurposeIntro($id);'],
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

    private function newsBanner(): ?array
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'OHUBK_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }
}
