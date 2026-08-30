# Hinweise für die Arbeit an diesem Repository

## Was dieses Modul ist

**OCPPHub**: OCPP-1.6J-Central-System für IP-Symcon — die Wallbox verbindet sich per
WebSocket zu Symcon. Zwei Säulen:

1. **Steuerung**: SmartCharging (`SetChargingProfile` → Stromlimit), RemoteStart/Stop,
   PV-Überschussladen (Regelungslogik aus ChargerHub übernehmen, siehe
   `.docs/architektur.md`), Lastmanagement-Grundlagen.
2. **Abrechnung**: kWh-Summen je RFID-Karte/Nutzer, Berichte (Monat/Jahr, CSV-Export),
   Kostenberechnung mit Tarif (statisch oder dynamisch via TibberGridRewards-Vertrag).
   **Bewusst KEIN Eichrecht, kein OCPI/Roaming, kein Bezahlterminal** — privatrechtliche
   Abrechnung (Haushalt/Familie/Dienstwagen-Nachweis), kein öffentlicher Stromverkauf.

**Bewusst KEIN Ersatz für ChargerHub** (Modbus): Geschwistermodul. Heidelberg z. B. kann
kein OCPP; der Modbus-Weg bleibt für Nur-Lesen-Setups schlanker. Konsumenten (EMS,
Dashboard) sollen idealerweise nicht merken, ob eine Wallbox per Modbus oder OCPP
angebunden ist → Vertrag kompatibel zu `CHUB_GetFunctions` halten (siehe unten).

Vorentscheidungen (Dietmar, 30.08.2026, ChargerHub-Session): OCPP **1.6J zuerst**, 2.0.1
später additiv. Start-Recherche in `.docs/recherche.md` — LESEN, bevor Architekturfragen
neu aufgerollt werden.

## Verwandte Repositories

Teil des NRG-Stack-Modul-Verbunds, an mehreren wird teilweise **gleichzeitig in getrennten
Sitzungen** gearbeitet:

- **OCPPHub** (dieses Repo): Wallboxen per OCPP 1.6J — https://github.com/DG65/NRGOCPPHub
- **ChargerHub**: Wallboxen per Modbus TCP — https://github.com/DG65/NRGChargerHub
- **InverterHub**: Wechselrichter per Modbus TCP — https://github.com/DG65/NRGInverterHub
- **MeterHub**: Energiezähler per Modbus TCP — https://github.com/DG65/NRGMeterHub
- **MigrationsHub**: Migration von Bestandsgeräten — https://github.com/DG65/NRGMigrationsHub
- **EMS**: koordinierende Instanz, künftig einziger zulässiger Konsument aller
  Hub-Verträge — https://github.com/DG65/NRGEMS

## Grundregeln des Verbunds (identisch in den anderen Hub-Repos)

1. **Kein Modul darf ein anderes voraussetzen.** Jeder Aufruf einer fremden Modulfunktion
   (`CHUB*_`, `IHUB*_`, `MHUB*_`, `EMS_`, `TIBBERGR_` …) muss innerhalb derselben Funktion
   durch `function_exists()` abgesichert sein. Prüfung: `php .tools/check-standalone.php`.
2. **Suchrichtung bei „Instanzen suchen": nur vom EMS aus, nie zurück.**
3. **`*_GetFunctions`-Konvention**: Dieses Modul exportiert `OHUB_GetFunctions` mit
   denselben Feldern wie `CHUB_GetFunctions` 1.2 (`function`/`label`/`powerID`/
   `energyImportID`/`energyExportID`/`measured`/`chargeEnableID`/`currentLimitID`/
   `plugStateID`/`minCurrent`/`maxCurrent`/`managedBy`/`externallyManaged`/
   `vehicleNameID`/`contractVersion`), damit EMS/Dashboard transportunabhängig
   konsumieren. Abweichungen nur additiv und mit der EMS-Sitzung abgestimmt.
4. **Ein veröffentlichter Vertrag wird nicht umbenannt.** Gilt auch für Idents.
5. **Sprachregel: alles Nutzersichtbare auf Deutsch.** Details/Stolperfallen: SUITE.md.
   Bezeichner im Code und Fachbegriffe (OCPP, WebSocket, ChargingProfile, idTag …)
   bleiben englisch.
6. **Emojis erwünscht, wo sie Nutzen stiften** (Panel-Icons, Status-Symbole).
7. **Zugangsdaten-Konvention**: OCPP-Basic-Auth-Passwörter der Ladepunkte in
   `RegisterAttributeString` (nicht Property), Eingabe per `PasswordTextBox`, nach
   Übernahme leeren. IP-Symcon verschlüsselt nicht at rest.
8. **Gemeinsame Variablenprofile `NRG.*`** (`NRG.Watt`, `NRG.kWh`, `NRG.Ampere`, …):
   `IPS_VariableProfileExists()` prüfen, nur bei Fehlen anlegen, nie überschreiben.
   Modulspezifische Profile mit Präfix `OHUB.` (Sitzungs-kWh bewusst NICHT `NRG.kWh`,
   damit die MeterHub-Zählersuche rückspringende Werte nicht aufnimmt).
9. **Einheitliche Formular-Optik** (Referenz InverterHub): „🆕 Neu in Version X.Y"-Panel,
   „📖 Dokumentation & Hilfe" mit Versionsnummer, `🆕`-Präfixe, ausblendbare Hinweise per
   Attribut + `UpdateFormField`.

## Verbund-Manifest SUITE.md

Primärquelle aller Verbund-Konventionen: `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS, während der EMS-Integrationsphase Branch
`ems-integration`). Sync-Kopie hier im Root, NIEMALS lokal editieren. Bitte in der
EMS-Sitzung melden, dass dieses Repo in den `sync-suite`-Workflow aufgenommen wird.

## Branch-Regel

Während der EMS-Integrationsphase geht ALLES auf `ems-integration` (Anweisung Dietmar,
verbundweit). `main` bleibt leer/stabil bis zum ersten Release.

## Arbeitsweise (aus ChargerHub übernommen, hart erarbeitet)

- Versions-Ritual je Änderung: `library.json` version+build bumpen, Versions-Caption in
  `GetConfigurationForm()` nachziehen, CHANGELOG.md-Eintrag, `php -l` +
  `php .tools/check-standalone.php` vor jedem Commit.
- IP-Symcon-Stolpersteine (RequestAction-Kernel-Namen, EnableAction nur auf direkte
  Instanzkinder, RegisterVariableX nur bei Neuanlage, Archive-Control-GUID
  `{43192F0B-135B-4CE7-A0A7-1475603F3060}`): Abschnitt „IP-Symcon-Stolpersteine" in
  SUITE.md lesen, bevor Variablen-/Aktions-Code geschrieben wird.
- Archivierung: alle statistisch sinnvollen Datenpunkte per `AC_SetLoggingStatus`
  aktivieren (inkl. regelungsrelevanter Steuerwerte), Referenz `SetArchive()` in
  ChargerHub ab 0.9.53.
