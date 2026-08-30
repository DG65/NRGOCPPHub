<?php

// ===========================================================================
// OCPPHub Ladepunkt — eine Instanz je Wallbox/Connector, Kind der OCPPHub-
// Splitter-Instanz. Hält die sichtbaren Variablen (Ident-Vokabular bewusst
// identisch zu ChargerHub, siehe .docs/architektur.md „Instanzmodell") und
// das eigenständige PV-Überschussladen (Fallback ohne EMS, Logik aus
// ChargerHub SurplusChargeControl() 0.9.53 portiert).
//
// STUFE 1 (siehe .docs/pflichtenheft.md): kein RFID, keine Reservierung,
// keine Phasenumschaltung (ctl_phase_mode noch nicht implementiert — pro
// Hersteller zu verifizieren, siehe architektur.md), keine
// Splitter-interne Lastverteilung bei mehreren eigenen Ladepunkten
// gleichzeitig (TODO Stufe 2, siehe architektur.md „Splitter-interne
// Lastverteilung"). UNGETESTET, siehe Splitter-Header.
// ===========================================================================

class OCPPHubLadepunkt extends IPSModule
{
    private const METERHUB_GUID     = '{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}';
    private const INVERTERHUB_GUID  = '{BBE2C593-1A91-426D-A714-29A9C7E87589}';
    private const EMS_GUID          = '{90286A25-E6C9-4A66-BD4E-0CFB707C2C6C}';

    private const MIN_CURRENT_HARD = 6; // A — kleinster IEC-61851-Ladestrom

    private const MANAGEDBY_ALL = ['none', 'ems', 'goe-controller', 'tibber', 'p14a', 'marketer', 'other'];
    private const MANAGEDBY_LABELS = [
        'none'           => 'Niemand — frei / manuell (Standard)',
        'ems'            => 'Energiemanagement (EMS)',
        'goe-controller' => 'go-e Controller (Überschussladen)',
        'tibber'         => 'Tibber (Regelenergie / Grid Rewards)',
        'p14a'           => '§14a-Steuerung (Netzbetreiber)',
        'marketer'       => 'Direktvermarkter',
        'other'          => 'Anderes externes Lastmanagement',
    ];

    // OCPP-1.6-StatusNotification-Werte (ChargePointStatus) → Anzeige-Code
    // fürs `state`-Profil (Sprachregel: Anzeige auf Deutsch, Rohwert bleibt
    // als state_raw für Diagnose erhalten).
    private const STATUS_MAP = [
        'Available'     => 0,
        'Preparing'     => 1,
        'Charging'      => 2,
        'SuspendedEVSE' => 3,
        'SuspendedEV'   => 4,
        'Finishing'     => 5,
        'Reserved'      => 6,
        'Unavailable'   => 7,
        'Faulted'       => 8,
    ];

