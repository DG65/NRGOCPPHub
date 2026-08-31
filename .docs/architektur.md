# Architektur-Skizze OCPPHub (Entwurf, 30.08.2026 — zur Abstimmung mit EMS-Sitzung)

Status: ENTWURF aus der ChargerHub-Session. Die OCPPHub-Session prüft, verfeinert und
stimmt den Vertrag mit der EMS-Sitzung ab, BEVOR Idents/Felder festgezurrt werden
(Verbund-Regel 4: veröffentlichte Verträge/Idents sind unumbenennbar).

**Leitprinzip Abrechnung/Tarife (Dietmar, 30.08.2026, bestätigt): „wirklich ALLES soll
möglich sein."** Statt Preis-/Bedingungstypen immer weiter einzeln aufzuzählen (nie
vollständig, jede neue Idee bräuchte sonst wieder eine Architekturänderung), gilt ab
hier: vordefinierte Bausteine für die üblichen Fälle + immer ein freier
Formel-/Skript-Fluchtweg für alles nicht Vorgesehene (Details: „Tarifmodell
(Grundtarife)", Abschnitt „Fluchtweg für wirklich jeden Fall"). Dieses Muster gilt als
Vorlage, falls an anderer Stelle im Modul dieselbe Frage aufkommt.

## Instanzmodell (an das offizielle Symcon-OCPP-Modul und den Verbund angelehnt)

