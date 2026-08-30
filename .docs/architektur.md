# Architektur-Skizze OCPPHub (Entwurf, 30.08.2026 — zur Abstimmung mit EMS-Sitzung)

Status: ENTWURF aus der ChargerHub-Session. Die OCPPHub-Session prüft, verfeinert und
stimmt den Vertrag mit der EMS-Sitzung ab, BEVOR Idents/Felder festgezurrt werden
(Verbund-Regel 4: veröffentlichte Verträge/Idents sind unumbenennbar).

## Instanzmodell (an das offizielle Symcon-OCPP-Modul und den Verbund angelehnt)

1. **OCPPHub Splitter** (I/O): hält den WebSocket-Server (Symcon Server Socket +
   eigenes OCPP-J-Framing, KEIN externer Daemon), nimmt Verbindungen aller Ladepunkte
   an, routet nach Charge-Point-Identity (URL-Pfad `/<cpid>`), verwaltet zentrale
   RFID-Whitelist + Basic-Auth-Zugangsdaten (Attribute, Regel 7).
2. **OCPPHub Ladepunkt** (Device, 1 je Wallbox/Connector): Variablen analog ChargerHub
   (gleiche Ident-Namen wo semantisch gleich: `power`, `energy_total`, `energy_session`,
   `state`, `vehicle_plugged`, `vehicle_name`, `ctl_enable`, `ctl_curr_limit`,
   `ctl_phase_mode` wo unterstützt, `surplus_status` …) — bewusste Wiederverwendung der
   ChargerHub-Ident-Vokabeln, damit Dashboards/Skripte portabel sind.
3. **OCPPHub Konfigurator**: listet verbundene, noch nicht angelegte Ladepunkte.
4. **OCPPHub Abrechnung** (eine Instanz, optional): Karten-/Nutzerverwaltung
   (idTag ↔ Name ↔ optional Fahrzeug), Tarife, Berichte, CSV-Export.

## OCPP-Nachrichten-Mapping (Kern)

| OCPP (eingehend)      | Wirkung                                                       |
|-----------------------|---------------------------------------------------------------|
| BootNotification      | Registrierung, Vendor/Model/Serial-Variablen, Accepted+Intervall |
| Heartbeat             | Verbindungsüberwachung (`connected`)                          |
| StatusNotification    | `state` (+ ErrorCode-Variable)                                |
| Authorize             | Whitelist-Prüfung (zentral/lokal, wie Symcon-Modul: 5 Modi)   |
| StartTransaction      | Transaktionsbeginn: idTag, MeterStart, TransactionId vergeben |
| StopTransaction       | Abschluss: kWh der Sitzung, Abrechnungs-Datensatz schreiben   |
| MeterValues           | `power`, `energy_total`, Phasenwerte (Measurand-Mapping!)     |

