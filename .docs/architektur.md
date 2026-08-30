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

1. **OCPPHub Splitter** (I/O): hält den WebSocket-Server (Symcon Server Socket +
   eigenes OCPP-J-Framing, KEIN externer Daemon), nimmt Verbindungen aller Ladepunkte
   an, routet nach Charge-Point-Identity (URL-Pfad `/<cpid>`), verwaltet zentrale
   RFID-Whitelist + Basic-Auth-Zugangsdaten (Attribute, Regel 7).
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
   Dashboard").
3. **OCPPHub Konfigurator**: listet verbundene, noch nicht angelegte Ladepunkte.
4. **OCPPHub Abrechnung** (eigene Instanz, **obligatorischer** Bestandteil des Moduls,
   nicht zubuchbar/abwählbar — wird vom Splitter bei Erstanlage automatisch mit
   angelegt): Karten-/Nutzerverwaltung (idTag ↔ Name ↔ optional Fahrzeug), Tarife,
   Berichte, CSV-Export. Korrektur 30.08.2026 (Dietmar): ursprünglich als „optional"
   entworfen, das ist falsch — Abrechnung ist Kernzweck des Moduls, siehe CLAUDE.md.
   „Obligatorisch" heißt: die Instanz/Funktion existiert immer im Modul. Das
   **Konfigurationsformular** zeigt sie deswegen NICHT zwangsläufig an — siehe
   „Formular-Struktur" unten: der Alleinnutzer ohne Abrechnungsbedarf sieht davon nichts,
   bis er es aktiv aufklappt.

## Formular-Struktur (Konfigurationsformular)

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
| RemoteStartTransaction    | `ctl_enable` = an (mit internem idTag, wie Symcon: „symcon") |
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

- `OHUB_ManualStart(int $LadepunktID, int $ZugangID = 0)` / `OHUB_ManualStop(int
  $LadepunktID)` — `$LadepunktID` ist die Instanz-ID der OCPPHub-Ladepunkt-Instanz
  (bestätigt gegenüber Dashboard, 30.08.2026 — passt direkt in deren bestehendes
  Knoten-/instanceID-Modell, keine eigene ID-Auflösung nötig). Löst intern denselben
  Weg aus wie eine Kartenauflage (`Authorize.req` mit dem übergebenen Zugang, danach
  `RemoteStartTransaction`/`RemoteStopTransaction`). Bei Betriebsart ① (kein
  RFID-Zwang) wird `$ZugangID` ignoriert, es wird der interne „symcon"-idTag genutzt.
  Damit ist die Zuordnung unabhängig davon, was bei der rollenbasierten Konsole am Ende
  rauskommt — die eigentliche Zugriffskontrolle „wer darf das auslösen" ist Dashboards/
  WebFronts Berechtigungsfrage, keine von OCPPHub zu lösende.
- `OHUB_SetDailyOverride(int $LadepunktID, bool $Active)` — Tages-Override „heute
  Vollladen trotz PV-Vorrang", setzt die Vorrangkaskade („Steuerung /
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

## Authentifizierung (RFID & Alternativen)

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
  schickt, Format z. B. `VID:A014310E004E`. Fahrzeug muss das unterstützen (frühere
  Renault-/Tesla-Modelle u. a.); ob go-e das sendet, ist unklar und noch nicht geprüft.
  **Datenmodell-Konsequenz**: `idTag` bleibt einfach ein String/Whitelist-Eintrag —
  ein Autocharge-Tag ist technisch nur ein weiterer idTag-Typ, kein Sonderfall. Keine
  Architekturänderung nötig, nur bei Gelegenheit prüfen, ob WB1 das sendet.

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

## Vermerkte, noch nicht vertiefte Punkte

Kurz notiert (30.08.2026), bewusst noch nicht ausgearbeitet — vor der jeweils
betroffenen Umsetzung nachziehen:

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
   Repo öffentlich schalten → **ja, sobald erster installierbarer Code drin ist**
   (bestätigt 30.08.2026), analog den anderen NRG-Stack-Repos.
4. Lizenz → **PolyForm Noncommercial 1.0.0** (bestätigt 30.08.2026), kanonischer Text
   aus dem EMS-Repo übernommen, liegt jetzt als `LICENSE` im Root.