1. **OCPPHub Splitter** (I/O): nimmt WebSocket-Verbindungen aller Wallboxen entgegen —
   **Korrektur nach Recherche am echten Mechanismus** (ursprünglich hier als „Symcon
   Server Socket + eigenes OCPP-J-Framing" vermutet, das war falsch): tatsächlich über
   Symcons eingebaute **WebHook-Control**-Kern-Instanz (`RegisterHook()`/
   `ProcessHookData()`/`WC_PushMessage()`, verifiziert gegen zwei offizielle
   Symcon-Quellen, siehe „Verfügbarkeit / Offline-Verhalten" und Splitter-Quellcode-
   Kommentar), KEIN externer Daemon, KEIN eigenes WebSocket-Framing. Routet nach
   Charge-Point-Identity (URL-Pfad hinter dem Hook), verwaltet zentrale RFID-Whitelist +
   Basic-Auth-Zugangsdaten (Attribute, Regel 7).
2. **OCPPHub Ladepunkt** (Device, 1 je Wallbox/Connector): Variablen analog ChargerHub
   (gleiche Ident-Namen wo semantisch gleich: `power`, `energy_total`, `energy_session`,
   `state`, `vehicle_plugged`, `vehicle_name`, `ctl_enable`, `ctl_curr_limit`,
   `ctl_phase_mode` wo unterstützt, `surplus_status` …) — bewusste Wiederverwendung der
   ChargerHub-Ident-Vokabeln, damit Dashboards/Skripte portabel sind. Zusätzlich
   `reserved_by`/`reserved_until` (siehe „Reservierung"). **Scope-Korrektur
   30.08.2026 (Dietmar): keine eigene WebFront-Kachel.** Alles WebFront-Bezogene ist
   Dashboards Aufgabe, OCPPHub bleibt reines Backend — der manuelle Start/Stop-Weg
   (ursprünglich als eigene Kachel gedacht) wird stattdessen als Backend-Funktion
   angeboten, die Dashboard konsumiert (siehe „Bedienung: Backend-Funktion für
   Dashboard"). **FIX 30.08.2026 (Live-Fund, Dashboard-Diagnose + eigene Nachprüfung
   direkt an Dietmars Instanz)**: die Zuordnung Ladepunkt↔Splitter läuft NICHT über
   Symcons Objektbaum-Position (`IPS_GetParent()`/`IPS_GetChildrenIDs()`) — Instanzen
   lassen sich in der Konsole frei in andere Kategorien verschieben (Dietmar
   organisiert seine Instanzen unter „Geräte / Module"), wodurch die Objektbaum-
   Position von der tatsächlichen Splitter-Zugehörigkeit abweichen kann. Live
   bestätigt: WB1 lag unter einer fremden Kategorie, `IPS_GetChildrenIDs()` auf den
   Splitter lieferte leer — sowohl `OHUB_GetFunctions()` als auch die Steuerbefehle
   (`ctl_enable`/`ctl_curr_limit`, die intern denselben Splitter finden müssen) waren
   dadurch faktisch tot. Stattdessen jetzt: explizite Pflicht-Property `SplitterID` am
   Ladepunkt (SelectInstance-Feld im Formular, vom Konfigurator beim Erstellen
   automatisch vorbelegt), Objektbaum-Position bleibt nur Rückfall für Alt-Instanzen.
3. **OCPPHub Konfigurator**: listet verbundene, noch nicht angelegte Ladepunkte.
4. **OCPPHub Abrechnung** (eigene Instanz, **obligatorischer** Bestandteil des Moduls,
   nicht zubuchbar/abwählbar — wird vom Splitter bei Erstanlage automatisch mit
   angelegt): Karten-/Nutzerverwaltung (idTag ↔ Name ↔ optional Fahrzeug), Tarife,
   Berichte, CSV-Export. Korrektur 30.08.2026 (Dietmar): ursprünglich als „optional"
   entworfen, das ist falsch — Abrechnung ist Kernzweck des Moduls, siehe CLAUDE.md.
   „Obligatorisch" heißt: die Instanz/Funktion existiert immer im Modul.
   **Live-Fund 31.08.2026 (Dietmar, Stufe-2-Test)**: `ensureAbrechnung()` im Splitter
   verwendet AUSSCHLIESSLICH seine eigene, per Attribut gemerkte Kind-Instanz — sucht
   NICHT nach anderswo existierenden Abrechnung-Instanzen. Legt jemand manuell (z. B.
   über die Modulverwaltung) eine zweite „OCPPHub Abrechnung"-Instanz an, egal wo im
   Objektbaum, wird diese vom Splitter NIE konsultiert — alle darin gepflegten
   Karten/Kunden bleiben wirkungslos, ohne jede Fehlermeldung (stiller Blindgang). Genau
   das ist Dietmar live passiert: er hatte eine zweite, manuell angelegte Instanz
   gepflegt, während der Splitter seine eigene (leere) Instanz abfragte. Fix: (a)
   Splitter-Formular warnt jetzt explizit davor, manuell eine zweite Instanz anzulegen;
   (b) Abrechnung-Formular prüft beim Aufbau selbst (`hasSplitterParent()`), ob es
   direktes Kind einer OCPPHub-Splitter-Instanz ist, und zeigt sonst ganz oben einen
   unübersehbaren Warnhinweis samt Löschempfehlung.
   **Umsetzung Stufe 2 (30.08.2026)**: Kunden/Zugänge/Fahrzeuge/Gruppen implementiert
   (Tarife/Berichte bleiben Stufe 3). Anders als ursprünglich geplant zeigt diese
   Instanz ihre Felder IMMER (eigene Konsolenseite, kein einblendbares Panel im
   Splitter-Formular — Symcon kennt kein Cross-Instance-Panel-Einblenden) — sie wirkt
   sich aber nur aus, solange am Splitter Betriebsart ② gewählt ist, siehe
   „Formular-Struktur".

## Formular-Struktur (Konfigurationsformular)

**Umsetzung Stufe 2 (30.08.2026) — Anpassung ggü. dem ursprünglichen Entwurf unten**:
Kundenverwaltung lebt als eigene Instanz „OCPPHub Abrechnung" (wird vom Splitter
automatisch angelegt, siehe „Instanzmodell" Punkt 4), NICHT als eingeblendetes Panel
innerhalb des Splitter-Formulars — Symcon hat kein Konzept für „ein anderes Formular
blendet Panels in meinem Formular ein", jede Instanz hat ihre eigene Konsolenseite. Das
**Betriebsart-Auswahlfeld bleibt am Splitter**, schaltet aber nur noch das VERHALTEN
frei (Authorize prüft echt vs. immer Accepted, Limits/Reservierung wirken), nicht mehr
Formular-Panels — die Abrechnung-Instanz existiert und zeigt ihre Felder immer,
unabhängig von der gewählten Betriebsart (wirkt sich nur bei ② tatsächlich aus).
Reservierung ist UNABHÄNGIG von der Betriebsart nutzbar (liegt am Ladepunkt). Der
Rest der ursprünglichen Beschreibung (Reihenfolge Basis/Steuerung/Betriebsart,
Herunterstufen ohne Datenverlust) gilt inhaltlich weiter, nur eben über zwei Instanzen
statt eine verteilt.

Vorgabe 30.08.2026 (Dietmar): der einfache Alleinnutzer (eine Wallbox, kein
Karten-/Abrechnungsbedarf) darf nicht von Kundendatenbank/Tarif-Komplexität erschlagen
werden. Entschieden 30.08.2026: statt drei einzelner, verschachtelt abhängiger Schalter
(Authentifizierung→Kundenverwaltung→Abrechnung) EIN **Betriebsart-Auswahlfeld**, das die
drei Ausbaustufen als benannte Gesamtkonfiguration anbietet — unmögliche Zwischenzustände
(z. B. Kundenverwaltung ohne Authentifizierung) können dadurch gar nicht erst entstehen,
keine Kreuzabhängigkeits-Validierung nötig. „Basis" bleibt immer sichtbar, „Steuerung"
bleibt ein unabhängiger Schalter (auch der Alleinnutzer will ggf. PV-Überschussladen):

1. **Basis** (immer sichtbar, keine Aktivierung nötig): Verbindungsdaten (Charge-Point-ID,
   WS-Pfad, Basic-Auth), Ladepunkt-Zuordnung. Reicht allein zum Laden — OCPP nimmt ohne
   weitere Einstellung jede Transaktion an (kein RFID-Zwang, siehe „Betriebsart" ①).
2. **Steuerung** (Schalter „PV-Überschussladen aktivieren", unabhängig von Betriebsart):
   Regelungsparameter (StorageSharePercent, Phasenumschalt-Schwellen, Vorrangkaskade).
3. **Betriebsart** (Auswahlfeld, ersetzt die vorherigen Einzelschalter „Authentifizierung"/
   „Kundenverwaltung"/„Abrechnung"):
   - **① Einzelnutzer** (Default): kein RFID-Zwang, `Authorize.conf` immer Accepted.
     Nur Basis+Steuerung sichtbar.
   - **② Mehrere Nutzer**: RFID-Pflicht + Kundenverwaltung-Panel erscheint (Kunden mit
     optionaler Gruppe → deren Zugänge/Karten, je optional mit Fahrzeug und optionalem
     Zeitfenster → Gruppen mit optionalen Verbrauchslimits, siehe „Abrechnung
     (Datenmodell-Entwurf)"). Reservierung (`ReserveNow`) wird nutzbar, sobald es
     benannte Kunden/Zugänge gibt. Kein Tarif/Kosten/Bericht.
   - **③ Volle Abrechnung**: zusätzlich **mehrere benannte Grundtarife** (nicht nur ein
     ct/kWh-Wert — z. B. „Nachttarif" an das günstige EnWG-§14a-Fenster gekoppelt,
     „Sonnentarif" an günstige dynamische Preise/Saison gekoppelt, siehe „Tarifmodell
     (Grundtarife)"), Kunde/Gruppe/Standard-Kaskade wählt jetzt einen Grundtarif statt
     eines Festpreises, Berichte, CSV-Export.
   Einfachster Fall innerhalb ② (ein Kunde, eine Karte, kein Fahrzeug, keine Gruppe,
   keine Limits) bleibt genauso schnell ausfüllbar wie vorher — Mehrfach-Zugänge/Gruppen/
   Limits sind Zusatzfelder, kein Pflichtweg.
   **Herunterstufen** (z. B. ③→①) löscht keine Daten — Kunden/Zugänge/Tarife bleiben in
   der Abrechnung-Instanz erhalten, nur die Panels verschwinden und `Authorize.conf`
   springt zurück auf „immer Accepted". Beim Hochstufen sind alte Daten sofort wieder da.

„Obligatorisch" (Instanzmodell Punkt 4) bezieht sich weiterhin nur auf die Instanz/
Funktion im Modul, nicht auf das, was im Formular sichtbar ist — bei Betriebsart ①
zeigt das Formular nichts von alldem.

Passt zur bestehenden NRG-Stack-Formular-Konvention („🆕 Neu"/„📖 Doku"-Panels zuerst,
dann Fachpanels, InverterHub als Vorbild) — hier zusätzlich mit einer einzigen
Ausbaustufen-Auswahl statt mehrerer Einzelschalter.

**Abschnittsschutz Kundenverwaltung/Abrechnung — Vertrag REVERTIERT (StrukturHub,
30.08.2026, Commit `ae8b88f`, StrukturHub jetzt 0.2.2/Build 16): `STRUKT_CheckVaultAccess()`
existiert nicht mehr.** Dietmars Entscheidung: Zugriffsschutz bekommt ein eigenständiges
neues Modul statt in StrukturHub mitzulaufen (sauberer Schnitt statt zweier fachfremder
Aufgaben in einem Modul). **NICHT gegen `STRUKT_CheckVaultAccess()` bauen** — StrukturHub
meldet sich, sobald das neue Modul Namen/Vertrag hat. Das UI-Muster selbst (unten)
bleibt technisch richtig, nur der Aufruf wechselt später von `STRUKT_*` auf das neue
Modul.

UI-Muster, sobald ein Vertrag steht (Platzhalter-Aufruf unten als `<NEUES_MODUL>_*`
markiert, bis Name/Präfix feststehen — IPS lässt ohnehin kein Modul das Formular eines
anderen Moduls von außen steuern, `GetConfigurationForm()` ist immer pro Instanz; der
Zugriffsschutz kann also nur die Frage „ist dieses Passwort richtig" zustandslos
beantworten, „sperren" bleibt OCPPHubs eigene UI):

- Vertrag (Platzhalter): `<NEUES_MODUL>_CheckVaultAccess($vaultInstanceId, string
  $password): bool` (`function_exists`-abgesichert, wie jeder Fremdaufruf im Verbund) —
  reiner Passwort-Vergleich, ohne Nebenwirkung auf die Zielinstanz.
- Eigenes Attribut in OCPPHub (z. B. `SensitiveUnlocked`, bool) steuert, ob
  Kundenverwaltung/Abrechnung normal gerendert werden oder nur ein Passwortfeld +
  „Entsperren"-Button zeigen — Basis/Steuerung bleiben davon unberührt, immer sichtbar.
  „Entsperren" ruft den Vertrag auf, setzt bei Erfolg `SensitiveUnlocked=true` +
  `ReloadForm()`; „Jetzt sperren" spiegelbildlich, aber OHNE `ReloadForm()`, wenn aus dem
  vollen Formular heraus geklickt (SUITE.md-Stolperstein 12 — unbeachtete
  Formulareingaben bei Reload).
- **Ausdrücklicher Vorbehalt (galt schon für den revertierten StrukturHub-Vertrag, gilt
  für das neue Modul genauso)**: das ist KEIN echter Zugriffsschutz — sperrt nur
  Formular-Klicks, nicht direkten Skript-/API-Zugriff auf die Konfiguration, kennt nur
  ein Passwort statt Nutzeridentität. Nicht als alleinige Absicherung für die
  Abrechnungsdaten verkaufen — ergänzt eine mögliche künftige rollenbasierte Konsole
  (siehe „Vermerkte, noch nicht vertiefte Punkte"), ersetzt sie nicht.

## OCPP-Nachrichten-Mapping (Kern)

| OCPP (eingehend)      | Wirkung                                                       |
|-----------------------|---------------------------------------------------------------|
| BootNotification      | Registrierung, Vendor/Model/Serial-Variablen, Accepted+Intervall |
| Heartbeat             | Verbindungsüberwachung (`connected`)                          |
| StatusNotification    | `state` (+ ErrorCode-Variable)                                |
| Authorize             | Zentrale Whitelist-Prüfung, siehe Abschnitt „Authentifizierung“ |
| StartTransaction      | Transaktionsbeginn: idTag, MeterStart, TransactionId vergeben |
| StopTransaction       | Abschluss: kWh der Sitzung, Abrechnungs-Datensatz schreiben   |
| MeterValues           | `power`, `energy_total`, Phasenwerte (Measurand-Mapping!)     |

| OCPP (ausgehend)          | Auslöser                                                  |
|---------------------------|-----------------------------------------------------------|
| RemoteStartTransaction    | `ctl_enable` = an (mit internem idTag, wie Symcon: „symcon"). **`connectorId` PFLICHT** (fest `1`) — go-e lehnt ohne dieses Feld strukturell mit `{"status":"Rejected"}` ab (Live-Bug 31.08.2026, WB2 meldet zwei Connectors: 0 = ganze Wallbox, 1 = tatsächlicher Stecker, ohne Angabe unklar welcher gemeint ist). |
| RemoteStopTransaction     | `ctl_enable` = aus                                        |
| SetChargingProfile        | `ctl_curr_limit` (TxDefaultProfile, Limit in A oder W — je Wallbox prüfen!) |
| ChangeConfiguration       | u. a. MeterValueSampleInterval hochdrehen (Lehre aus dem Symcon-Modul: sonst „Datenausbeute gering") |
| TriggerMessage            | gezieltes Nachfordern von StatusNotification/MeterValues  |
| ReserveNow                | Reservierung anlegen (siehe „Reservierung") |
| CancelReservation         | Reservierung aufheben |
| SendLocalList / ClearCache | Offline-Fallback-Liste pflegen/leeren (siehe „Verfügbarkeit / Offline-Verhalten") |

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
- **Sicherheits-/Regulatorik-Vorrang, hier festgelegt (30.08.2026, keine Ermessensfrage):**
  §14a-Zwangsdimmung (über die externe Bedingung/SteuerboxHub-Vertrag, siehe
  „Tarifmodell") und jede EMS-Notsituation stehen IMMER über einer laufenden
  Reservierung oder einem zugesagten Tarif — eine bezahlte Reservierung ist ein
  finanzielles Versprechen, kein physisches Anrecht auf Netzleistung. Wird eine
  Reservierung/Ladung dadurch eingeschränkt, ist das ein Ereignis (siehe
  „Benachrichtigungen / Ereignisse"), keine technische Blockade der Regelung — eine
  etwaige Kompensation (Gebühr erlassen o. ä.) ist eine spätere geschäftliche
  Entscheidung, kein Architekturthema.

### Gegenprüfung mit ChargerHub am echten Code (Live-Test-Fund, 30.08.2026)

Dietmar hinterfragte zu Recht, ob die reine Doku-Zusammenfassung oben zum Portieren
reicht — Gegenprüfung mit der ChargerHub-Sitzung direkt am Code (0.9.53) ergab einen
echten, sicherheitsrelevanten Fehler plus mehrere Feinheiten, die in der
Zusammenfassung fehlten:

- **Frische-Problem (der eigentliche Fund, jetzt gefixt)**: Bei ChargerHub kommt
  `power` aus einem SYNCHRONEN Modbus-Poll innerhalb desselben `Update()`-Zyklus, der
  auch die Überschussrechnung aufruft — immer frisch. Bei OCPPHub kommt `power`
  dagegen nur asynchron rein, wenn die Wallbox von sich aus eine MeterValues-Nachricht
  schickt. Mit dem ursprünglichen `interval: 300` (fälschlich als MeterValues-Intervall
  verstanden, tatsächlich das Heartbeat-Intervall) und ohne `ChangeConfiguration` hätte
  die Überschussschleife bis zu mehrere Minuten mit veralteter/fehlender eigener
  Ladeleistung gerechnet — reproduziert exakt die Selbstregelschwingung, die die
  Rückaddierung eigentlich verhindern soll, nur mit der Meldeverzögerung der Wallbox
  als Periode statt dem Regelintervall. Behoben (Splitter `requestFastMeterValues()`
  nach BootNotification, Ladepunkt `LastMeterValuesAt`-Frische-Wache in `Update()`,
  ereignisgetriebene Neuberechnung bei jedem MeterValues statt rein zeitgesteuert,
  Timer bleibt Fallback/Watchdog).
- **Phasenzahl-Default korrigiert**: war `1`, jetzt `3` — ChargerHub-Konvention „aus
  `ctl_phase_mode`, wenn vorhanden, sonst 3 annehmen". Sicherheitsrelevant: wird real
  3-phasig geladen und mit 1 gerechnet, kommt ein zu hohes Stromlimit raus →
  Netzbezug. Der umgekehrte Fehler lädt nur konservativer, nie mit Netzbezug.
  `ctl_phase_mode` selbst bleibt Stufe-2-TODO (Phasenumschaltung).
- **NaN/Inf-Wache ergänzt** bei `UpdateMeterValues()` — ChargerHub verwirft
  NaN/Inf-Werte grundsätzlich (dort: Modbus-Füllwerte), bei uns relevant für kaputte
  MeterValues-Payloads.
- **Noch NICHT umgesetzt, vermerkt für später** (siehe „Vermerkte, noch nicht
  vertiefte Punkte"): 1-A-Totband mit Float-Marge vor dem Runden (aktuell nur
  Integer-Vergleich nach dem Cast, eine gröbere Näherung), `floor()` statt Runden ist
  durch den bestehenden `(int)`-Cast bei positiven Werten bereits gegeben, ausstehende
  `SetChargingProfile.conf` abwarten statt bei jedem Zyklus neu zu senden (aktuell nur
  Werteänderung als Schutz vor Spam, keine echte Request/Response-Korrelation),
  Beobachtungszähler-Semantik für die künftige Phasenumschaltung, Cross-Hub-
  Konkurrenzprüfung ChargerHub↔OCPPHub über die `GetFunctions`-Verträge (ChargerHub
  hat angeboten, das spiegelbildlich mitzubauen, sobald wir soweit sind).

### Splitter-interne Lastverteilung (mehrere eigene Ladepunkte gleichzeitig)

Vertiefung 30.08.2026 (Dietmar): bisher ist `SurplusChargeControl()` pro Ladepunkt
gedacht — bei mehreren gleichzeitig aktiven eigenen Ladepunkten (z. B. WB1 UND WB2 laden
im Überschussmodus) würde jeder für sich denselben Überschuss sehen und potenziell
doppelt verplanen (Netzbezug als Folge). Auflösung:

- **Wenn EMS aktiv ist**: kein zusätzliches Problem — die bestehende Vorrangkaskade
  („EMS aktiv → passiv") greift bereits, EMS koordiniert dann global über alle Hubs und
  Ladepunkte hinweg (EMS ist laut CLAUDE.md „künftig einziger zulässiger Konsument aller
  Hub-Verträge" — genau dafür gebaut).
- **Wenn EMS NICHT aktiv ist** (Übergangsphase, oder Betrieb ohne EMS) UND mehr als ein
  **eigener** Ladepunkt gleichzeitig im Überschussmodus lädt: der Splitter berechnet den
  verfügbaren Gesamtüberschuss EINMAL pro Regelzyklus (nicht jeder Ladepunkt für sich)
  und verteilt ihn auf seine aktiven eigenen Ladepunkte — Mindestleistung zuerst
  (z. B. 6 A) je aktivem Punkt, Rest fair anteilig, mit optional konfigurierbarer
  Priorität je Ladepunkt (überschreibt den Fair-Share-Default, z. B. „WB2 immer zuerst
  bedienen").
- **Cross-Hub-Fall bewusst NICHT selbst gelöst** (OCPPHub-Ladepunkt gleichzeitig mit
  einem ChargerHub-Ladepunkt im Überschussmodus, EMS nicht aktiv): zwei getrennte
  Symcon-Modulinstanzen ohne gemeinsamen Speicher können sich nicht zuverlässig
  gegenseitig koordinieren, ohne Verbund-Regel 1 zu verletzen („kein Modul darf ein
  anderes voraussetzen") und ohne EMS' Rolle zu duplizieren. Bewusste Einschränkung,
  keine offene Aufgabe: sauber koordiniertes Lastmanagement über mehrere Hubs hinweg ist
  explizit EMS' Aufgabe, nicht die von OCPPHub im Alleingang.

## Bedienung: Backend-Funktion für Dashboard

**Scope-Korrektur 30.08.2026 (Dietmar): OCPPHub baut KEINE eigene WebFront-Kachel.**
Alles WebFront-Bezogene ist Aufgabe von Dashboard — OCPPHub bleibt hochkonzentriert
Backend. Ursprünglich als eigene Kachel entworfen (siehe Git-Historie dieses
Abschnitts), jetzt umgebaut: OCPPHub stellt die nötige Funktionalität als reine
Backend-Funktion bereit, Dashboard baut die eigentliche Bedienoberfläche darüber.

- `OHUBL_ManualStart(int $LadepunktID, int $ZugangID)` / `OHUBL_ManualStop(int
  $LadepunktID)` — **Korrektur 30.08.2026**: liegen auf der Ladepunkt-Instanz selbst,
  nicht auf dem Splitter (ursprünglich `OHUB_*` mit Splitter als Zielinstanz entworfen —
  hätte Dashboard gezwungen, zusätzlich die Splitter-ID aufzulösen, obwohl genau das
  „keine eigene ID-Auflösung nötig"-Versprechen brechen würde). `$LadepunktID` bleibt
  die einzige nötige ID (bestätigt gegenüber Dashboard, 30.08.2026 — passt direkt in
  deren bestehendes Knoten-/instanceID-Modell). Löst intern denselben Weg aus wie ein
  Klick auf `ctl_enable` (`IPS_RequestAction()`), dahinter wie eine Kartenauflage
  (`Authorize.req`, danach `RemoteStartTransaction`/`RemoteStopTransaction` über den
  Splitter). Bei Betriebsart ① (kein RFID-Zwang) wird `$ZugangID` ignoriert, es wird
  der interne „symcon"-idTag genutzt. Damit ist die Zuordnung unabhängig davon, was bei
  der rollenbasierten Konsole am Ende rauskommt — die eigentliche Zugriffskontrolle
  „wer darf das auslösen" ist Dashboards/WebFronts Berechtigungsfrage, keine von
  OCPPHub zu lösende.
- **Live-Bug 31.08.2026 (Dashboard-Fund, exakter Stacktrace)**: `ArgumentCountError`
  beim manuellen Ladestart über Dashboard/`ctl_enable` — `OCPPHubLadepunkt` rief
  `OHUB_RemoteStart($splitterId, $cpid)` mit nur 2 Argumenten auf, verlassend auf den
  PHP-Standardwert `string $idTag = 'symcon'` im Splitter. Per `ReflectionFunction`
  live verifiziert: **Symcons generierte globale Instanzfunktion ignoriert PHP-
  Standardwerte auf Parametern komplett** — jeder Parameter ist dort zwingend,
  unabhängig vom Default im Quellcode. Fix: dritter Parameter wird jetzt immer explizit
  übergeben, alle PHP-Standardwerte auf öffentlichen Vertragsmethoden (`RemoteStart()`,
  `ManualStart()`) entfernt, um den Fehler nicht zu wiederholen — verbundweit relevanter
  Symcon-Fallstrick, an EMS/SUITE.md gemeldet.
- `OHUBL_SetDailyOverride(int $LadepunktID, bool $Active)` — ebenfalls auf der
  Ladepunkt-Instanz. Tages-Override „heute Vollladen trotz PV-Vorrang", setzt die
  Vorrangkaskade („Steuerung /
  Überschussladen") für die laufende Sitzung außer Kraft. **Korrektur 30.08.2026**
  (Dashboard-Rückfrage zeigte: ein Reset-Cron auf deren Seite wäre unnötige neue
  Infrastruktur für einen Zustand, der ohnehin bei uns liegt): OCPPHub setzt den
  Override SELBST an einem neuen Kalendertag zurück (Datum wird beim Setzen
  mitgespeichert, `Update()` prüft das bei jedem Regelzyklus) — kein Cron/Timer auf
  Dashboard-Seite nötig, ein manueller „Zurücksetzen"-Button dort bleibt trotzdem
  sinnvoll für „schon heute wieder normal". Zieht NICHT den Sicherheits-/
  Regulatorik-Vorrang (§14a/EMS-Notfall) aus „Steuerung / Überschussladen" — der
  bleibt in jedem Fall bestehen.
- Statusdaten (`state`, aktuelles Limit, „Rest bis Limit" bei Verbrauchslimits,
  `reserved_by`/`reserved_until`) liegen bereits als normale Ladepunkt-Variablen vor
  (Instanzmodell Punkt 2) — Dashboard liest sie wie jede andere Wallbox-Variable,
  keine zusätzliche OCPPHub-Funktion nötig.

Dashboard-Sitzung informiert (30.08.2026), dass diese Funktionen so kommen. Offene
Architekturfrage auf Dashboard-Seite (deren Repo beansprucht bisher explizit „keine
Steuerhoheit", Start/Stop/Override wären ihre erste echte Steuerungsaktion) — die
klären sie selbst mit Dietmar, keine Blockade für OCPPHub.

## Verfügbarkeit / Offline-Verhalten

Bisher unspezifiziert, jetzt nachgeholt (30.08.2026): was passiert, wenn Symcon neu
startet oder die WebSocket-Verbindung kurz weg ist, während jemand laden will?
Fail-open (alles erlauben) widerspricht der zentralen Autorisierung; strikt fail-closed
strandet im Zweifel jemanden am eigenen Zuhause-Ladepunkt. Lösung: Standard-OCPP-1.6-
Mechanismus **AuthorizationCache** statt Sonderweg —

- Splitter pflegt eine lokale Zwischenspeicherung der zuletzt getroffenen
  `Authorize.conf`-Entscheidungen je idTag (`AuthorizationCacheEnabled`, Standard-
  OCPP-Konfigurationsschlüssel) UND schickt sie per `SendLocalList` als
  Offline-Fallback-Liste an die Wallbox (soweit die Wallbox das unterstützt — bei go-e
  separat prüfen).
- Ist die OCPPHub-Instanz/Symcon nicht erreichbar: Wallbox greift auf ihre lokale
  Liste/ihren Cache zurück — bereits bekannte, zuletzt akzeptierte Karten laden weiter,
  unbekannte/neue Karten werden abgelehnt, bis die Verbindung zurück ist.
- **Verbrauchslimits während einer Offline-Phase**: können nicht live geprüft werden.
  Wird rückwirkend nach Reconnect abgeglichen (Transaktion war evtl. bereits über dem
  Limit) — es wird NICHT nachträglich abgebrochen/sanktioniert, nur im Bericht sichtbar
  vermerkt („offline autorisiert, Limit-Prüfung nachträglich").
- **Steuerung/PV-Überschuss während Offline-Phase**: keine neuen `SetChargingProfile`-
  Updates möglich — Wallbox behält das zuletzt gesetzte Profil (Standard-OCPP-Verhalten,
  kein Sonderfall nötig).
- Reconnect-Verhalten: nach Wiederverbindung `TriggerMessage` für aktuellen Status
  anfordern, Cache/Local-List neu synchronisieren (`ClearCache` + neu aufbauen, falls
  zwischenzeitlich Kunden/Zugänge geändert wurden).

## Benachrichtigungen / Ereignisse

Ergänzung 30.08.2026 (Dietmar): fehlte bisher komplett. Ereignisse, die eine Meldung
auslösen sollen: Verbrauchslimit fast erreicht (Schwelle konfigurierbar, z. B. 80 %),
unbekannte/gesperrte Karte abgelehnt, Wallbox-Verbindung verloren (Heartbeat-Timeout),
Reservierung nicht angetreten. **Vor eigener Umsetzung prüfen**: EMS führt laut
[[project_ems]] bereits eine Ereignisliste externer Regeleingriffe
(`EMS_GetSpecialEvents`) — Muster ggf. wiederverwenden/andocken statt neu erfinden
(Verbund-Regel: reuse über Erfinden). Falls nicht passend: eigenes `OHUB_GetEvents`
analog aufbauen, damit Dashboard/WebFront das einheitlich abfragen kann. EMS-Sitzung
dazu bei Gelegenheit ansprechen, sobald Umsetzung ansteht (noch nicht dringend).

## Reservierung

**Umgesetzt Stufe 2 (30.08.2026)**: `OHUBL_Reserve(string $IdTag, string $UntilIso):
bool` / `OHUBL_CancelReservation()` am Ladepunkt (Dashboard braucht nur die Ladepunkt-
ID, wie beim Backend-Funktionen-Muster). Splitter vergibt intern die
`reservationId` (`OHUB_ReserveNow`/`OHUB_CancelReservation`, von Dashboard nicht direkt
zu nutzen). **Vereinfachung ggü. dem ursprünglichen Entwurf**: `reserved_by` zeigt
aktuell den rohen idTag, nicht den aufgelösten Kundennamen aus der Abrechnung-Instanz —
spätere Verfeinerung möglich, ohne den Vertrag zu ändern (rein interne Darstellung).
Durchsetzung unabhängig von der Betriebsart: `OCPPHubSplitter::checkIdTag()` prüft die
Reservierung VOR der Betriebsart-abhängigen RFID-Autorisierung. Bekannte Lücke: eine
abgelaufene Reservierung räumt sich nur bei der NÄCHSTEN Autorisierungsprüfung auf
(`GetActiveReservationIdTag()`), nicht durch einen eigenen Timer — `reserved_until`
kann bis dahin kosmetisch veraltet angezeigt bleiben.

Ergänzung 30.08.2026 (Dietmar): echter OCPP-1.6-Kernbefehl (`ReserveNow`/
`CancelReservation`), bisher nicht eingeplant. Sinnvoll bei einer gemeinsam genutzten
Wallbox (Familie, Dienstwagen-Pool): „Ladepunkt ist ab 18 Uhr für mich reserviert."
Nur relevant ab Betriebsart ② (braucht einen bekannten Kunden/Zugang, für den reserviert
wird — bei Einzelnutzer gibt es niemanden, vor dem reserviert werden müsste).

- **Reservierung**-Datensatz: Ladepunkt, Kunde/Zugang, Von-/Bis-Zeitpunkt, Status
  (aktiv/angetreten/abgelaufen/storniert), optional **Gebühr** (siehe unten).
- Bei aktiver Reservierung außerhalb des berechtigten Kunden: `Authorize.conf` = Blocked
  (analog Limit-Prüfung), sichtbar über `reserved_by`/`reserved_until` an der
  Ladepunkt-Instanz (siehe Instanzmodell Punkt 2 und „Bedienung: Backend-Funktion für
  Dashboard").
- Reservierung nicht angetreten (Fahrzeug steckt nicht innerhalb Zeitfenster) → Ereignis
  (siehe „Benachrichtigungen / Ereignisse"), automatisches Verfallen nach Zeitfenster-Ende.
- **Live-Fund 31.08.2026 (isolierter Test ohne Fahrzeug, WB1)**: go-e beantwortet den
  OCPP-Kernbefehl `ReserveNow` mit `CALLERROR "NotImplemented"` — die native OCPP-
  Reservierungsfunktion ist auf dieser Hardware nicht implementiert (deckt sich mit
  go-es eigener Kompatibilitätstabelle: „Reservation: –"). Das ist **unschädlich für
  unsere eigentliche Durchsetzung**: `OCPPHubSplitter::checkIdTag()` blockt eine fremde
  Karte rein serverseitig bei JEDER `Authorize`-Anfrage, unabhängig davon, ob `ReserveNow`
  bei der Wallbox angekommen ist — nur eine etwaige kosmetische Anzeige AN der Wallbox
  selbst (falls das Modell sowas hätte) bliebe aus. Der Fehlschlag wird bereits über die
  bestehende dauerhafte Ablehnungs-Protokollierung sichtbar (siehe „Ladeablehnung
  erklären"). **Noch nicht live verifiziert** (fehlte ein physisches Kartenauflegen zum
  Testzeitpunkt): dass eine tatsächlich aufgelegte fremde Karte während einer aktiven
  Reservierung wirklich mit `Blocked` abgewiesen wird.
- **Reservierungsgebühr** (Ergänzung 30.08.2026, Dietmar): Reservieren kann selbst Geld
  kosten, und zwar variabel je nach Bedingung — genau wie beim Laden (z. B. Reservierung
  während eines günstigen Tarif-Fensters billiger/teurer als außerhalb). Kein eigener
  Preis-Mechanismus, sondern **derselbe Grundtarif, den der Kunde/die Gruppe für kWh
  nutzt, bekommt zusätzlich eine Reservierungsgebühr-Komponente** (siehe „Tarifmodell
  (Grundtarife)") — Gültigkeitsbedingungen (Zeitfenster/Saison/Preisschwelle/externe
  Bedingung) und Fallback-Kaskade gelten identisch, nur bezogen auf den
  Reservierungszeitraum statt auf 15-Minuten-Lade-Scheiben. Die Gebühr ist eine
  eigenständige Kostenposition, unabhängig von eventuellen späteren kWh-Kosten der
  tatsächlichen Ladung, und fällt unabhängig davon an, ob die Reservierung angetreten
  wird (kompensiert die Blockade des Ladepunkts) — nur relevant, wenn Betriebsart ③
  „Volle Abrechnung" aktiv ist; ohne Tarife auch keine Reservierungsgebühr (Reservierung
  selbst bleibt aber schon ab Betriebsart ② nutzbar, dann kostenlos).

## Fahrzeug-Zuordnung & SOC

Dietmars Vorgabe (30.08.2026): alle fahrzeugbezogenen Funktionen von ChargerHub 1:1
übernehmen. Gegenprüfung mit ChargerHub am echten Code (0.9.53) ergab: ChargerHub macht
hier bewusst SEHR wenig — die Zuordnungs-Intelligenz liegt absichtlich NICHT im Hub.

- **`OHUBL_SetVehicleName(int $LadepunktID, string $Name, bool $TimeCorrelated)`** (auf
  der Ladepunkt-Instanz): schreibt `vehicle_name`. Analog `CHUB_SetVehicleName()` (das
  hat kein `$TimeCorrelated` — additiv nur bei uns, siehe „Auto-Autorisierung" unten).
  `vehicleNameID` steht bereits im Vertrag (`GetContractEntry()`). **KEIN Standardwert**
  auf `$TimeCorrelated` (Symcons generierte globale Funktion ignoriert PHP-Standardwerte
  ohnehin, siehe RemoteStart()-Kommentar im Splitter) — jeder Aufrufer (auch Dashboard)
  muss ihn explizit mitgeben.
- **Keine eigene Korrelationslogik** (kein Zeitabgleich, keine Heuristik) — Verbund-
  Entscheidung nach Debatte ChargerHub/Tessie/Dashboard: EIN Korrelationsmechanismus im
  Verbund statt mehrerer konkurrierender. Die Zeitkorrelation beim Anstecken macht
  ausschließlich Dashboards `AssignVehicles()`. Tessie ruft Hubs NICHT direkt auf.
  **Erledigt (Dashboard, 30.08.2026, Commit f737df2)**: `AssignVehicles()` brauchte
  keine Änderung — deren `normalizeDeviceCategory()` mappt `function==='charger'`
  bereits quellenneutral, OCPPHub-Ladepunkte flossen also schon immer in die
  Zeitkorrelation ein. Einzige echte Lücke war der Rücksync des ermittelten
  Fahrzeugnamens (fest auf `CHUB_SetVehicleName()` verdrahtet) — dispatcht jetzt nach
  `$wallbox['transport']`: `'ocpp'` → `OHUBL_SetVehicleName()`, sonst weiter
  `CHUB_SetVehicleName()`, je hinter eigenem `function_exists()`.
- **Auto-Autorisierung ("so etwas wie Autocharge", 31.08.2026, Dietmars Wunsch, Design
  mit Dashboard abgestimmt)**: Dashboard ruft `OHUBL_SetVehicleName()` jetzt auch
  UNABHÄNGIG von einer echten Kartenauflage auf, sobald es per `AssignVehicles()` ein
  Fahrzeug erkennt, mit `$TimeCorrelated=true` NUR bei echter Zeitkorrelation (nicht
  bei deren Ein-Wallbox/Ein-Fahrzeug-Blindzuordnungs-Sonderfall, siehe Dashboards
  Rückmeldung 31.08.2026 — Fehlzuordnungsrisiko dort real, kein Automatismus
  gewünscht). Bei `$TimeCorrelated=true` sucht der Splitter
  (`AutoAuthorizeVehicle()`) über `OHUBA_FindIdTagForVehicleName()` einen passenden
  Zugang und jagt dessen idTag durch DIESELBE `checkIdTag()`-Prüfung wie eine echte
  Kartenauflage (Limits/Zeitfenster/Reservierung/Kunde aktiv gelten identisch, keine
  laxere Sonderlogik). Nur bei Betriebsart ②. 60s-Sperrfrist gegen wiederholte
  Versuche/Log-Spam (`LastAutoAuthAttempt`-Attribut), da Dashboards Aufruf laut deren
  eigener Aussage kein Einmal-Ereignis ist, sondern bei jedem `buildPayload()`-Lauf
  wiederholt (Power-/SoC-Update oder deren 5-Minuten-Timer). Bei Erfolg: echter idTag
  (nicht `'symcon'`) an `RemoteStart()` — `StartTransaction.req` der Wallbox trägt ihn
  zurück, `RecordConsumption()` rechnet den Verbrauch dadurch dem richtigen Kunden zu.
- **Auto-Löschung beim Abstecken**: `vehicle_name` wird geleert, sobald `vehicle_plugged`
  auf `false` geht — NUR bei tatsächlich erkanntem Status (nicht bei unbekanntem OCPP-
  Status-String, sonst würde ein einfach nicht verstandener Status fälschlich als „kein
  Fahrzeug" interpretiert). Bugfix nebenbei gefunden: `UpdateStatus()` setzte
  `vehicle_plugged` vorher IMMER, auch bei unbekanntem Status (dann fälschlich `false`)
  — jetzt nur noch bei erkanntem Status.
  Analog ChargerHubs `Update()`-Verhalten.
- **Direktweg über idTag** (ChargerHub-Empfehlung, **umgesetzt in Stufe 2/0.2.0**): kommt
  eine Transaktion mit einem idTag rein, der in der Kundenverwaltung (OCPPHub Abrechnung)
  einem Fahrzeug zugeordnet ist, ist das eine ECHTE Identifikation statt Zeitkorrelation
  — der Splitter setzt `vehicle_name` dann direkt selbst, statt auf Dashboard zu warten.
  Kein Verstoß gegen die Ein-Mechanismus-Regel (kein Heuristik-Duplikat, sondern Wissen).
  Abstimmung mit Dashboard: idTag-Zuordnung gewinnt gegen Zeitkorrelation — Dashboard
  überschreibt einen bereits gesetzten, nicht-leeren `vehicle_name` nicht.
- **Fahrzeuge-Liste in OCPPHub Abrechnung ↔ Tessie-Verknüpfung (0.2.2)**: Dietmars
  Einwand am Live-Beispiel — die Fahrzeuge-Liste der Abrechnungs-Instanz (Anzeigename/
  Kennzeichen) war rein freihändig getippt und hatte keinen Bezug zu Fahrzeugen, die im
  Verbund über Tessie bereits bekannt sind ("Schneeflocke" #19532, "Kohlekasten"
  #41537). Jede Fahrzeugzeile kann jetzt optional per `SelectInstance` (gefiltert auf
  `TessieVehicle`, GUID `{3F1F7E31-8BA0-4B8F-9B62-47DAD7A0B6C9}`) mit einer echten
  Tessie-Instanz verknüpft werden; ist verknüpft, gilt deren `IPS_GetName()` live als
  Anzeigename (kein zweiter, potenziell veraltender Namensspeicher). Ohne Verknüpfung
  bleibt das manuelle Namensfeld (deckt Fahrzeuge ab, die nicht per Tessie erfasst sind,
  z. B. Fremd-/Firmenfahrzeuge) — bewusst kein Zwang zur Tessie-Abhängigkeit
  (`function_exists` nicht nötig, da nur Symcon-Kernfunktionen `IPS_InstanceExists`/
  `IPS_GetInstance`/`IPS_GetName` verwendet werden, kein Aufruf einer TESSIE_-Funktion).
- **KEIN eigener SOC-Vertrag** (ChargerHub hat auch keinen — Modbus-Wallboxen liefern
  keinen Fahrzeug-SOC, SOC kommt vom Fahrzeug-Modul, z. B. `TESSIE_GetVehicleState`
  contractVersion 1.4, konsumiert von EMS/Dashboard). **Ausnahme, die bei uns anders
  liegt**: OCPP 1.6 kennt den Measurand `SoC` in MeterValues (manche Wallboxen/
  Fahrzeuge übertragen ihn wirklich, v. a. bei DC-Laden/ISO 15118) — Splitter parst das
  bereits mit (`onMeterValues()`), Ladepunkt hat eine `vehicle_soc`-Variable. **Bewusst
  NOCH NICHT im `OHUB_GetFunctions`-Vertrag als `vehicleSocID`** — erst nach Bestätigung,
  dass reale Hardware (WB1) das tatsächlich liefert, UND Abstimmung mit der EMS-Sitzung
  (additives Vertragsfeld). Falls go-e das nicht liefert: Variable bleibt leer,
  unschädlich.
- **„Verbraucherkreis"-Darstellung** (Dietmars Frage): reines Dashboard-Konzept
  (Energiefluss-/Sankey-Darstellung) — ChargerHub zeigt selbst nichts dergleichen an,
  bei uns gilt das erst recht (Scope-Korrektur: keine eigene WebFront-Kachel). Wir
  liefern nur `vehicleNameID` (+ ggf. künftig `vehicleSocID`), die Darstellung ist
  Dashboards Aufgabe.
- **Weitere fahrzeugbezogene Funktionen bewusst NICHT bei uns** (liegen richtig bei
  anderen Modulen, wären Duplikation): Ziel-SOC/Abfahrtszeit über
  `TIBBERGR_SetVehicleSetting` bzw. EMS-Planung, Reichweite über den Tessie-Vertrag,
  RFID-Kartenzähler-Äquivalent ist unsere eigene Kundenverwaltung/Transaktionshistorie
  (Stufe 2/3), nicht ein eigener Direktkanal wie ChargerHubs go-e-MQTT-Kartenzähler.

## Ladeablehnung erklären (Diagnose-Feature, 31.08.2026)

Auslöser: Dietmars Live-Test — go-e lehnte `RemoteStartTransaction` sauber mit
`{"status":"Rejected"}` ab, ein `SetChargingProfile` wurde `Accepted`, blieb aber
wirkungslos (`SuspendedEVSE`, 0 W), ohne dass irgendwo eine Begründung sichtbar war.
Dietmars ausdrücklicher Wunsch: bei einer **eindeutigen** Ablehnung (nicht bei
mysteriösen Netzwerkfehlern) eine echte, nachvollziehbare Erklärung im Dashboard zeigen
statt Rätselraten — dafür bei den betroffenen Nachbarmodulen nachfragen, welche Signale
sie liefern können.

**Vorgehen bewusst offen, nicht nur die eigene Lösungsidee bestätigen lassen**
(Dietmars ausdrückliche Anweisung): Problem beiden Sitzungen breit geschildert statt
nur eng gefragt — beide brachten Erkenntnisse, die die ursprüngliche Idee verbessert
bzw. korrigiert haben.

- **Tibber Grid Rewards, strukturelle Einordnung (nicht nur Datenfrage)**: Tibber
  greift ausschließlich auf der FAHRZEUGSEITE ein (Tesla-/Herstellercloud), spricht
  nicht mit dem Charger. Eine Ablehnung VOR Sitzungsbeginn (wie unser Fall) kann Tibber
  strukturell nicht verursachen — sie könnten höchstens NACH einem erfolgreichen
  Sitzungsstart die Stromabnahme des Fahrzeugs drosseln. `TIBBERGR_GetActiveControls()`
  wird trotzdem als Zusatzinfo abgefragt (kostet nichts, schließt eine Möglichkeit
  aus), aber ausdrücklich nur als „möglicherweise, nicht sicher zuordenbar" markiert.
  `deviceId` in diesem Vertrag ist Tibbers EIGENE `vehicleId`/`batteryId` (auf unsere
  Nachfrage von `0` auf echte Werte nachgebessert, contractVersion 1.0→2.0, deren
  Commit `0cecc66`) — keine Symcon-Instanz-ID, Zuordnung nur unscharf über
  `name`/`make` gegen unseren `vehicle_name` möglich, ohne verlässliche
  Kreuzreferenz. Wichtiger technischer Hinweis von Tibber, der die eigentliche Spur
  zur echten Ursache legt: `SuspendedEVSE` (nicht `SuspendedEV`) bedeutet laut OCPP-1.6,
  dass die STATION selbst keinen Strom anbietet — kein Fahrzeug-/Tibber-Entscheid. In
  Kombination mit dem sauber abgelehnten `RemoteStartTransaction` deutet das eher auf
  eine hängende alte Transaktion auf demselben Connector oder ein go-e-internes
  `AuthorizeRemoteTxRequests`-Erfordernis hin (unabhängig von der App-Einstellung
  „Zugangskontrolle: Offen") — **noch nicht abschließend verifiziert**, TODO beim
  nächsten Live-Test (siehe unten).
- **Tessie, live nachgeschaut statt spekuliert**: `TESSIE_GetVehicleState()` macht
  KEINEN eigenen API-Aufruf (reine Lesung bereits vorhandener lokaler Symcon-
  Variablenwerte) — beliebig oft aufrufbar, kein Tessie-API-Kontingent betroffen.
  `scheduledChargingActive` (Feld liefert bewusst `null`, nicht `false`, bei fehlender
  Telemetrie — strikte `=== true`-Prüfung ist sicher) und `chargeLimit` (echtes
  Telemetriefeld, nicht die frühere veraltete Aktionsvariable) sind verlässliche
  Signale. **Tessies eigener Zusatzfund**: `soc >= chargeLimit` ist ein unabhängiger,
  unterscheidbarer Ablehnungsgrund („Ladelimit erreicht"), den die ursprüngliche Idee
  noch nicht abdeckte. Staleness-Absicherung: `IPS_GetInstance($tessieId)
  ['InstanceStatus'] === 203` heißt „Telemetrie seit über 15 Minuten nicht aktualisiert"
  — bei 203 keine der anderen Feldwerte als sichere Begründung werten. **Live am
  Testfahrzeug ("Kohlekasten") bestätigt**: Telemetrie war 140 Minuten alt trotz aktiv
  gemeldeter WebSocket-Verbindung — typisches Schlafmodus-Muster, würde ALLE
  beobachteten Symptome gleichzeitig erklären (Fahrzeug verhandelt nicht aktiv mit der
  Wallbox). Tessie hat daraufhin `TESSIE_WakeUp($id)` als neue Funktion ergänzt (nutzt
  denselben `/wake`-Endpunkt wie ihre eigene interne `ensureAwake()`, keine neue
  Risikofläche, deren Commit `4e47688`) — bei Status 203 rufen wir das automatisch auf
  (asynchron, kein Block-Warten in der OCPP-Nachrichtenverarbeitung).

**Umsetzung**: additive Kette durch alle drei eigenen Instanzen —
`OHUBA_CheckAuthorization()` liefert zusätzlich `vehicleTessieInstanceId` (0 = keins) →
Splitter merkt sich das bei der idTag-Direktzuordnung am Ladepunkt
(`OHUBL_SetVehicleTessieId()`, Attribut, beim Abstecken zurückgesetzt wie
`vehicle_name`) → bei einer über eine leichte Aufruf-Korrelation (`PendingCalls`-
Attribut am Splitter, uniqueId→Aktion, Einträge >5 Min verworfen) erkannten
eindeutigen Ablehnung von `RemoteStartTransaction`/`SetChargingProfile` ruft der
Splitter `OHUBL_DiagnoseBlockReason()` auf, die neue Ladepunkt-Variable `block_reason`
füllt sich mit der besten verfügbaren Erklärung (leer = keine erkennbare Ursache).
`TESSIE_*`/`TIBBERGR_*` sind ECHTE Fremdmodule (anders als `OHUBA_`/`OHUBL_`) — beide
Aufrufe hinter `function_exists()` abgesichert.

**Teilweise geklärt (31.08.2026)**: `AuthorizeRemoteTxRequests` steht bei WB1 auf
`false` (live per neuer `OHUB_GetConfigurationKeys()`-Funktion verifiziert, Antwort
jetzt korrekt dauerhaft im Systemlog gelandet — der erste Versuch war noch ein Ad-hoc-
Rohbefehl ohne `sendCall()`, lief in dieselbe Sackgasse wie `block_reason` eigentlich
lösen sollte, siehe „Meta-Lehre" in `project_nrgocpphub`-Memory). Damit ist Tibbers
erste Hypothese (go-e verlangt eine zusätzliche Rückfrage-Autorisierung) widerlegt.
**Noch offen**: die zweite Hypothese — eine alte, nicht sauber beendete Transaktion
blockiert Connector 1 — lässt sich nur mit einem echten Ladeversuch bei angestecktem
Fahrzeug klären, nicht per reiner Konfigurationsabfrage.

## Authentifizierung (RFID & Alternativen)

**Umgesetzt Stufe 2 (30.08.2026)**: `OCPPHubAbrechnung::CheckAuthorization(string
$idTag): array` — echte Prüfung (Zugang existiert/aktiv/nicht abgelaufen/Zeitfenster,
Kunde existiert/aktiv/nicht über Limit), aufgerufen von `checkIdTag()` im Splitter bei
Authorize UND StartTransaction (manche Wallboxen überspringen Authorize.req). NUR
wirksam bei Betriebsart ②, siehe „Formular-Struktur". **Vereinfachung ggü. dem
ursprünglichen Entwurf**: Zeitfenster am Zugang nur als Uhrzeit-von-bis (kein
Wochentags-Filter, kein Formel-Fluchtweg wie beim späteren Tarifmodell) — reicht für
Stufe 2, kann additiv erweitert werden.

Klarstellung 30.08.2026 (Dietmar): unbegrenzt viele RFIDs müssen abrechenbar sein, nicht
nur die Handvoll, die eine einzelne Wallbox lokal speichern kann. Recherche-Ergebnis:

- **Zentrale Autorisierung ist Standard-OCPP, kein Sonderweg.** Beim Kartenscan schickt
  die Wallbox ein `Authorize.req` mit dem gelesenen idTag an den Central-System-Server
  (OCPPHub Splitter) und wartet auf `Authorize.conf` (Accepted/Blocked/Expired/Invalid).
  Die Prüfung läuft komplett bei uns in der Karten-/Nutzerverwaltung (Abrechnung-Instanz)
  — die Wallbox selbst muss dafür nichts lokal kennen. Genau deshalb liegt die
  RFID-Whitelist schon im Instanzmodell beim Splitter (Punkt 1), nicht bei der Wallbox.
- **go-e-Bestätigung**: go-e selbst dokumentiert, dass lokal max. 10 RFID-Karten auf dem
  Charger gespeichert werden können (Offline-Fallback ohne Backend-Verbindung), aber über
  Cloud/OCPP-Backend beliebig viele Nutzer zentral verwaltet werden können — deckt sich
  mit dem OCPP-Standardmechanismus oben.
- **Bekannte Firmware-Falle** (GitHub-Issue
  [goecharger/go-eCharger-API-v2#176](https://github.com/goecharger/go-eCharger-API-v2/issues/176)):
  auf FW 055.5 schickt der go-e auch lokal bekannte Karten zur Prüfung an OCPP — korrekt.
  Auf einer 055.7-Beta wurden lokal bekannte Karten dagegen OHNE OCPP-Interaktion
  akzeptiert (vermuteter Bug). **Für den Live-Test relevant**: vor dem Test prüfen, dass
  die WB1-Firmware zentral autorisiert, und die lokale RFID-Liste auf WB1 leer halten
  bzw. die einschlägigen OCPP-Konfigurationsschlüssel (`LocalPreAuthorize`,
  `LocalAuthorizeOffline`, `AuthorizationCacheEnabled` — Standard-OCPP-1.6-Keys) prüfen/
  deaktivieren, damit nichts an OCPPHub vorbei lokal entschieden wird.
- **Alternative Authentifizierung — Fahrzeug-MAC („Autocharge")**: Branchen-Quasi-Standard
  (kein offizieller OCPP-1.6-Kernbestandteil, aber verbreitet, z. B. has·to·be/
  Chargekeeper-Backends), bei dem die Wallbox während des Anstöpsel-Handshakes die
  MAC-Adresse des EVCC (Electric Vehicle Communication Controller, NICHT die
  WLAN/Bluetooth-MAC des Fahrzeugs) als virtuellen idTag im selben `Authorize.req`
  schickt. **Format verifiziert (31.08.2026, Chargekeeper-Spec)**: `VID:` + 12-stellige
  Hex-MAC ohne Trenner, Großschreibung, z. B. `VID:A014310E004E` — Modul unterstützt das
  bereits generisch, ohne jede Sonderbehandlung: `idTag` ist einfach ein
  String/Whitelist-Eintrag, ein Autocharge-Tag ist technisch nur ein weiterer idTag-
  Typ, `Authorize`-Pfad/Datenmodell brauchen dafür keine Änderung.
  **Wichtige Einschränkung, unabhängig vom Wallbox-Fabrikat (31.08.2026, zwei
  unabhängige Quellen: Chargekeeper-Spec „nur DC-Charger mit CCS", emobilitysimplified
  „will only work with CCS-based vehicles")**: Autocharge ist strukturell auf
  DC-Schnellladen (CCS/CHAdeMO) beschränkt — keine der für dieses Modul relevanten
  AC-Heimwallboxen (go-e, Easee, KEBA, Alfen …) wird das je senden, unabhängig vom
  Fabrikat. Das ist also kein go-e-spezifisches Thema, sondern ein Kategorie-Thema
  (AC-Wallbox vs. DC-Schnelllader). Relevanter für die Zukunft ist **ISO-15118
  „Plug & Charge"** (die zertifikatsbasierte Nachfolgetechnik), die zunehmend auch für
  AC-Laden ausgerollt wird — unser generisches `idTag`-Modell würde das genauso ohne
  Änderung aufnehmen. **Hardware-Notiz go-e (nur diese eine Marke)**: aktuelle
  go-eCharger-Geräte haben laut deren Maintainer (GitHub-Diskussion
  `goecharger/go-eCharger-API-v2#182`) noch gar keine ISO-15118-Kommunikationshardware
  (nur PWM-Pulsweitensignale nach IEC 61851) — für WB1/WB2 bleibt darum
  `AssignVehicles()` + idTag-Direktzuordnung der einzig mögliche Weg zur
  Fahrzeugzuordnung (siehe unten), unabhängig vom Autocharge/DC-Thema oben.

### Karte anlernen (Teach-in, 31.08.2026)

Dietmars Anstoß, nachdem er beim Live-Test wieder eine unbekannte idTag von Hand aus
dem Systemlog abtippen musste: *"irgendwie müssen die idTags ja in die Konfiguration
kommen. D.h. wir brauchen eine Sequenz um die idTags anzulernen."*

- `CheckAuthorization()` merkt sich jede idTag, für die `findZugangByIdTag()` `null`
  liefert, in den Attributen `LastUnknownIdTag`/`LastUnknownIdTagAt` — läuft bei jedem
  Kartenauflegen ohnehin schon mit, kostet also nichts Zusätzliches.
- `GetConfigurationForm()` zeigt bei nicht-leerem `LastUnknownIdTag` oben einen
  Hinweisblock (idTag im Klartext + Zeitpunkt) mit Button „Als neuen Zugang
  übernehmen" → `AdoptLastUnknownIdTag()`.
- `AdoptLastUnknownIdTag()` staged eine neue Entwurfszeile (idTag vorausgefüllt, Rest
  leer, `id => 0`) per `UpdateFormField('Zugaenge', 'values', …)` in die Zugänge-Liste
  — **keine Selbstpersistenz im Button** (Verbundregel), erst Dietmars eigenes
  „Übernehmen" im Formular ruft `ApplyChanges()` → `assignIds()` auf und vergibt die
  echte `id`. Klappt dafür den Zugänge-Reiter direkt per `UpdateFormField('expanded'/
  'width', …)` auf, **bewusst OHNE `ReloadForm()`** — das würde das Formular komplett
  neu aus `GetConfigurationForm()` aufbauen und damit die gerade gestagte,
  ungespeicherte Zeile sofort wieder verwerfen (derselbe Formular-Rebuild-Mechanismus,
  den `OnPanelToggle()` für die Reiter-Reihenfolge nutzt — hier bewusst vermieden).
  Kompromiss: der Reiter rückt dabei nicht wie beim normalen Ziehharmonika-Klick ganz
  nach rechts, bleibt aber an seiner Stelle sichtbar aufgeklappt.
- Nach dem Übernehmen wird `LastUnknownIdTag` sofort geleert (Hinweis verschwindet) —
  liegt dieselbe Karte danach nochmal unautorisiert auf, schreibt `CheckAuthorization()`
  sie erneut, kein dauerhafter Datenverlust bei einem Klick ohne anschließendes
  Speichern.

## Abrechnung (Datenmodell-Entwurf)

Erweiterung 30.08.2026 (Dietmar): 1:1 „eine Karte = ein Nutzer" reicht nicht — Kunden
können **mehrere Zugänge** (Karten, künftig Autocharge-MAC, perspektivisch App-Token)
gleichzeitig haben, jeder Zugang optional mit eigenem Fahrzeug/Kennzeichen, dazu
**Gruppen** zur Bündelung — wie bei etablierten Abrechnungsprogrammen (z. B. SteVe, große
CPO-Backends: Account → mehrere Ausweismedien → Fahrzeuge, plus Kostenstellen/Gruppen).
Vierstufiges Modell, alles unterhalb von Betriebsart ② „Mehrere Nutzer" im Formular
(siehe „Formular-Struktur") — Protokoll-Mechanik (`Authorize.req` prüft weiterhin nur den
einzelnen idTag) bleibt davon unberührt, es ändert sich nur unsere Datenorganisation:

- **Kunde**: id, Anzeigename, optionale Notiz/Kontakt, aktiv/gesperrt (sperrt alle seine
  Zugänge auf einen Schlag), optional **eigener Grundtarif** (überschreibt Gruppentarif,
  siehe „Tarifmodell (Grundtarife)"), optionale Zuordnung zu **einer Gruppe**, optionale
  **Verbrauchslimits** (siehe unten). **Löschbar** (nicht nur sperrbar, siehe
  „Löschen/Datenschutz" unten).
- **Zugang** (1:N zu Kunde): idTag (RFID oder künftig Autocharge-MAC — Typ-Feld, technisch
  gleich behandelt, siehe Abschnitt „Authentifizierung"), Anzeigename des Zugangs (z. B.
  „Ersatzkarte", „Firmenwagen-Chip"), aktiv/gesperrt **je Zugang** (verlorene Einzelkarte
  sperren, ohne den ganzen Kunden zu sperren), gültig bis, optionales **Zeitfenster**
  (`allowedFrom`/`allowedTo` als Uhrzeit, optional Wochentags-Auswahl — z. B. Gastkarte
  nur 8–20 Uhr, Kind nur nachmittags; leer = keine Einschränkung), optionale Zuordnung zu
  einem **Fahrzeug**. Keine Obergrenze — Prüfung läuft zentral bei uns, nicht in einer
  begrenzten Wallbox-internen Liste. Zeitfenster wird wie die Verbrauchslimits bei
  `Authorize.req`/`StartTransaction.req` geprüft (außerhalb Fenster → `Blocked`, laufende
  Sitzung wird beim Verlassen des Fensters nicht abgebrochen, analog Limit-Logik).
- **Fahrzeug**: Kennzeichen, Anzeigename, Anknüpfung an `vehicle_name`-Mechanik/
  Dashboard-AssignVehicles. Eigene Entität (nicht nur ein Textfeld am Zugang), weil ein
  Fahrzeug mehrere Zugänge haben kann (Hauptkarte + Ersatzkarte + später Autocharge für
  dasselbe Auto) und Berichte je Fahrzeug sinnvoll sind (Dienstwagen-Nachweis pro KFZ,
  nicht nur pro Karte).
- **Gruppe** (z. B. „Familie", „Dienstwagen-Flotte", „Mitarbeiter"): Name, optionaler
  **Gruppen-Grundtarif** (Fallback-Ebene zwischen Kunden-Tarif und Standardtarif, siehe
  „Tarifmodell (Grundtarife)"), optionale **Verbrauchslimits** (siehe unten, gelten dann
  für die Gruppe in Summe). Rein zur Bündelung für Berichte/Tarife/Limits — kein eigenes
  Ladeverhalten.
- **Löschen/Datenschutz** (Ergänzung 30.08.2026, Dietmar): Kennzeichen + Namen sind
  personenbezogene Daten. Ein Kunde inkl. all seiner Zugänge/Fahrzeug-Zuordnungen muss
  sich **vollständig löschen** lassen (nicht nur sperren), z. B. wenn jemand auszieht.
  Transaktionshistorie (kWh/Kosten, für Dienstwagen-Nachweis ggf. weiter benötigt) bleibt
  dabei erhalten, aber **pseudonymisiert**: die Name-Verknüpfung wird entfernt, die
  idTag-Referenz bleibt technisch bestehen bis auch die Transaktion selbst gelöscht/
  archiviert wird — Buchhaltungsdaten und personenbezogene Stammdaten getrennt löschbar.
- **Verbrauchslimits** (Ergänzung 30.08.2026, Dietmar — wie bei großen
  Abrechnungsprogrammen üblich): je Kunde UND je Gruppe unabhängig voneinander optional
  konfigurierbar — `maxKwhWeek`, `maxKwhMonth`, `maxKwhYear` (alle drei gleichzeitig
  möglich, unabhängige Zeiträume, kein Muss). Zählperiode = Kalenderwoche/-monat/-jahr
  (nicht rollierend, einfacher zu erklären und zu prüfen). Durchsetzung bei jedem
  `Authorize.req`/`StartTransaction.req`: Splitter summiert die kWh der bereits
  abgeschlossenen (+ laufenden) Transaktionen des Kunden in der aktuellen Periode; ist
  irgendein konfiguriertes Limit erreicht (Kunde ODER seine Gruppe — das jeweils
  restriktivere Limit gewinnt), wird die Autorisierung mit `Blocked` beantwortet.
  **Eine bereits laufende Ladung wird nicht unterbrochen**, wenn sie das Limit während
  der Sitzung überschreitet — geprüft wird nur vor dem nächsten Start, nicht mitten in
  einer laufenden Transaktion (vermeidet abruptes Abbrechen einer Ladung).
  Verfügbar unabhängig von Betriebsart ③ „Volle Abrechnung" — reine Mengenbegrenzung,
  braucht keine Tarife, deshalb schon in Betriebsart ② „Mehrere Nutzer" nutzbar.
- **Tarif-Kaskade** je Transaktion: Kunden-eigener Grundtarif → Gruppen-Grundtarif →
  Standard-Grundtarif (siehe „Tarifmodell (Grundtarife)" für die Preisermittlung selbst).
- **Transaktion** (persistent, Medium: Archiv + eigene JSON-Attribut-Tabelle oder
  Archiv-Variablen je Ladepunkt): TransactionId, cpid/Connector, idTag (→ Zugang → Kunde
  auflösbar), Start-/Endzeit, MeterStart/Ende (Wh), kWh, Tarifsatz(e) zum Zeitpunkt,
  Kosten. Wird IMMER mitgeschrieben, auch wenn Betriebsart ③ „Volle Abrechnung" nicht
  gewählt ist (siehe „Formular-Struktur") — nur Tarif/Kosten bleiben dann leer, die
  Verbrauchssumme fürs Limit-Tracking steht trotzdem zur Verfügung.
- **Berichte**: kWh+Kosten je Zugang, aggregiert je Kunde (über alle seine Zugänge), je
  Gruppe, je Fahrzeug — Woche/Monat/Jahr (gleiche Perioden wie die Limits), CSV-Export
  (Dienstwagen-Nachweis), Summen-Variablen je Kunde für Dashboard-Kacheln inkl. „Rest bis
  Limit" wo ein Limit konfiguriert ist.

## Tarifmodell (Grundtarife)

Erweiterung 30.08.2026 (Dietmar): ein einzelner ct/kWh-Wert pro Kunde/Gruppe reicht
nicht — es soll **mehrere benannte Grundtarife** geben. Klarstellung 30.08.2026: „Nacht-
tarif" (§14a-Fenster) und „Sonnentarif" (dynamisch/Saison) waren nur **Beispiele**, kein
abschließendes Anforderungspaar — Ziel ist ein generisches Regelwerk, mit dem sich
**jede** denkbare Tarifidee abbilden lässt, ohne dass dafür Architektur/Code geändert
werden muss. Ein Grundtarif ist eine **eigenständige, global definierte Entität**
(Kunden/Gruppen wählen einen aus der Liste, siehe Tarif-Kaskade oben), zusammengesetzt
aus zwei unabhängigen, beliebig kombinierbaren Teilen — **Preisstruktur** und
**Gültigkeitsbedingung**:

- **Preisstruktur** (was kostet's, wenn der Tarif gilt) — eine der folgenden, auch
  kombinierbar:
  - fix ct/kWh
  - dynamisch via `TIBBERGR_GetPriceCurve` (`function_exists`-abgesichert), optional mit
    Auf-/Abschlag (ct/kWh oder %) auf den Rohpreis
  - **gestaffelt/Mengenstaffel**: unterschiedliche ct/kWh je nach bereits verbrauchter
    Menge in der laufenden Periode (z. B. erste 100 kWh/Monat zu X, darüber zu Y) —
    nutzt dieselbe Perioden-Logik wie die Verbrauchslimits (Kalenderwoche/-monat/-jahr)
  - optionale **Grundgebühr** zusätzlich zum kWh-Preis (fixer Betrag je Periode,
    unabhängig von der Ladeleistung) — für Tarife, die eine Servicepauschale abbilden
    sollen
- **Gültigkeitsbedingung** (wann gilt der Tarif) — eine **Liste** unabhängiger
  Teilbedingungen (nicht nur eine), die alle erfüllt sein müssen (UND-Verknüpfung);
  mehrere Grundtarife mit unterschiedlichen Bedingungen decken ODER-Fälle ab:
  - **Zeitfenster**: beliebig viele Uhrzeit-von-bis-Bereiche (nicht nur einer — z. B.
    „22–06 Uhr UND 12–15 Uhr"), optional auf Wochentage eingeschränkt
  - **Saison**: Monatsbereich (z. B. Mai–September)
  - **Preisschwelle**: Vergleich gegen den dynamischen Preis (z. B. „< X ct/kWh")
  - **Externe Bedingung**: beliebige Symcon-Variable (Boolean oder Zahl + Vergleichs-
    operator) als Freischalter — deckt §14a-Fenster genauso ab wie z. B. eine
    StromGedacht-Ampelfarbe, `EMS_Active_State` oder jeden anderen Hub-Vertrag; IMMER
    über den jeweiligen Modulvertrag abgefragt, NIE über feste Uhrzeiten dupliziert
    (Verbund-Regel: Suchrichtung nur über Verträge, nie über Namen). Beispiel „Nacht-
    tarif"/§14a: laut [[arch-14a-steuerboxhub]] entsteht die §14a-Dimmung als eigenes
    SteuerboxHub-Modul — die externe Bedingung fragt dessen Vertrag ab (Funktionsname
    mit SteuerboxHub-Sitzung abstimmen, sobald der Vertrag steht), statt eigene feste
    Uhrzeiten zu hinterlegen; solange der Vertrag noch nicht steht, Fallback auf ein
    lokal konfiguriertes Zeitfenster (s. o.), später ohne Architekturbruch umstellbar.
- **Preisermittlung je 15-Minuten-Scheibe** (nicht nur einmal pro Transaktion, da sich
  Bedingungen während einer laufenden Ladung ändern können): für jede Scheibe wird
  geprüft, ob der zugewiesene Grundtarif GERADE gültig ist (alle Teilbedingungen
  erfüllt) — wenn ja, dessen Preisstruktur; wenn nein, **Fallback eine Kaskadenstufe
  tiefer** für diese Scheibe (Gruppen-Grundtarif, sonst Standard-Grundtarif). Beispiel:
  ein zeitfenster-gebundener Tarif ist zugewiesen, die Ladung beginnt außerhalb des
  Fensters und läuft hinein — die frühen Scheiben rechnen zum Fallback-Tarif ab, die
  späten zum eigentlichen Tarif.
- Mehrere Grundtarife nur ab Betriebsart ③ „Volle Abrechnung" wählbar (siehe
  „Formular-Struktur") — in ① und ② gibt es keine Kostenberechnung, also keinen Tarif.

**Fluchtweg für wirklich jeden Fall** (Dietmar 30.08.2026, verschärft: „wirklich ALLES
soll möglich sein"): eine Liste konkreter Preis-/Bedingungstypen ist grundsätzlich nie
vollständig — jede weitere Idee (heute Reservierungsgebühr, morgen etwas noch nicht
Gedachtes) würde sonst wieder eine Architektur-/Code-Änderung an OCPPHub erfordern. Statt
die Aufzählung immer weiter zu verlängern, deshalb EIN genereller Mechanismus zusätzlich
zu den vordefinierten Bausteinen oben: **sowohl Preisstruktur als auch
Gültigkeitsbedingung eines Grundtarifs können wahlweise durch einen freien
Formel-/Skriptausdruck ersetzt werden**, ausgewertet gegen einen definierten Kontext
(aktuelle Zeit/Datum/Wochentag, Ladepunkt, Kunde/Gruppe, Fahrzeug, aktueller dynamischer
Preis, bereits verbrauchte kWh/Reservierungsstunden in der Periode, beliebige
Symcon-Variablenwerte per ID). Die vordefinierten Typen bleiben die komfortable Vorlage
für die üblichen Fälle — bei Bedarf ersetzt eine Formel jeden einzelnen Baustein davon,
ohne dass dafür neue Programmierung am Modul nötig wird. Damit ist „alles soll möglich
sein" architektonisch eingelöst statt an einer endlos wachsenden Feature-Liste versucht.
**Sicherheitshinweis**: die Formel darf NICHT per PHP-`eval()` auf rohem String
ausgewertet werden (Code-Injection-Risiko, auch wenn nur lokal von Dietmar befüllt) —
sondern über einen eingeschränkten, sicheren Ausdrucks-Parser (z. B. eine kleine
whitelisted Mathe-/Vergleichs-Grammatik statt echtem PHP), damit ein Formelfeld nicht
versehentlich zu einer Codeausführungs-Hintertür wird.

## Vertrag `OHUB_GetFunctions`

Feldgleich zu `CHUB_GetFunctions` 1.2 (siehe ChargerHub-README-Feldtabelle) + additiv
z. B. `transport: 'ocpp'`, `ocppVersion`. contractVersion eigenständig ab 1.0. MIT DER
EMS-SITZUNG ABSTIMMEN, bevor irgendetwas veröffentlicht wird.

**1.1 (Dashboard-Fund im Live-Test, 30.08.2026): `instanceID` additiv ergänzt.**
`OHUB_GetFunctions()` sammelt (Splitter-Methode) über `IPS_GetChildrenIDs()` die
Verträge ALLER eigenen Ladepunkt-Instanzen ein und gibt sie als eine gemeinsame Liste
zurück — anders als bei ChargerHub (1 Instanz = 1 Wallbox, Splitter-Konzept gibt es
dort nicht) reicht die Splitter-ID als `instanceID` für Konsumenten NICHT: jeder
Eintrag braucht seine EIGENE (Ladepunkt-)Instanz-ID, sonst adressieren
Steuerungsaufrufe wie `OHUBL_ManualStart()` die falsche Instanz. Jeder Eintrag trägt
jetzt `instanceID` = die eigene Ladepunkt-Instanz-ID (`OCPPHubLadepunkt::
GetContractEntry()`). Dashboard liest dieses Feld bevorzugt statt der generischen
Splitter-ID aus ihrer Discovery-Hilfsfunktion.

## Vermerkte, noch nicht vertiefte Punkte

Kurz notiert (30.08.2026), bewusst noch nicht ausgearbeitet — vor der jeweils
betroffenen Umsetzung nachziehen:

- **SetChargingProfile-Korrelation**: ausstehende `.conf`-Antwort abwarten statt bei
  jedem Regelzyklus erneut zu senden (ChargerHub-Empfehlung, siehe „Gegenprüfung mit
  ChargerHub am echten Code"). Aktuell nur durch „nur bei Werteänderung senden"
  behelfsmäßig entschärft, keine echte Request/Response-Korrelationstabelle.
- **1-A-Totband mit Float-Marge**: aktuell nur Integer-Vergleich nach dem `(int)`-Cast
  auf `ctl_curr_limit` — eine gröbere Näherung an ChargerHubs echtes Totband
  (Vergleich vor dem Runden), kann bei Werten nahe einer Ganzzahlgrenze theoretisch
  öfter senden als nötig.
- **Cross-Hub-Konkurrenzprüfung ChargerHub↔OCPPHub**: beide Module sollten sich bei
  Überschussladen gegenseitig als Konkurrenz sehen (gleiche Wallbox ggf. doppelt
  angebunden, oder zwei Wallboxen an zwei Hubs), über die `GetFunctions`-Verträge
  gelöst statt über modul-eigene Instanzlisten. ChargerHub hat angeboten, das
  spiegelbildlich mitzubauen, sobald wir soweit sind — noch nicht angestoßen.
- **Backup/Export der Abrechnungsdaten**: Kunden-/Transaktionsdaten sind jetzt
  steuerrelevant (Dienstwagen-Nachweis). CSV-Export existiert als Berichtsformat, aber
  keine Aussage, ob Symcons eigenes Backup ausreicht oder ein eigener periodischer
  Export (z. B. automatisch monatlich) sinnvoll ist.
- **`ConcurrentTx`**: dieselbe Karte an zwei Ladepunkten gleichzeitig — Standard-
  OCPP-Fall, sollte abgelehnt werden, solange die erste Transaktion des idTags noch
  läuft. Bisher nicht explizit in der Authorize-Logik erwähnt, aber Standardverhalten,
  keine offene Designfrage.
- **Tarif-Historie**: wird ein Grundtarif nachträglich geändert (Preis oder Bedingung),
  bleibt die einzelne Transaktion durch „Tarifsatz zum Zeitpunkt" reproduzierbar — der
  Grundtarif selbst hat aber keine eigene Versionierung. Falls später relevant (z. B.
  Nachvollziehbarkeit bei Rückfragen), dort nachziehen.
- **Rollenbasierte Konsole**: Dietmars Idee, sensible Konsolendaten (Kundenverwaltung/
  Abrechnung) nicht jedem Konsolennutzer gleichermaßen zugänglich zu machen. Vermutlich
  ein eigenständiges, neues Modul statt Teil von OCPPHub — an die StrukturHub-Sitzung als
  Bedarfsmeldung weitergegeben (30.08.2026). StrukturHubs Einschätzung (keine
  Entscheidung): passt konzeptionell nicht zu StrukturHubs bisheriger Identität
  (Objektbaum/Räume), eher ein eigenes Modul (analog SteuerboxHub getrennt vom EMS);
  vorher prüfen, was IP-Symcon nativ an Benutzerrollen/Kategorie-Sichtbarkeit je
  WebFront-Nutzer schon mitbringt. Entscheidung liegt bei Dietmar, noch offen.
  **Update 30.08.2026**: der einfache Abschnitts-Passwortschutz (siehe
  „Formular-Struktur") ist inzwischen aus demselben Grund ebenfalls aus StrukturHub
  entfernt worden und wandert in ein eigenständiges neues Modul — ob das dasselbe Modul
  wie die hier vermerkte rollenbasierte Konsole wird oder ein separates, ist noch nicht
  bekannt (StrukturHub meldet sich, sobald Name/Vertrag stehen). Bis dahin hat OCPPHub
  GAR KEINEN Formular-Zugriffsschutz — nicht gegen einen inzwischen entfernten Vertrag
  bauen.

## Test-Strategie

- Chargepoint-Emulator (apostoldevel/ocpp-cs) für Protokolltests ohne Hardware.
- Live-Test an Dietmars go-e Gemini (FW ≥ 56.8, OCPP per App aktivieren, WSS+Basic-Auth) —
  ACHTUNG: OCPP-Backend-Aktivierung am go-e kann parallele Modbus-/Controller-Regelung
  beeinflussen; vorher mit Dietmar klären, welche Wallbox Testkandidat ist.
- MeterValues-Intervall per ChangeConfiguration auf 10–30 s für die Regelung.
- Vor RFID-Tests auf WB1: lokale RFID-Liste am go-e leer lassen/prüfen und verifizieren,
  dass jede Kartenauflage tatsächlich per `Authorize.req` bei OCPPHub ankommt (Firmware-
  Falle, siehe Abschnitt „Authentifizierung").
- Offline-Fallback testen: WLAN/Symcon kurz trennen, prüfen ob go-e den lokalen Cache/die
  `SendLocalList` tatsächlich nutzt (siehe „Verfügbarkeit / Offline-Verhalten") — falls
  go-e das nicht sauber unterstützt, Fallback-Verhalten neu bewerten.
  Reservierung (`ReserveNow`) am Emulator vor dem WB1-Live-Test prüfen, da hardwareseitig
  unklar, ob go-e das unterstützt.

## Offene Fragen an Dietmar / andere Sessions

Stand 30.08.2026 — alle vier Punkte geklärt:

1. EMS-Sitzung (30.08.2026): **keine Einwände.** `OHUB_GetFunctions` 1.0 feldgleich zu
   `CHUB_GetFunctions` + additiv `transport`/`ocppVersion`, eigener unabhängiger
   `contractVersion`-Zähler entspricht der Verbundregel (eigener Major.Minor-Zähler je
   Datenschnittstelle, SUITE.md „Vertragsversion"). EMS konsumiert OCPPHub als weitere
   Wallbox-Quelle, **sobald der Vertrag veröffentlicht ist** — dann dort melden, damit
   die Discovery-Seite gebaut wird. OCPPHub wurde bereits in `sync-suite.yml`
   aufgenommen (Commit `bfd42f8` auf `ems-integration` im EMS-Repo, Platzhalter-Eintrag
   in der Manifest-Tabelle) — SUITE.md-Root-Kopie hier synct beim nächsten Push.
2. Dashboard-Sitzung (30.08.2026): **reine Feldgleichheit reicht.** Dashboard konsumiert
   über eine generische `discoverListContract()`-Hilfsfunktion (gleiches Muster wie
   MeterHub/HeishaMon/Tessie), liest nur `function/label/powerID/measured/plugStateID/
   plugOp/plugVal`, rührt keine Steuer-Idents an (reine Darstellungsschicht), pollt
   selbst nicht (MessageSink auf Variablen-Updates) — Timing hängt komplett an unserer
   Instanz. Zusatzfelder wie `transport`/`ocppVersion` unproblematisch
   (`normalizeEntry()` reicht unbekannte Keys durch).
   **Beobachtung (kein Blocker, im Blick behalten):** `AssignVehicles()` korreliert
   Wallbox↔Fahrzeug über die zeitliche Nähe zweier „verbunden"-Meldungen
   (`plugStateID`-Wechsel). Falls OCPP das Verbunden-Signal spürbar verzögert liefert
   (z. B. erst nach StatusNotification-Handshake statt sofort bei Steckkontakt), könnte
   die Korrelation ungenauer werden als bei Modbus-Wallboxen. Mit realen Timing-Daten
   aus dem Live-Test verifizieren.
3. Dietmar: Testkandidat WB1/WB2 → **WB1** (Empfehlung ChargerHub-Sitzung, 30.08.2026,
   11:49). WB1 (#52957) ist im Leerlauf: kein Fahrzeug, Ladefreigabe aus, Überschussladen
   deaktiviert, `managedBy='none'` — Test stört nichts. WB2 (#30324) ist Dietmars aktiv
   genutzte Box mit angestecktem Fahrzeug, Überschussladen aktiv, `managedBy='other'`
   (externe Regelung, vermutlich Tibber/go-e Controller) — Kollisionsrisiko.
   Vor Live-Test an WB1 zu erledigen:
     a. In der ChargerHub-Instanz WB1 „Wer regelt diesen Ladepunkt?" auf „Anderer"
        stellen, damit `CHUB_GetFunctions` für WB1 `managedBy='other'`/
        `externallyManaged=true` meldet und EMS/Überschusslogik dort zurückweicht.
     b. Modbus an WB1 parallel aktiv lassen (`men=true`) — ChargerHub liest weiter mit,
        gut für Kreuzvalidierung OCPP-MeterValues gegen Modbus-Register.
     c. go-e-Firmware auf WB1 prüfen: OCPP braucht ≥56.1, besser ≥56.8.
   ChargerHub-Sitzung bei Live-Gang informieren, sie beobachtet von ihrer Seite mit.
   Repo öffentlich schalten → **erledigt 30.08.2026**, nach dem ersten installierbaren
   Commit (`b061672`, Stufe 1) auf public gestellt, analog den anderen NRG-Stack-Repos.
4. Lizenz → **PolyForm Noncommercial 1.0.0** (bestätigt 30.08.2026), kanonischer Text
   aus dem EMS-Repo übernommen, liegt jetzt als `LICENSE` im Root.
