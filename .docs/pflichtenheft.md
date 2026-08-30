# Pflichtenheft OCPPHub

Stand 30.08.2026. Abgeleitet aus `.docs/architektur.md` und `.docs/recherche.md` —
dieses Dokument beschreibt WAS das Modul leisten muss, nicht WIE (das steht weiter in
der Architektur-Skizze). Muss/Soll/Kann-Einstufung ist mein Vorschlag anhand des
bisherigen Gesprächs, keine endgültige Festlegung — bei „alles soll möglich sein" könnte
Dietmar einzelne Soll/Kann-Punkte höher einstufen wollen.

## 1. Zielbestimmung

### 1.1 Musskriterien

- M1: OCPP-1.6J-Central-System in IP-Symcon, WebSocket-basiert, ohne externen Daemon.
- M2: Zentrale, mengenmäßig unbegrenzte RFID-Autorisierung (nicht durch
  Wallbox-Hardwaregrenzen limitiert).
- M3: Obligatorische Abrechnungsfähigkeit (Kunde/Zugang/Fahrzeug/Gruppe-Datenmodell),
  auch wenn im Formular standardmäßig verborgen.
- M4: PV-Überschussladen mit der aus ChargerHub übernommenen, praxiserprobten
  Regelungslogik.
- M5: Vertrag `OHUB_GetFunctions` feldkompatibel zu `CHUB_GetFunctions`, damit EMS/
  Dashboard transportunabhängig konsumieren.
- M6: Formular so gestaffelt, dass der Alleinnutzer ohne Karten-/Abrechnungsbedarf davon
  nichts sieht.
- M7: Lizenz PolyForm Noncommercial 1.0.0, Repo öffentlich sobald installierbar.

### 1.2 Sollkriterien

- S1: Reservierung (`ReserveNow`/`CancelReservation`) für gemeinsam genutzte Wallboxen.
- S2: Mehrere benannte Grundtarife statt einem Festpreis (Preisstruktur ×
  Gültigkeitsbedingung, siehe Architektur).
- S3: Verbrauchslimits je Kunde/Gruppe (Woche/Monat/Jahr).
- S4: Benachrichtigungen bei Limit-Nähe, unbekannter Karte, Verbindungsverlust,
  verpasster Reservierung.
- S5: Ausfallsicherer Betrieb bei kurzzeitigem Symcon-/Verbindungsausfall
  (AuthorizationCache/SendLocalList).
- S6: Backend-Funktionen für manuellen Start/Stop und Tages-Override (Bedienoberfläche
  ist Aufgabe von Dashboard, nicht Teil dieses Moduls — siehe Abgrenzung 1.4).
- S7: Passwortschutz für die Abschnitte Kundenverwaltung/Abrechnung — Vertrag noch
  offen: war als `STRUKT_CheckVaultAccess` bei StrukturHub geplant, dort aber
  wieder entfernt (30.08.2026); wandert in ein eigenständiges neues Modul, Name/Vertrag
  noch nicht bekannt.
- S8: Vollständige Löschbarkeit eines Kunden (Datenschutz), Transaktionshistorie bleibt
  pseudonymisiert erhalten.

### 1.3 Kannkriterien

- K1: Formel-/Skript-Fluchtweg für Preisstruktur/Gültigkeitsbedingung eines Grundtarifs
  (deckt zukünftige, heute nicht bedachte Tarifideen ab).
