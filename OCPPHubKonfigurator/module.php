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

require_once __DIR__ . '/../libs/OHUB_Text.php';

class OCPPHubKonfigurator extends IPSModule
{
    private const LADEPUNKT_GUID = '{27A1625F-A006-4945-8A36-FFBAA38A5FB5}';

    // Bei jedem Versions-Bump in library.json auch hier nachziehen
    // (Verbund-Konvention „Dokumentation & Hilfe"-Panel, siehe SUITE.md).
    private const VERSION = '0.3.1';
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
    private const NEWS_VERSION = '0.3.0';
    private const NEWS_ITEMS = [
        '🌐 New: German and English. The form and the messages follow the Symcon language setting.',
        '🔌 Technical: The configurator is now a device below the splitter and asks it for the wallboxes it has seen through the Symcon data flow. You select the splitter at the top, under the interface (“Gateway”); the “OCPPHub splitter” field is gone. Existing instances are connected automatically the first time you apply changes after the update.',
        'New: “🧡 About this module” at the very bottom of the form (license/donations); the feedback notice is now a panel of its own that you can hide, instead of a line of text (store convention addition).',
        'New: “👋 What is this module for?” — a new panel at the very top of the form briefly explains what this instance does and what it is good for (store convention addition, same pattern as the “What’s new” panel below it).',
        'The splitter can now also be selected manually (selection field at the top) in case automatic detection via the instance nesting does not work.',
        'Newly created charge point instances now get their splitter assignment right away when they are created — before, it had to be set on the charge point afterwards.',
    ];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Quelltexte sind englisch, Deutsch steht in locale.json; je Text fragt Symcon nur einmal je Prozess.
        OHUB_Text::setzen(fn (string $text): string => $this->Translate($text));
    }

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
                $this->LogMessage(OHUB_Text::t('Connected to splitter #%d (data flow).', $kandidat), KL_MESSAGE);
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
                return OHUB_Text::t('ℹ️ No OCPPHub splitter found in the system. First create an “OCPPHub Splitter” instance and then create this configurator below it; without a splitter this list shows no wallboxes.');
            }
            $nennen = implode(', ', array_map(fn ($id) => '#' . $id . ' ' . OHUB_Text::t('“%s”', IPS_GetName($id)), $all));
            return OHUB_Text::t('⚠️ No splitter assigned; the system has %s. Select the splitter at the top, under the interface (“Gateway”); nothing is guessed.', $nennen);
        }
        if (!@IPS_InstanceExists($splitterId) || IPS_GetInstance($splitterId)['ModuleInfo']['ModuleID'] !== self::SPLITTER_GUID) {
            return OHUB_Text::t('⛔ The selected instance #%d is not an OCPPHub splitter (or no longer exists). Please select the interface at the top again.', $splitterId);
        }
        $name = '#' . $splitterId . ' ' . OHUB_Text::t('“%s”', IPS_GetName($splitterId));
        $lib = @IPS_GetLibrary(IPS_GetInstance($splitterId)['ModuleInfo']['LibraryID'])['Version'] ?? '';
        $version = $lib !== '' ? ' (OCPPHub ' . $lib . ')' : '';
        if (!(bool)@IPS_GetProperty($splitterId, 'Active')) {
            return OHUB_Text::t('⚠️ Connected to splitter %s, but it is deactivated: wallboxes cannot report there, the list stays as it was last.', $name . $version);
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
            return OHUB_Text::t('ℹ️ Connected to splitter %s, but no wallbox has reported there yet. Enter the endpoint on the wallbox (it is shown in the splitter form); it will then appear here.', $name . $version);
        }
        $text = OHUB_Text::t('✅ Connected to splitter %s. %d wallbox(es) reported: ', $name . $version, count($seenRows));
        $text .= count($angelegt) > 0 ? OHUB_Text::t('created as a charge point: %s', implode(', ', $angelegt)) : OHUB_Text::t('none created as a charge point yet');
        $text .= count($fehlt) > 0 ? OHUB_Text::t('; still without a charge point: %s (use “Create” below).', implode(', ', $fehlt)) : '.';
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
                    'lastSeen'   => date(OHUB_Text::t('Y-m-d H:i:s'), $lastSeen),
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
                    'caption'  => OHUB_Text::t('📖 Documentation & help (version %s)', self::VERSION),
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'What this instance does: setup aid only, no function of its own during normal operation. Shows the charge point identities that have already reported to the selected splitter via OCPP but do not yet have an “OCPPHub Charge Point” instance of their own — saves creating them by hand, including the correct splitter assignment.'],
                        ['type' => 'Label', 'caption' => '1️⃣ First set the wallbox to the splitter endpoint in its own OCPP configuration (backend URL, see the splitter instance, “Documentation & help” panel there). Once it has reported in, its charge point identity appears in the “Wallboxes seen” list below — even if the connection is closed again afterwards.'],
                        ['type' => 'Label', 'caption' => '2️⃣ Click “Create” in the row of the wallbox you want. This creates a new “OCPPHub Charge Point” instance with the charge point identity AND the splitter assignment already filled in correctly — nothing else is required in the charge point form afterwards, unless you want current limits or PV surplus charging to differ from the defaults.'],
                        ['type' => 'Label', 'caption' => 'ℹ️ You select the splitter at the top, under the interface (“Gateway”) of this instance; it is a device below the “OCPPHub Splitter” (Symcon data flow). If no splitter is detected, the status line below says so. Existing instances are connected automatically the first time you apply changes after the update, provided there is exactly one splitter.'],
                    ],
                ],
                $this->splitterStatusLabel($this->splitterStatusLine($splitterId, $values)),
                [
                    'type'     => 'Configurator',
                    'name'     => 'ChargePointList',
                    'caption'  => 'Wallboxes seen',
                    'rowCount' => 10,
                    'delete'   => false,
                    'sort'     => ['column' => 'lastSeen', 'direction' => 'descending'],
                    'columns'  => [
                        ['caption' => 'Charge point identity', 'name' => 'cpid', 'width' => '300px'],
                        ['caption' => 'Last seen', 'name' => 'lastSeen', 'width' => '200px'],
                    ],
                    'values' => $values,
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Active'],
            ],
        ];

        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'ExpansionPanel', 'name' => 'ReviewHint', 'expanded' => true,
                'caption' => '💬 Feedback',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 OCPPHub is an early beta — feedback is welcome in the Symcon forum.'],
                    ['type' => 'Button', 'caption' => 'Go to forum thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                    ['type' => 'Button', 'caption' => 'Do not show again', 'onClick' => 'OHUBK_DismissReviewHint($id);'],
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
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private, non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Do you like the module and would still like to leave something behind? I would be happy about a small donation — entirely voluntary, nothing expected in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            'caption' => '👋 What is this module for?',
            'items' => [
                ['type' => 'Label', 'caption' => 'The configurator is purely a setup aid: it shows wallboxes that have already reported to an “OCPPHub Splitter” instance via OCPP but do not yet have an “OCPPHub Charge Point” instance of their own — create them with one click instead of typing the charge point identity and the splitter assignment by hand.'],
                ['type' => 'Label', 'caption' => 'The benefit: especially when setting up several wallboxes for the first time, this saves repeated manual creation. In day-to-day operation the configurator is no longer needed — control and display run entirely through the charge point instances themselves.'],
                ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'OHUBK_AckPurposeIntro($id);'],
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
        $items = [['type' => 'Label', 'caption' => '🆕 New in this module — please take a quick look and check your settings if needed:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . OHUB_Text::t($line)];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Understood – do not show again', 'onClick' => 'OHUBK_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => OHUB_Text::t('🆕 New in version %s', self::NEWS_VERSION), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }
}
