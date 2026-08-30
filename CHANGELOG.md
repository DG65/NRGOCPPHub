# Changelog

## 0.2.1 (30.08.2026)

**Kritischer Fix** (Dashboard-Diagnose + eigener Live-Zugriff auf Dietmars Instanz):
jede MeterValues-Nachricht ließ den Splitter mit `Fatal error: Cannot use object of
type stdClass as array` abstürzen — betraf JEDE Wallbox, nicht nur die live getestete.
Live gefunden an WB2 (beide Wallboxen inzwischen über OCPP verbunden), Log zeigte den
Absturz alle ~5s bei jeder eingehenden Messung.

Ursache: `json_decode($raw)` ohne den Assoziativ-Parameter lässt verschachtelte
JSON-Objekte als `stdClass` statt als Array durch. Der äußere OCPP-J-Frame ist ein
JSON-Array (dekodiert immer als PHP-Array), aber alles darin Verschachtelte (bei
MeterValues: `meterValue[]`/`sampledValue[]`) blieb `stdClass` — der spätere
`(array)`-Cast auf das payload-Element konvertiert nur die oberste Ebene. Andere
Nachrichten (Authorize/StartTransaction/StopTransaction/BootNotification) haben nur
flache Payloads und liefen deshalb unbemerkt weiter — nur MeterValues hat verschachtelte
Objekte.

Fix: `json_decode($raw, true)` — alles konsequent als Array statt gemischt
Array/stdClass. Das erklärt rückwirkend auch, warum Dashboard bei der OCPP-Wallbox nie
Leistung/Fahrzeugzuordnung zeigte — es lag nie an der Wallbox-Konfiguration oder an
Dashboards Anzeige, sondern daran, dass die Werte serverseitig nie ankamen.

## 0.2.0 (30.08.2026)