    public function Create()
    {
        parent::Create();

        // Charge-Point-Identity — der URL-Pfad-Teil, mit dem sich diese
        // Wallbox beim Splitter meldet. Einziges Pflichtfeld.
        $this->RegisterPropertyString('CPID', '');
        $this->RegisterPropertyString('Label', '');

        $this->RegisterPropertyInteger('MinCurrent', self::MIN_CURRENT_HARD);
        $this->RegisterPropertyInteger('MaxCurrent', 16);
        $this->RegisterPropertyString('ManagedBy', 'none');

        // Eigenständiges Überschussladen als Fallback ohne EMS (Dietmars
        // Vorgabe, wie in ChargerHub) — Default aus, sicherer Opt-in.
        $this->RegisterPropertyBoolean('EnableSurplusCharging', false);
        $this->RegisterPropertyInteger('IntervalFast', 10);
        $this->RegisterPropertyInteger('StorageSharePercent', 0);
        $this->RegisterPropertyFloat('BatteryCapacityKWh', 0.0);
        $this->RegisterPropertyInteger('SurplusMeterID', 0);

        $this->RegisterAttributeInteger('LastTransactionId', 0);
        $this->RegisterAttributeInteger('MeterStartWh', 0);
        // Tages-Override „heute Vollladen trotz PV-Vorrang" (Backend-Funktion
        // für Dashboard, siehe OHUB_SetDailyOverride am Splitter). Reset auf
        // "aus" übernimmt OCPPHub SELBST anhand von DailyOverrideDate (siehe
        // Update()) — Dashboard-Rückfrage 30.08.2026 zeigte, dass ein
        // Cron/Timer auf deren Seite unnötige neue Infrastruktur wäre, wenn
        // der Zustand ohnehin hier bei uns liegt.
        $this->RegisterAttributeBoolean('DailyOverride', false);
        $this->RegisterAttributeString('DailyOverrideDate', '');

        $this->RegisterTimer('SurplusTimer', 0, 'OHUBL_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('EnableActionsTimer', 0, 'OHUBL_EnableActions($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        $active = $this->ReadPropertyString('CPID') !== '';
        if (!$active) {
            $this->SetTimerInterval('SurplusTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval(
            'SurplusTimer',
            $this->ReadPropertyBoolean('EnableSurplusCharging') ? $this->ReadPropertyInteger('IntervalFast') * 1000 : 0
        );
        $this->SetTimerInterval('EnableActionsTimer', 200);
        $this->SetStatus(102);
    }

    // 200ms nach ApplyChanges (Muster wie ChargerHub/InverterHub, siehe
    // SUITE.md „IP-Symcon-Stolpersteine" — EnableAction() braucht die fertig
    // aufgebaute Instanz-Baumstruktur, deshalb verzögert statt direkt in
    // ApplyChanges()).
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);
        $this->EnableAction('ctl_enable');
        $this->EnableAction('ctl_curr_limit');
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'CPID',
                    'caption' => 'Charge-Point-Identity (aus der Wallbox-OCPP-Konfiguration)',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Label',
                    'caption' => 'Anzeigename (leer = Instanzname)',
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '⚡ Stromgrenzen & Steuerungshoheit',
                    'items'   => [
                        ['type' => 'NumberSpinner', 'name' => 'MinCurrent', 'caption' => 'Minimaler Ladestrom (A)'],
                        ['type' => 'NumberSpinner', 'name' => 'MaxCurrent', 'caption' => 'Maximaler Ladestrom (A)'],
                        [
                            'type'    => 'Select',
                            'name'    => 'ManagedBy',
                            'caption' => 'Wer regelt diesen Ladepunkt?',
                            'options' => array_map(
                                fn ($key) => ['value' => $key, 'caption' => self::MANAGEDBY_LABELS[$key]],
                                self::MANAGEDBY_ALL
                            ),
                        ],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => '☀️ PV-Überschussladen (eigenständig, nur ohne aktives EMS)',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'EnableSurplusCharging', 'caption' => 'Aktivieren'],
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Regelintervall (s)'],
                        ['type' => 'NumberSpinner', 'name' => 'StorageSharePercent', 'caption' => 'Speicheranteil vom Überschuss (%)'],
                        ['type' => 'NumberSpinner', 'name' => 'BatteryCapacityKWh', 'digits' => 1, 'caption' => 'Speicherkapazität (kWh, 0 = unbekannt)'],
                        ['type' => 'SelectInstance', 'name' => 'SurplusMeterID', 'caption' => 'Netzzähler erzwingen (leer = automatisch über MeterHub-Vertrag)'],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Charge-Point-Identity fehlt'],
            ],
        ]);
    }

    private function CreateProfiles(): void
    {
        // Gemeinsame NRG.*-Profile (Verbund-Regel 8) — nur anlegen, wenn sie
        // noch nicht existieren, nie überschreiben.
        if (!IPS_VariableProfileExists('NRG.Watt')) {
            IPS_CreateVariableProfile('NRG.Watt', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Watt', '', ' W');
            IPS_SetVariableProfileDigits('NRG.Watt', 0);
        }
        if (!IPS_VariableProfileExists('NRG.kWh')) {
            IPS_CreateVariableProfile('NRG.kWh', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.kWh', '', ' kWh');
            IPS_SetVariableProfileDigits('NRG.kWh', 1);
        }
        if (!IPS_VariableProfileExists('NRG.Ampere')) {
            IPS_CreateVariableProfile('NRG.Ampere', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileText('NRG.Ampere', '', ' A');
        }

        // Modul-eigene Profile (Präfix OHUB., Verbund-Regel 8): Sitzungs-kWh
        // bewusst NICHT NRG.kWh, damit die MeterHub-Zählersuche den
        // rückspringenden Sitzungswert nicht als Zählerstand aufnimmt.
        if (!IPS_VariableProfileExists('OHUB.kWhSession')) {
            IPS_CreateVariableProfile('OHUB.kWhSession', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('OHUB.kWhSession', '', ' kWh');
            IPS_SetVariableProfileDigits('OHUB.kWhSession', 2);
        }
        if (!IPS_VariableProfileExists('OHUB.ChargePointStatus')) {
            IPS_CreateVariableProfile('OHUB.ChargePointStatus', VARIABLETYPE_INTEGER);
            $captions = [
                0 => 'Verfügbar', 1 => 'Wird vorbereitet', 2 => 'Lädt',
                3 => 'Pausiert (Wallbox)', 4 => 'Pausiert (Fahrzeug)',
                5 => 'Wird abgeschlossen', 6 => 'Reserviert',
                7 => 'Nicht verfügbar', 8 => 'Störung',
            ];
            foreach ($captions as $code => $caption) {
                IPS_SetVariableProfileAssociation('OHUB.ChargePointStatus', $code, $caption, '', -1);
            }
        }
    }

    private function RegisterVariables(): void
    {
        $this->MaintainVariable('power', 'Ladeleistung', VARIABLETYPE_FLOAT, 'NRG.Watt', 0, true);
        $this->MaintainVariable('energy_total', 'Energie gesamt', VARIABLETYPE_FLOAT, 'NRG.kWh', 10, true);
        $this->MaintainVariable('energy_session', 'Energie dieser Ladung', VARIABLETYPE_FLOAT, 'OHUB.kWhSession', 20, true);
        $this->MaintainVariable('state', 'Status', VARIABLETYPE_INTEGER, 'OHUB.ChargePointStatus', 30, true);
        $this->MaintainVariable('vehicle_plugged', 'Fahrzeug angesteckt', VARIABLETYPE_BOOLEAN, '', 40, true);
        $this->MaintainVariable('vehicle_name', 'Zugeordnetes Fahrzeug', VARIABLETYPE_STRING, '', 50, true);

        $this->MaintainVariable('ctl_enable', 'Ladefreigabe', VARIABLETYPE_BOOLEAN, '', 60, true);
        $this->MaintainVariable('ctl_curr_limit', 'Stromlimit', VARIABLETYPE_INTEGER, 'NRG.Ampere', 70, true);
        $this->EnableAction('ctl_enable');
        $this->EnableAction('ctl_curr_limit');

        if ($this->ReadPropertyBoolean('EnableSurplusCharging')) {
            $this->MaintainVariable('surplus_status', 'Überschussladen', VARIABLETYPE_STRING, '', 80, true);
        } else {
            $this->MaintainVariable('surplus_status', 'Überschussladen', VARIABLETYPE_STRING, '', 80, false);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $splitterId = @IPS_GetParent($this->InstanceID);
        $cpid = $this->ReadPropertyString('CPID');

        switch ($Ident) {
            case 'ctl_enable':
                $this->SetValue($Ident, (bool)$Value);
                if ($splitterId > 0 && $cpid !== '') {
                    if ($Value) {
                        OHUB_RemoteStart($splitterId, $cpid);
                    } else {
                        OHUB_RemoteStop($splitterId, $cpid, $this->ReadAttributeInteger('LastTransactionId'));
                    }
                }
                break;

            case 'ctl_curr_limit':
                $clamped = max(
                    $this->ReadPropertyInteger('MinCurrent'),
                    min($this->ReadPropertyInteger('MaxCurrent'), (int)$Value)
                );
                $this->SetValue($Ident, $clamped);
                if ($splitterId > 0 && $cpid !== '') {
                    OHUB_SetCurrentLimit($splitterId, $cpid, (float)$clamped);
                }
                break;
        }
    }

    // ---------------------------------------------------------------------
    // Von OCPPHubSplitter aufgerufene Rückkanal-Funktionen (OHUBL_*).
    // ---------------------------------------------------------------------

    public function UpdateBootInfo(string $vendor, string $model, string $serial): void
    {
        // Stufe 1: nur geloggt, keine eigenen Variablen dafür — Vendor/
        // Model/Serial sind Diagnoseinfo, kein Regel-relevanter Wert.
        $this->SendDebug('OCPPHub Boot', "$vendor / $model / $serial", 0);
    }

    public function UpdateStatus(string $ocppStatus, string $errorCode): void
    {
        if (isset(self::STATUS_MAP[$ocppStatus])) {
            $this->SetValue('state', self::STATUS_MAP[$ocppStatus]);
        }
        if ($errorCode !== '' && $errorCode !== 'NoError') {
            IPS_LogMessage('OCPPHub', 'Ladepunkt ' . $this->InstanceID . ' meldet Fehler: ' . $errorCode);
        }
        $this->SetValue('vehicle_plugged', in_array($ocppStatus, ['Preparing', 'Charging', 'SuspendedEVSE', 'SuspendedEV', 'Finishing'], true));
    }

    public function StartTransaction(int $transactionId, string $idTag, int $meterStartWh): void
    {
        $this->WriteAttributeInteger('LastTransactionId', $transactionId);
        $this->WriteAttributeInteger('MeterStartWh', $meterStartWh);
        $this->SetValue('energy_session', 0.0);
        $this->SendDebug('OCPPHub StartTransaction', "id=$transactionId idTag=$idTag meterStart=$meterStartWh", 0);
    }

    public function StopTransaction(int $transactionId, int $meterStopWh): void
    {
        $meterStart = $this->ReadAttributeInteger('MeterStartWh');
        if ($meterStopWh > $meterStart) {
            $this->SetValue('energy_session', ($meterStopWh - $meterStart) / 1000.0);
        }
        $this->SetValue('ctl_enable', false);
        $this->SendDebug('OCPPHub StopTransaction', "id=$transactionId meterStop=$meterStopWh", 0);
    }

    public function UpdateMeterValues(?float $powerW, ?float $energyWh): void
    {
        if ($powerW !== null) {
            $this->SetValue('power', $powerW);
        }
        if ($energyWh !== null) {
            $this->SetValue('energy_total', $energyWh / 1000.0);
            $meterStart = $this->ReadAttributeInteger('MeterStartWh');
            if ($meterStart > 0 && $energyWh > $meterStart) {
                $this->SetValue('energy_session', ($energyWh - $meterStart) / 1000.0);
            }
        }
    }

    public function GetLastTransactionId(): int
    {
        return $this->ReadAttributeInteger('LastTransactionId');
    }

    // ---------------------------------------------------------------------
    // Backend-Funktionen für Dashboard (Scope-Korrektur 30.08.2026: OCPPHub
    // baut KEINE eigene WebFront-Kachel — Dashboard konsumiert diese
    // Funktionen für die eigentliche Bedienoberfläche, siehe
    // .docs/architektur.md „Bedienung: Backend-Funktion für Dashboard").
    // Bewusst hier auf OCPPHubLadepunkt statt auf dem Splitter — Dashboard
    // braucht dafür nur die eine Ladepunkt-Instanz-ID, wie mit der
    // Dashboard-Sitzung abgestimmt (30.08.2026), keine zusätzliche
    // Splitter-ID-Auflösung.
    // ---------------------------------------------------------------------

    public function ManualStart(int $ZugangID = 0): void
    {
        // Stufe 1: $ZugangID wird noch ignoriert (kein Kundenverwaltung-
        // Vertrag vorhanden) — derselbe Weg wie ein Klick auf ctl_enable,
        // löst intern RemoteStartTransaction über den Splitter aus.
        IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
    }

    public function ManualStop(): void
    {
        IPS_RequestAction($this->InstanceID, 'ctl_enable', false);
    }

    // Tages-Override „heute Vollladen trotz PV-Vorrang" — siehe
    // Update()-Kommentar unten für den automatischen Reset.
    public function SetDailyOverride(bool $Active): void
    {
        $this->WriteAttributeBoolean('DailyOverride', $Active);
        $this->WriteAttributeString('DailyOverrideDate', $Active ? date('Y-m-d') : '');
    }

    // Verbund-Vertrag — ein Eintrag, feldgleich zu CHUB_GetFunctions 1.2
    // (siehe .docs/architektur.md „Vertrag OHUB_GetFunctions"), additiv
    // transport/ocppVersion. NOCH NICHT final mit der EMS-Sitzung
    // gegenprogrammiert.
    public function GetContractEntry(): array
    {
        $managedBy = $this->ReadPropertyString('ManagedBy');
        return [
            'contractVersion'   => '1.0',
            'function'          => 'charger',
            'label'             => $this->ReadPropertyString('Label') ?: IPS_GetName($this->InstanceID),
            'powerID'           => $this->GetIDForIdent('power'),
            'energyImportID'    => $this->GetIDForIdent('energy_total'),
            'measured'          => true,
            'chargeEnableID'    => $this->GetIDForIdent('ctl_enable'),
            'currentLimitID'    => $this->GetIDForIdent('ctl_curr_limit'),
            'plugStateID'       => $this->GetIDForIdent('vehicle_plugged'),
            'minCurrent'        => $this->ReadPropertyInteger('MinCurrent'),
            'maxCurrent'        => $this->ReadPropertyInteger('MaxCurrent'),
            'managedBy'         => $managedBy,
            'externallyManaged' => !in_array($managedBy, ['none', 'ems'], true),
            'vehicleNameID'     => $this->GetIDForIdent('vehicle_name'),
            'transport'         => 'ocpp',
            'ocppVersion'       => '1.6',
        ];
    }

    // ---------------------------------------------------------------------
    // PV-Überschussladen (Fallback ohne EMS) — Logik aus ChargerHub
    // SurplusChargeControl() 0.9.53 portiert, transportunabhängig. Stufe 1:
    // nur EIN Ladepunkt gleichzeitig unterstellt — Splitter-interne
    // Lastverteilung bei mehreren eigenen aktiven Ladepunkten ist TODO
    // (siehe .docs/architektur.md), ebenso Phasenumschaltung.
    // ---------------------------------------------------------------------

    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('EnableSurplusCharging')) {
            return;
        }

        $managedBy = $this->ReadPropertyString('ManagedBy');
        if ($managedBy !== 'none') {
            $this->SetValue('surplus_status', 'Passiv — Regelungshoheit bei: ' . self::MANAGEDBY_LABELS[$managedBy]);
            return;
        }
        if ($this->IsEmsActive()) {
            $this->SetValue('surplus_status', 'Passiv — EMS aktiv');
            return;
        }
        if (!$this->GetValue('vehicle_plugged')) {
            $this->SetValue('surplus_status', 'Kein Fahrzeug angesteckt');
            return;
        }

        // Tages-Override (Dashboard-Backend-Funktion OHUB_SetDailyOverride):
        // ignoriert NUR die Überschussberechnung unten, NICHT die
        // Vorrangkaskade/managedBy-Prüfung oben — ein Override darf nicht
        // zwei Regler gegeneinander ausspielen, siehe .docs/architektur.md
        // „Sicherheits-/Regulatorik-Vorrang". Läuft an einem neuen Kalendertag
        // automatisch ab (OCPPHub verwaltet das selbst, siehe Create()) — kein
        // Cron/Timer auf Dashboard-Seite nötig.
        if ($this->ReadAttributeBoolean('DailyOverride') && $this->ReadAttributeString('DailyOverrideDate') !== date('Y-m-d')) {
            $this->SetDailyOverride(false);
        }
        if ($this->ReadAttributeBoolean('DailyOverride')) {
            $maxCurrent = $this->ReadPropertyInteger('MaxCurrent');
            if (!$this->GetValue('ctl_enable')) {
                IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
            }
            if ($this->GetValue('ctl_curr_limit') !== $maxCurrent) {
                IPS_RequestAction($this->InstanceID, 'ctl_curr_limit', $maxCurrent);
            }
            $this->SetValue('surplus_status', 'Tages-Override aktiv — lädt mit ' . $maxCurrent . ' A');
            return;
        }

        $surplusW = $this->FindGridSurplusW();
        if ($surplusW === null) {
            $this->SetValue('surplus_status', 'Kein Netzzähler gefunden (MeterHub-Vertrag)');
            return;
        }

        // Speicheranteil vor der Ampere-Berechnung abziehen.
        $storageShare = $this->ReadPropertyInteger('StorageSharePercent') / 100.0;
        $surplusW -= $this->GetBatteryChargePowerW() * $storageShare;

        // Eigene Ladeleistung zurückaddieren — sonst Selbstregelschwingung
        // (live erlitten in ChargerHub 0.9.50, siehe architektur.md).
        $surplusW += $this->GetValue('power');

        $phases = 1; // Phasenumschaltung noch nicht implementiert (Stufe 2 TODO)
        $ampere = max(0, $surplusW) / (230 * $phases);
        $minCurrent = $this->ReadPropertyInteger('MinCurrent');
        $maxCurrent = $this->ReadPropertyInteger('MaxCurrent');

        if ($ampere < $minCurrent) {
            if ($this->GetValue('ctl_enable')) {
                IPS_RequestAction($this->InstanceID, 'ctl_enable', false);
            }
            $this->SetValue('surplus_status', sprintf('Kein ausreichender Überschuss (%.0f W)', $surplusW));
            return;
        }

        $clamped = (int)min($maxCurrent, $ampere);
        if (!$this->GetValue('ctl_enable')) {
            IPS_RequestAction($this->InstanceID, 'ctl_enable', true);
        }
        if ($this->GetValue('ctl_curr_limit') !== $clamped) {
            IPS_RequestAction($this->InstanceID, 'ctl_curr_limit', $clamped);
        }
        $this->SetValue('surplus_status', sprintf('Lädt mit %d A (%.0f W Überschuss)', $clamped, $surplusW));
    }

    // Vorrangkaskade: EMS aktiv → passiv. Prüft eine Statusvariable
    // 'Active_State' unter der EMS-Instanz (Ident-Name wie in
    // .docs/architektur.md „Steuerung / Überschussladen" beschrieben,
    // NOCH NICHT gegen eine echte EMS-Instanz verifiziert — dort prüfen,
    // sobald EMS diese Variable tatsächlich exponiert; bis dahin liefert
    // die Funktion bei fehlender Variable sicherheitshalber false, also
    // "EMS nicht aktiv/nicht vorhanden", statt fälschlich zu blockieren).
    private function IsEmsActive(): bool
    {
        $ids = @IPS_GetInstanceListByModuleID(self::EMS_GUID);
        if (!$ids) {
            return false;
        }
        $vid = @IPS_GetObjectIDByIdent('Active_State', $ids[0]);
        if (!$vid) {
            return false;
        }
        $value = @GetValue($vid);
        return is_bool($value) ? $value : (bool)$value;
    }

    // Verbund-Vertrag mit MeterHub (siehe ChargerHub, 26.08.2026 abgestimmt):
    // NIE über den Instanznamen gehen, nur über
    // MHUB_GetFunctions()['assignments'][*]['function'] === 'grid',
    // 'latency' === 'realtime'; 'authority' === 'billing' nur als
    // Tiebreaker. Vorzeichen: + = Bezug, − = Einspeisung → Überschuss =
    // max(0, -wert).
    private function FindGridSurplusW(): ?float
    {
        if (!function_exists('MHUB_GetFunctions')) {
            return null;
        }
        $forcedID = $this->ReadPropertyInteger('SurplusMeterID');
        $candidates = $forcedID > 0 ? [$forcedID] : (@IPS_GetInstanceListByModuleID(self::METERHUB_GUID) ?: []);

        $best = null;
        foreach ($candidates as $iid) {
            $fns = json_decode((string)@MHUB_GetFunctions($iid), true);
            if (!is_array($fns) || !isset($fns['assignments']) || !is_array($fns['assignments'])) {
                continue;
            }
            foreach ($fns['assignments'] as $assignment) {
                if (!is_array($assignment) || ($assignment['function'] ?? '') !== 'grid') {
                    continue;
                }
                if (($assignment['latency'] ?? '') !== 'realtime') {
                    continue;
                }
                $powerID = (int)($assignment['powerID'] ?? 0);
                if ($powerID <= 0) {
                    continue;
                }
                $billing = ($assignment['authority'] ?? '') === 'billing';
                if ($best === null || ($billing && !$best['billing'])) {
                    $best = ['powerID' => $powerID, 'billing' => $billing];
                }
            }
        }
        if ($best === null) {
            return null;
        }
        $value = @GetValue($best['powerID']);
        return is_numeric($value) ? max(0, -(float)$value) : null;
    }

    // Speicher-Ladeleistung über den InverterHub-Vertrag (batPowerID) —
    // negativ = Laden. Rückgabe hier positiv als "aktuelle Ladeleistung",
    // 0 wenn kein Speicher/kein Vertrag.
    private function GetBatteryChargePowerW(): float
    {
        if (!function_exists('IHUB_GetFunctions')) {
            return 0.0;
        }
        foreach (@IPS_GetInstanceListByModuleID(self::INVERTERHUB_GUID) ?: [] as $iid) {
            $fns = @IHUB_GetFunctions($iid);
            if (!is_array($fns) || !isset($fns[0]['batPowerID'])) {
                continue;
            }
            $value = @GetValue($fns[0]['batPowerID']);
            if (is_numeric($value) && $value < 0) {
                return abs((float)$value);
            }
        }
        return 0.0;
    }
}