| OCPP (ausgehend)          | Auslöser                                                  |
|---------------------------|-----------------------------------------------------------|
| RemoteStartTransaction    | `ctl_enable` = an (mit internem idTag, wie Symcon: „symcon") |
| RemoteStopTransaction     | `ctl_enable` = aus                                        |
| SetChargingProfile        | `ctl_curr_limit` (TxDefaultProfile, Limit in A oder W — je Wallbox prüfen!) |
| ChangeConfiguration       | u. a. MeterValueSampleInterval hochdrehen (Lehre aus dem Symcon-Modul: sonst „Datenausbeute gering") |
| TriggerMessage            | gezieltes Nachfordern von StatusNotification/MeterValues  |

## Steuerung / Überschussladen

Regelungslogik aus ChargerHub `SurplusChargeControl()` (Stand 0.9.53) portieren — sie ist
transportunabhängig formuliert und braucht nur zwei Stellglieder (`ctl_enable`,
`ctl_curr_limit`) plus Messwerte. Mit zu übernehmende, hart erarbeitete Bausteine:

- NAP-Zähler NUR über MeterHub-Vertrag suchen (`function==='grid'`,
  `latency==='realtime'`, `authority==='billing'` nur als Tiebreaker; NIE über Namen).
  Vorzeichen: + = Bezug, − = Einspeisung → Überschuss = `max(0, -wert)`.
- Speicher-Ladeleistung über `IHUB_GetFunctions()['batPowerID']` zurückaddieren
  (Wechselrichter bedient Verbraucher VOR Speicher; negativ = Laden).
- **Eigene Ladeleistung zurückaddieren** (`power`) — sonst Selbstregelschwingung
  (live erlitten in ChargerHub 0.9.50).
- Einstellbarer Speicheranteil (`StorageSharePercent`) + Speicherkapazität/SOC-abhängige
  Beobachtungsdauer (`BatteryCapacityKWh`, `GetPhaseSwitchStablePolls()`).
- Phasenumschaltung: Start bevorzugt 1-phasig bei wenig Überschuss (Schwelle 1380 W statt
  4140 W), dynamisches Hoch-/Runterschalten mit Hysterese (+2 A) UND Beobachtungszähler
  (3–10 Polls). Über OCPP je nach Wallbox via ChargingProfile (numberPhases) oder
  DataTransfer — pro Hersteller verifizieren.
- Vorrangkaskade unverändert: managedBy ≠ none → passiv; EMS aktiv (echte
  Statusvariablen-Prüfung `EMS_Active_State`) → passiv; Konkurrenzprüfung über andere
  Instanzen MIT angestecktem Fahrzeug (nicht bloß „aktiv").

## Abrechnung (Datenmodell-Entwurf)

- **Transaktion** (persistent, Medium: Archiv + eigene JSON-Attribut-Tabelle oder
  Archiv-Variablen je Ladepunkt): TransactionId, cpid/Connector, idTag, Start-/Endzeit,
  MeterStart/Ende (Wh), kWh, optional Tarifsatz(e) zum Zeitpunkt, Kosten.
- **Karte/Nutzer**: idTag, Anzeigename, optional Fahrzeug (Anknüpfung an
  `vehicle_name`-Mechanik/Dashboard-AssignVehicles), aktiv/gesperrt, gültig bis.
- **Tarif**: fixer ct/kWh ODER dynamisch via `TIBBERGR_GetPriceCurve`
  (function_exists-abgesichert!) — Kosten je Transaktion aus 15-Minuten-Scheiben der
  MeterValues, nicht nur Endsumme (sonst bei dynamischem Tarif falsch).
- **Berichte**: kWh+Kosten je Karte je Monat/Jahr, CSV-Export (Dienstwagen-Nachweis),
  Summen-Variablen je Karte für Dashboard-Kacheln.

## Vertrag `OHUB_GetFunctions`

Feldgleich zu `CHUB_GetFunctions` 1.2 (siehe ChargerHub-README-Feldtabelle) + additiv
z. B. `transport: 'ocpp'`, `ocppVersion`. contractVersion eigenständig ab 1.0. MIT DER
EMS-SITZUNG ABSTIMMEN, bevor irgendetwas veröffentlicht wird.

## Test-Strategie

- Chargepoint-Emulator (apostoldevel/ocpp-cs) für Protokolltests ohne Hardware.
- Live-Test an Dietmars go-e Gemini (FW ≥ 56.8, OCPP per App aktivieren, WSS+Basic-Auth) —
  ACHTUNG: OCPP-Backend-Aktivierung am go-e kann parallele Modbus-/Controller-Regelung
  beeinflussen; vorher mit Dietmar klären, welche Wallbox Testkandidat ist.
- MeterValues-Intervall per ChangeConfiguration auf 10–30 s für die Regelung.

## Offene Fragen an Dietmar / andere Sessions

1. EMS-Sitzung: Vertragsabstimmung `OHUB_GetFunctions` (transport-Feld ok? eigener
   contractVersion-Zähler ok?).
2. Dashboard-Sitzung: Kachel-Konsum transportunabhängig — reicht Feldgleichheit?
3. Dietmar: Welche Wallbox wird OCPP-Testkandidat (WB1 oder WB2)? Repo öffentlich
   schalten, sobald installierbar?
4. Lizenz: wie die anderen NRG-Repos (PolyForm Noncommercial 1.0.0)?