**Stufe 2** (siehe `.docs/pflichtenheft.md` „Ausbaustufen"), auf Dietmars Wunsch in
einem Zug gebaut. **UNGETESTET** — im Gegensatz zu Stufe 1 noch nicht live verifiziert.

- **Neue Instanz OCPPHub Abrechnung**: Kunden ↔ Zugänge (Karten) ↔ optional Fahrzeug,
  optional Gruppe, Verbrauchslimits (Woche/Monat/Jahr) je Kunde UND je Gruppe. Wird vom
  Splitter automatisch angelegt (obligatorischer Bestandteil, siehe README/architektur.md).
- **Betriebsart-Auswahlfeld am Splitter** (① Einzelnutzer / ② Mehrere Nutzer) — bei ②
  wird jede Kartenauflage über `OCPPHubAbrechnung::CheckAuthorization()` echt geprüft
  (Zugang aktiv/nicht abgelaufen/Zeitfenster, Kunde aktiv/nicht über Limit), sonst wie
  bisher immer „Accepted". Geprüft sowohl bei Authorize als auch bei StartTransaction.
- **idTag-Direktzuordnung**: bei erfolgreicher Autorisierung mit bekanntem Fahrzeug wird
  `vehicle_name` sofort gesetzt (Vorrang vor Dashboards Zeitkorrelation, wie mit
  ChargerHub/Dashboard abgestimmt).
- **Reservierung**: `OHUBL_Reserve()`/`OHUBL_CancelReservation()` am Ladepunkt, neue
  Variablen `reserved_by`/`reserved_until`. Wirkt unabhängig von der Betriebsart — eine
  aktive Reservierung blockiert jede Kartenauflage mit abweichendem idTag.
- Verbrauch je abgeschlossener Transaktion wird bei Betriebsart ② dem Kunden gutgeschrieben
  (`OHUBA_RecordConsumption`) — Grundlage für die Limit-Prüfung.

Bewusst noch nicht enthalten (Stufe 3): Tarife/Kostenberechnung, Berichte/CSV-Export,
Reservierungsgebühr. Bekannte Vereinfachungen: `reserved_by` zeigt den rohen idTag statt
des Kundennamens; abgelaufene Reservierungen räumen sich erst bei der nächsten
Autorisierungsprüfung auf, nicht per eigenem Timer.

## 0.1.10 (30.08.2026)

Fahrzeug-Zuordnung und SOC nach Rücksprache mit ChargerHub 1:1 nachgebaut (Dietmars
Vorgabe). Kernaussage von ChargerHub: sie machen bewusst sehr wenig, die
Korrelations-Intelligenz liegt absichtlich nicht im Hub.

- `OHUBL_SetVehicleName(int $LadepunktID, string $Name)` — dummer Setter ohne eigene
  Logik, analog `CHUB_SetVehicleName()`. `vehicleNameID` war schon im Vertrag.
- Auto-Löschung von `vehicle_name` beim Abstecken, nur bei tatsächlich erkanntem
  OCPP-Status (dabei nebenbei einen Bug gefixt: `vehicle_plugged` wurde vorher auch bei
  unbekanntem Status fälschlich auf `false` gesetzt).
- Keine eigene Korrelationslogik — das bleibt laut Verbund-Entscheidung ausschließlich
  Dashboards `AssignVehicles()` (Zeitkorrelation beim Anstecken). Dashboard gebeten,
  OCPPHub-Ladepunkte dort mit einzusammeln.
- Kein eigener SOC-Vertrag (wie ChargerHub) — Ausnahme: OCPP kennt den Measurand „SoC"
  in MeterValues, Splitter parst ihn jetzt mit, Ladepunkt hat eine `vehicle_soc`-
  Variable. Bewusst noch NICHT als `vehicleSocID` im Vertrag, bis an echter Hardware
  bestätigt und mit EMS abgestimmt.
- idTag-basierte Direktzuordnung (Vorrang vor Dashboards Zeitkorrelation) als TODO für
  Stufe 2 vermerkt — braucht die noch fehlende Kundenverwaltung.

Details: `.docs/architektur.md` „Fahrzeug-Zuordnung & SOC".

## 0.1.9 (30.08.2026)

**Wichtiger Fix** (Dashboard-Diagnose, direkt an Dietmars laufender Instanz
per Live-Zugriff nachgeprüft): die Zuordnung Ladepunkt↔Splitter lief bisher
über Symcons Objektbaum-Position (`IPS_GetParent()`/`IPS_GetChildrenIDs()`)
statt über eine explizite Property. Da sich Instanzen in der Konsole frei
in andere Kategorien verschieben lassen (bei Dietmar unter „Geräte /
Module" organisiert), konnte die Objektbaum-Position von der tatsächlichen
Splitter-Zugehörigkeit abweichen — live bestätigt: WB1 lag unter einer
fremden Kategorie, `OHUB_GetFunctions()` fand sie nicht mehr (deshalb blieb
Dashboard leer), UND die internen Steuerbefehle (`ctl_enable`/
`ctl_curr_limit`) fanden den Splitter ebenfalls nicht mehr.

- Neues Pflichtfeld „OCPPHub-Splitter" (SelectInstance) am Ladepunkt —
  Property statt Objektbaum-Abfrage, mit Objektbaum-Rückfall für
  Alt-Instanzen.
- Splitter (`findLadepunkt()`, `GetFunctions()`) und Konfigurator suchen
  jetzt über `IPS_GetInstanceListByModuleID()` + Property-Filter statt
  `IPS_GetChildrenIDs()`.
- Konfigurator trägt die Splitter-Zuordnung neu angelegten Ladepunkt-
  Instanzen automatisch mit ein.
- **Bereits angelegte Ladepunkt-Instanzen (z. B. WB1) müssen einmal
  geöffnet und „OCPPHub-Splitter" manuell gesetzt werden**, danach
  funktioniert alles ohne weiteres Zutun.

Außerdem (Dietmar: Formulartexte waren noch zu knapp gegenüber
ChargerHub): alle drei Doku-Panels deutlich ausführlicher — Instanzmodell
im Überblick, Fehlersuche-Anleitung, mehr Kontext zu jedem Feld, Basic-
Auth-Verhalten im Detail.

## 0.1.8 (30.08.2026)

Nachgezogen (Dietmar): zwei weitere fehlende Verbund-Formularkonventionen.

- **„🆕 Neu in Version X.Y"-Banner** (ChargerHub-Muster: `NEWS_VERSION`/
  `NEWS_ITEMS`, aufgeklapptes Panel ganz oben, einmalig ausblendbar,
  Attribut `SeenNews`) in allen drei Modulen ergänzt — fehlte komplett.
- **Herstellerspezifische Hinweise** im Splitter-Doku-Panel ergänzt (go-e,
  KEBA x-series vs. c-series, Alfen Eve, Heidelberg kann kein OCPP) —
  analog ChargerHubs Hersteller-Bullets, aus `.docs/recherche.md`
  übernommen.
- OCPPHub-Konfigurator hatte bisher gar keinen GitHub-Rückmeldungshinweis
  (ReviewHint) — jetzt ergänzt, analog Splitter/Ladepunkt.

Keine funktionalen Änderungen.

## 0.1.7 (30.08.2026)

Nachgezogen (Dietmar: Formulare hatten die nötigen Felder, aber weder die
Verbund-Formularkonventionen noch die ausführlichen Erklärungen wie bei
ChargerHub): alle drei Module bekommen jetzt ein „📖 Dokumentation &
Hilfe"-Panel (eingeklappt, mit Versionsnummer), erklärende Label-Zeilen zu
jedem nicht selbsterklärenden Feld (Zwei-Regler-Warnung, wann
Überschussladen tatsächlich greift, Basic-Auth-Verhalten, Speicheranteil-
Wirkung usw. — analog ChargerHubs Formular) und einen einmalig
ausblendbaren GitHub-Rückmeldungshinweis. Keine funktionalen Änderungen.

## 0.1.6 (30.08.2026)

Fix (Dietmar hinterfragte zu Recht, ob das Überschussladen so funktionieren kann —
ChargerHub-Gegenprüfung am echten Code bestätigte einen sicherheitsrelevanten Fehler):

- **Frische-Problem behoben**: die eigene Ladeleistung (`power`) kam bisher nur
  asynchron rein (Wallbox-eigenes MeterValues-Intervall, bislang faktisch bis zu
  mehreren Minuten), die Überschussregelung rechnete damit mit veralteten/fehlenden
  Werten — reproduziert die Selbstregelschwingung, die die Rückaddierung eigentlich
  verhindern soll. Jetzt: Splitter fordert nach BootNotification per
  `ChangeConfiguration` ein kurzes `MeterValueSampleInterval` (10s) an, Ladepunkt
  regelt zusätzlich ereignisgetrieben bei jedem MeterValues nach (Timer bleibt nur
  Fallback), und setzt bei zu alten Messwerten (>30s) aus statt zu raten.
- **Phasenzahl-Default korrigiert**: `1` → `3` (sicherheitsrelevant — ein zu niedrig
  angenommener Wert hätte zu Netzbezug führen können).
- NaN/Inf-Wache bei MeterValues-Verarbeitung ergänzt.

Details/Begründung: `.docs/architektur.md` „Gegenprüfung mit ChargerHub am echten
Code". Noch offen (vermerkt, nicht sicherheitskritisch): Request/Response-Korrelation
für SetChargingProfile, feineres 1-A-Totband, Cross-Hub-Konkurrenzprüfung mit
ChargerHub.

## 0.1.5 (30.08.2026)

Fix (Dashboard-Fund, während sie die Steuerungs-UI bauen): `OHUB_GetFunctions()`
sammelt die Verträge ALLER eigenen Ladepunkte über den Splitter ein — Dashboards
generische Discovery hätte dadurch fälschlich die Splitter-ID statt der jeweiligen
Ladepunkt-ID für Steuerungsaufrufe (`OHUBL_ManualStart()` etc.) verwendet.
`OHUBL_GetContractEntry()` trägt jetzt zusätzlich `instanceID` (die eigene
Ladepunkt-Instanz-ID) — `OHUB_GetFunctions`-Vertrag additiv auf 1.1 angehoben.

## 0.1.4 (30.08.2026)

Fix (Live-Test-Fund, Dietmar): OCPP-Kernprotokoll läuft bereits sauber
(BootNotification/StatusNotification bestätigt), aber der Konfigurator
blieb leer — "Kein übergeordneter OCPPHub-Splitter verbunden". Ursache: die
Annahme, dass die Konfigurator-Instanz beim Anlegen automatisch mit einer
Splitter-Instanz als Parent verbunden wird, war von Anfang an als
UNGETESTET markiert und hat sich als nicht zuverlässig herausgestellt.
OCPPHub Konfigurator hat jetzt zusätzlich ein explizites Auswahlfeld
"OCPPHub-Splitter" (`SplitterID`-Property), das Vorrang vor der
automatischen Parent-Erkennung hat.

## 0.1.3 (30.08.2026)

Fix (Live-Test-Fund, Dietmar): Debug zeigte, die Anfrage erreicht OCPPHub
korrekt (`GET /hook/ocpphub/16316/WB1`), wird aber mit leerem Nutzernamen
abgelehnt, obwohl der go-e Zugangsdaten sendet. Ursache: `$_SERVER
['PHP_AUTH_USER']`/`['PHP_AUTH_PW']` werden nicht in jeder PHP-/Webserver-
Konfiguration automatisch aus dem `Authorization`-Header befüllt (bekannte
PHP-Falle, u. a. bei bestimmten FastCGI-/Reverse-Proxy-Aufbauten wie im
Docker-Betrieb). Neue `getBasicAuthCredentials()` liest als Fallback den
rohen `Authorization`-Header selbst und dekodiert ihn. Zusätzlich loggt eine
abgelehnte Basic-Auth-Prüfung jetzt auch, ob überhaupt ein
`Authorization`-Header ankam (weitere Diagnosehilfe, falls das Problem
tiefer liegt).

## 0.1.2 (30.08.2026)

Fix (Live-Test-Fund, Dietmar): go-e meldete „Verbindungsautorisierung: nicht
akzeptiert" ohne jede Debug-Ausgabe. Gefundener Fehler in `ApplyChanges()`:
wenn ein Basic-Auth-Passwort gesetzt wurde, verließ die Funktion sich per
`return()` vorzeitig, BEVOR `RegisterHook()`/`SetStatus()` liefen — der Hook
wurde dadurch nie (neu) registriert. Behoben: Hook-Registrierung und Status
laufen jetzt immer zuerst, das Leeren der Passwort-Property danach ist nur
noch ein harmloser Nachlauf. Zusätzlich: ein gesetzter Basic-Auth-Nutzername
ohne (noch) gehashtes Passwort sperrt nicht mehr stillschweigend jede
Verbindung, sondern lässt vorerst durch und loggt sichtbar. Ganz am Anfang
von `ProcessHookData()` zusätzlich eine Debug-Zeile vor jeder Prüfung, damit
künftig erkennbar ist, ob eine Anfrage OCPPHub überhaupt erreicht.

## 0.1.1 (30.08.2026)

Fix (Live-Test-Fund, Dietmar): Instanzanlage des Splitters brach mit `Fatal error:
Call to undefined method OCPPHubSplitter::RegisterHook()` ab —
`RegisterHook()`/`ProcessHookData()` sind KEINE eingebauten `IPSModule`-Methoden,
wie ursprünglich angenommen. Korrigiert auf den echten, gegen zwei offizielle
Symcon-Quellen verifizierten Mechanismus: eigene `RegisterHook()`-Methode trägt
diese Instanz manuell in die `Hooks`-Property der eingebauten
„WebHook Control"-Kern-Instanz ein (`IPS_SetProperty`+`IPS_ApplyChanges`), zusätzlich
Kernel-Ready-Absicherung (`RegisterMessage(IPS_KERNELMESSAGE)`/`MessageSink()`), da
die WebHook-Control-Instanz direkt nach einem Symcon-Neustart noch nicht bereit sein
kann. Betroffen: nur OCPPHub Splitter, Ladepunkt/Konfigurator unverändert.

Auch `OHUB_ManualStart`/`OHUB_ManualStop`/`OHUB_SetDailyOverride` von
OCPPHubSplitter auf OCPPHubLadepunkt verschoben (jetzt `OHUBL_*`, einzige nötige ID
ist die Ladepunkt-Instanz-ID, wie mit Dashboard abgestimmt) — der ursprüngliche
Entwurf hätte Dashboard zusätzlich zur Splitter-ID gezwungen.

## 0.1.0 (30.08.2026)

Erste installierbare Fassung — Stufe 1 laut `.docs/pflichtenheft.md`
(„Ausbaustufen"): Kernprotokoll + PV-Überschussladen, kein RFID-Zwang, keine
Kundenverwaltung/Tarife/Reservierung. **Ungetestet** gegen echte Symcon-
Instanz/Emulator/Wallbox — nächster Schritt ist der Chargepoint-Emulator-Test
(siehe `.docs/architektur.md` „Test-Strategie").

- OCPPHub Splitter: WebSocket-Central-System über einen Symcon-Hook (kein
  externer Daemon), Kernprotokoll-Handler (BootNotification, Heartbeat,
  StatusNotification, Authorize — immer „Accepted" in Stufe 1,
  StartTransaction, StopTransaction, MeterValues), ausgehend
  RemoteStartTransaction/RemoteStopTransaction/SetChargingProfile,
  `OHUB_GetFunctions`-Vertragsentwurf (feldgleich zu `CHUB_GetFunctions` 1.2
  + additiv `transport`/`ocppVersion`, noch nicht final mit der EMS-Sitzung
  abgestimmt).
- OCPPHub Ladepunkt: Ident-Vokabular wie ChargerHub (`power`, `energy_total`,
  `energy_session`, `state`, `vehicle_plugged`, `vehicle_name`, `ctl_enable`,
  `ctl_curr_limit`, `surplus_status`), eigenständiges PV-Überschussladen als
  EMS-loser Fallback (Logik aus ChargerHub `SurplusChargeControl()` 0.9.53
  portiert, transportunabhängig).
- OCPPHub Konfigurator: listet beim Splitter gesehene, noch nicht angelegte
  Charge-Point-Identities zur Ein-Klick-Anlage.
- `LICENSE` (PolyForm Noncommercial 1.0.0, kanonischer Text aus dem
  EMS-Repo), `.tools/check-standalone.php` auf OCPPHub angepasst (war noch
  unangepasste ChargerHub-Kopie).

Absichtlich noch nicht enthalten (siehe Architektur/Pflichtenheft): RFID-
Autorisierung/Kundenverwaltung/Tarife/Verbrauchslimits/Reservierung
(Betriebsart ②/③), Phasenumschaltung, Splitter-interne Lastverteilung bei
mehreren eigenen gleichzeitig aktiven Ladepunkten, Passwortschutz für
Formularabschnitte (StrukturHub-Vertrag), Benachrichtigungen.