- K2: Alternative Authentifizierung per Fahrzeug-MAC („Autocharge").
- K3: Variable Reservierungsgebühr, gekoppelt an den zugewiesenen Grundtarif.
- K4: Eigener periodischer Export/Backup der Abrechnungsdaten über Symcons Bordmittel
  hinaus.
- K5: Integration mit einer künftigen rollenbasierten Konsole (externe Abhängigkeit,
  Modul liegt vermutlich außerhalb von OCPPHub).
- K6: OCPP 2.0.1 additiv zu 1.6J.

### 1.4 Abgrenzungskriterien (bewusst NICHT Teil dieses Moduls)

- Eichrecht / geeichte, signierte Zählwerte.
- OCPI/Roaming, Mandantenfähigkeit für fremde Nutzer.
- Bezahlterminal / Ad-hoc-Bezahlung, öffentlicher Stromverkauf.
- Echte rollenbasierte Zugriffskontrolle der Symcon-Konsole (nur einfacher
  Abschnitts-Passwortschutz, siehe S7 — kein Ersatz für eine echte RBAC-Lösung).
- Cross-Modul-Lastmanagement zwischen OCPPHub- und ChargerHub-Ladepunkten ohne aktives
  EMS (explizit EMS' Aufgabe, nicht die von OCPPHub).
- Jede WebFront-Bedienoberfläche (Kachel, Start/Stop-Bedienung, Statusanzeige für
  Endnutzer) — explizit Dashboards Aufgabe (Vorgabe Dietmar, 30.08.2026). OCPPHub
  bleibt reines Backend und stellt dafür nur Backend-Funktionen/Variablen bereit (S6).

## 2. Produkteinsatz

- **Anwendungsbereich**: privater Haushalt/Familie, ggf. Dienstwagen-Nachweis — kein
  gewerblicher Ladepark.
- **Zielgruppe**: IP-Symcon-Betreiber mit einer oder mehreren OCPP-1.6J-fähigen
  Wallboxen (Referenzhardware: go-e Gemini), vom Alleinnutzer bis zur Familie mit
  mehreren Fahrzeugen/Karten.
- **Betriebsbedingungen**: IP-Symcon-Instanz mit Server-Socket-Fähigkeit, Wallbox im
  selben Netz oder per WSS erreichbar, optional MeterHub/InverterHub/TibberGridRewards/
  SteuerboxHub/StrukturHub/EMS als Vertragspartner (alle `function_exists`-abgesichert,
  keine Pflichtabhängigkeit).

## 3. Produktübersicht

Vier bis fünf Instanztypen (siehe Architektur „Instanzmodell"): Splitter (I/O,
WebSocket-Server, zentrale Whitelist), Ladepunkt (1 je Wallbox/Connector), Konfigurator
(Ladepunkt-Erkennung), Abrechnung (obligatorisch, Karten-/Kundenverwaltung, Tarife,
Berichte). Geschwistermodul zu ChargerHub, Vertrag kompatibel zu dessen
`CHUB_GetFunctions`. Reines Backend — keine eigene WebFront-Bedienoberfläche, das
übernimmt Dashboard über die von OCPPHub bereitgestellten Backend-Funktionen (siehe
Abschnitt 4.2, PF10).

## 4. Produktfunktionen

### 4.1 OCPP-Protokoll-Grundfunktionen
- PF1 (M): BootNotification, Heartbeat, StatusNotification verarbeiten.
- PF2 (M): Authorize, StartTransaction, StopTransaction, MeterValues verarbeiten.
- PF3 (M): RemoteStartTransaction, RemoteStopTransaction, SetChargingProfile,
  ChangeConfiguration, TriggerMessage senden.
- PF4 (S): ReserveNow, CancelReservation senden.
- PF5 (S): SendLocalList, ClearCache für den Offline-Fallback pflegen.

### 4.2 Ladesteuerung
- PF6 (M): PV-Überschussladen je Ladepunkt (Speicher-Gegenrechnung, Eigenverbrauch
  zurückaddieren, Phasenumschaltung mit Hysterese).
- PF7 (M): Vorrangkaskade — externe Regelung/EMS aktiv → passiv.
- PF8 (M): Sicherheits-/Regulatorik-Vorrang — §14a/EMS-Notsituation bricht IMMER
  Reservierung/Tarif-Zusage.
- PF9 (S): Splitter-interne Lastverteilung bei mehreren eigenen, gleichzeitig aktiven
  Ladepunkten ohne aktives EMS.
- PF10 (S): Backend-Funktionen `OHUB_ManualStart`/`OHUB_ManualStop`/
  `OHUB_SetDailyOverride` — keine eigene Bedienoberfläche, Dashboard baut die Kachel.

### 4.3 Authentifizierung
- PF11 (M): Zentrale Autorisierung jeder Kartenauflage via `Authorize.req`, unbegrenzte
  Kartenanzahl.
- PF12 (S): Zeitfenster je Zugang (erlaubte Uhrzeiten/Wochentage).
- PF13 (K): Autocharge-MAC als alternativer idTag-Typ.
- PF14 (M): `ConcurrentTx`-Schutz — dieselbe Karte nicht an zwei Ladepunkten gleichzeitig.

### 4.4 Kundenverwaltung
- PF15 (M): Kunde ↔ mehrere Zugänge ↔ optional Fahrzeug ↔ optional Gruppe.
- PF16 (M): Zugang einzeln sperrbar, ohne den ganzen Kunden zu sperren.
- PF17 (S): Kunde vollständig löschbar, Transaktionshistorie bleibt pseudonymisiert.

### 4.5 Verbrauchslimits & Reservierung
- PF18 (S): Limits `maxKwhWeek`/`maxKwhMonth`/`maxKwhYear` je Kunde und je Gruppe,
  restriktiveres Limit gewinnt, laufende Ladung wird nicht unterbrochen.
- PF19 (S): Reservierung eines Ladepunkts für einen Kunden/Zugang mit Zeitfenster.
- PF20 (K): Variable Reservierungsgebühr über den zugewiesenen Grundtarif.

### 4.6 Tarife & Abrechnung
- PF21 (S): Mehrere benannte Grundtarife, je aus Preisstruktur (fix/dynamisch/
  gestaffelt/Grundgebühr) und Gültigkeitsbedingung (Zeitfenster/Saison/Preisschwelle/
  externe Bedingung) zusammengesetzt.
- PF22 (K): Formel-Fluchtweg für Preisstruktur und Gültigkeitsbedingung.
- PF23 (M): Tarif-Kaskade Kunde → Gruppe → Standard, Preisermittlung je
  15-Minuten-Scheibe.
- PF24 (M): Transaktionsprotokoll (kWh, Kosten, Tarifsatz zum Zeitpunkt) — wird immer
  mitgeschrieben, unabhängig von der gewählten Betriebsart.
- PF25 (S): Berichte je Zugang/Kunde/Gruppe/Fahrzeug, Woche/Monat/Jahr, CSV-Export.

### 4.7 Benachrichtigungen
- PF26 (S): Ereignisse bei Limit-Nähe, unbekannter/gesperrter Karte, Verbindungsverlust,
  verpasster Reservierung — Wiederverwendung des EMS-Ereignislisten-Musters prüfen.

### 4.8 Ausfallsicherheit
- PF27 (S): Bei Verbindungsverlust laden bereits autorisierte Karten über den lokalen
  Wallbox-Cache weiter, neue Karten werden abgelehnt.
- PF28 (S): Verbrauchslimits werden während einer Offline-Phase nicht live durchgesetzt,
  nur nachträglich im Bericht vermerkt.

### 4.9 Bedienung & Formular
- PF29 (M): Betriebsart-Auswahlfeld (Einzelnutzer/Mehrere Nutzer/Volle Abrechnung)
  ersetzt einzelne Freischalt-Schalter, keine unmöglichen Zwischenzustände.
- PF30 (M): Herunterstufen der Betriebsart löscht keine Daten.
- PF31 (S): Abschnittsweiser Passwortschutz für Kundenverwaltung/Abrechnung über
  StrukturHub-Vertrag.

### 4.10 Verbund-Konformität
- PF32 (M): Alle Fremdmodul-Aufrufe `function_exists`-abgesichert, kein Modul setzt ein
  anderes voraus.
- PF33 (M): Gemeinsame `NRG.*`-Profile wiederverwendet, modul­eigene Profile mit
  `OHUB.`-Präfix.
- PF34 (M): Alle nutzersichtbaren Texte deutsch.

## 5. Produktdaten

Kernentitäten (Details: Architektur „Abrechnung (Datenmodell-Entwurf)" und
„Tarifmodell"): **Kunde**, **Zugang** (1:N zu Kunde), **Fahrzeug**, **Gruppe**,
**Grundtarif**, **Transaktion**, **Reservierung**. Ladepunkt-seitige Variablen analog
`CHUB_GetFunctions`-Vokabular (`power`, `energy_total`, `state`, `ctl_enable`,
`ctl_curr_limit` …) plus `reserved_by`/`reserved_until`.

## 6. Produktleistungen

- MeterValues-Intervall 10–30 s im Regelbetrieb (per `ChangeConfiguration` gesetzt).
- Autorisierungsentscheidung (`Authorize.conf`) muss innerhalb der von der Wallbox
  erwarteten Timeout-Zeit erfolgen (herstellerabhängig, im Live-Test zu ermitteln).
- Splitter muss mehrere gleichzeitige WebSocket-Verbindungen (mind. so viele wie
  vorhandene Ladepunkte) halten können.

## 7. Benutzungsoberfläche

- Formular-Struktur wie in Abschnitt 4.9 — Basis/Steuerung immer sichtbar, Betriebsart
  staffelt Kundenverwaltung/Abrechnung. Das ist die einzige Bedienoberfläche, die
  OCPPHub selbst stellt (Konfigurationsformular der Konsole) — keine WebFront-Kachel,
  siehe Abgrenzung 1.4.
- Formular-Optik nach NRG-Stack-Konvention („🆕 Neu"-Panel, „📖 Doku & Hilfe", InverterHub
  als Vorbild).
- Alle Anzeige-/Bedientexte deutsch, Fachbegriffe (OCPP, idTag, ChargingProfile …)
  bleiben englisch.

## 8. Qualitätsanforderungen (orientiert an ISO 25010, grob)

- **Funktionalität**: Kernprotokoll (Abschnitt 4.1) vollständig, keine SmartCharging-
  Lücke wie beim offiziellen Symcon-OCPP-Modul.
- **Zuverlässigkeit**: definiertes Offline-Verhalten (PF27/28), kein Datenverlust bei
  Verbindungsabbruch.
- **Benutzbarkeit**: Alleinnutzer nicht überfordert (PF29), Formular-Konvention
  eingehalten.
- **Sicherheit**: keine `eval()`-artige Formelauswertung (K1), Zugangsdaten nur in
  `RegisterAttributeString`, Passwortschutz kein falsches Sicherheitsversprechen (S7
  explizit als unvollständig dokumentiert).
- **Änderbarkeit/Erweiterbarkeit**: Formel-Fluchtweg bei Tarifen (K1) statt starrer
  Aufzählung, damit künftige Anforderungen ohne Architekturbruch reinpassen.
- **Übertragbarkeit**: Vertrag `OHUB_GetFunctions` feldkompatibel zu ChargerHub, EMS/
  Dashboard transportunabhängig.

## 9. Nichtfunktionale Anforderungen

- Lizenz PolyForm Noncommercial 1.0.0 (siehe `LICENSE`).
- Versions-Ritual je Änderung (`library.json`, CHANGELOG.md, `php -l`,
  `check-standalone.php`) vor jedem Commit.
- Während der EMS-Integrationsphase Branch `ems-integration`, `main` bleibt leer bis
  zum ersten Release.
- Repo öffentlich sobald erster installierbarer Code vorliegt.

## 10. Technische Produktumgebung

### 10.1 Software
- IP-Symcon (Server-Socket-fähige Version), PHP-Modul, OCPP 1.6J über WebSocket
  (Subprotokoll `ocpp1.6`).

### 10.2 Schnittstellen zu anderen Modulen (alle optional, `function_exists`-abgesichert)
- **MeterHub** (`function==='grid'`): Netzbezug/-einspeisung für Überschussregelung.
- **InverterHub** (`batPowerID`): Speicher-Ladeleistung gegenrechnen.
- **TibberGridRewards** (`TIBBERGR_GetPriceCurve`): dynamischer Tarif.
- **SteuerboxHub** (Vertrag noch nicht final): §14a-Fenster als externe Tarif-Bedingung.
- **Zugriffsschutz-Modul** (Name/Vertrag noch offen — war `STRUKT_CheckVaultAccess`
  bei StrukturHub geplant, dort 30.08.2026 wieder entfernt, wandert in ein
  eigenständiges neues Modul): Abschnitts-Passwortschutz.
- **EMS** (`EMS_Active_State`, künftig `EMS_GetSpecialEvents`-Muster): Vorrangkaskade,
  ggf. Ereignisliste.
- **ChargerHub** (`CHUB_GetFunctions`-Feldkompatibilität): gemeinsamer Vertrag für
  Dashboard/EMS.

## 11. Anforderungen an die Entwicklungsumgebung

- Verbund-Grundregeln (SUITE.md, siehe CLAUDE.md) gelten uneingeschränkt: kein Modul
  setzt ein anderes voraus, Suchrichtung „Instanzen suchen" nur vom EMS aus, veröffentlichte
  Verträge/Idents werden nicht umbenannt.
- Test-Strategie: Chargepoint-Emulator (apostoldevel/ocpp-cs) vor Live-Hardware,
  Live-Test an WB1 mit dokumentierten Vorbereitungsschritten (siehe Architektur
  „Test-Strategie" und „Offene Fragen", Punkt 3).

## 12. Ausbaustufen (Lieferung)

1. **Stufe 1 (entspricht Betriebsart ①)**: Basis + Steuerung — Kernprotokoll, PV-
   Überschussladen, kein RFID-Zwang. Live-Test-fähig an WB1.
2. **Stufe 2 (Betriebsart ②)**: zentrale Autorisierung, Kundenverwaltung, Limits,
   Reservierung ohne Gebühr.
3. **Stufe 3 (Betriebsart ③)**: Grundtarife, Reservierungsgebühr, Berichte/CSV-Export.
4. **Später, additiv**: OCPP 2.0.1 (K6), Formel-Fluchtweg (K1) falls nicht schon in
   Stufe 3, rollenbasierte Konsolen-Integration (K5, abhängig von externer Entscheidung).

## 13. Ergänzungen

**Glossar** (Auszug): idTag = Identifikationskennung einer Ladeautorisierung (RFID-Karte
o. ä.); ChargingProfile = OCPP-Struktur zur Strom-/Leistungsbegrenzung; CSMS/Central
System = das steuernde Backend (hier: OCPPHub Splitter); EVCC = Electric Vehicle
Communication Controller (fahrzeugseitige Ladekommunikation, Basis für Autocharge-MAC).

**Verweise**: Details und Begründungen zu jedem Punkt stehen in `.docs/architektur.md`;
Marktrecherche/Alternativenvergleich in `.docs/recherche.md`.
