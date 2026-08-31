# Changelog

## 0.6.5 (01.09.2026)

**Erster echter Live-Ladeversuch mit angestecktem Fahrzeug** — endlich der reale Test,
auf den die ganze "Rejected"-Untersuchung dieser Sitzung gewartet hatte. Drei
Ursachen gefunden und live behoben/verstanden, keine davon ein neuer Software-Bug
in der Kernlogik:

1. **Hängende alte Transaktion bestätigt** (die seit Wochen offene Hypothese): eine
   frühere Sitzung (Transaktion, gestartet über die damals noch unregistrierte Karte)
   war nie sauber per `StopTransaction` beendet worden. Der go-e lehnte deshalb jeden
   neuen `RemoteStartTransaction` mit `Rejected` ab — aus seiner Sicht lief ja schon
   eine Sitzung auf dem Connector. Live per `RemoteStopTransaction` beendet, Status
   ging sauber auf „Finishing" — löst die alte Hypothese endgültig auf.
2. **Fehlender Zugang für das zweite Fahrzeug**: „Schneeflocke" hatte schlicht keine
   registrierte Karte in der Kundenverwaltung (nur „Kohlekasten" hatte eine) — unter
   Betriebsart ② („Mehrere Nutzer") verweigert das System das zu Recht. Dietmar hat
   die zuvor unbekannte Karte über die brandneue "Karte anlernen"-Kachel selbst als
   Zugang für Schneeflocke registriert — danach liefen sowohl die reale Karte als
   auch die Auto-Autorisierung (`OHUB_AutoAuthorizeVehicle`) sofort fehlerfrei durch
   bis zu `RemoteStartTransaction` → `Accepted`.
3. **Danach kein Software-Thema mehr**: die Transaktion lief, ein frisches
   Stromlimit wurde gesendet, aber es floss weiter kein Strom. Tessie-Abfrage zeigt
   die wahrscheinliche Erklärung: das Fahrzeug steht bei 92 % SoC und fordert selbst
   nur noch 5 A an (`chargeAmpsRequest`/`chargeAmpsMax`) — unterhalb der
   IEC-61851-Mindeststromstärke (6 A), die Type-2-Laden voraussetzt. Vermutlich
   Teslas eigene Strom-Reduzierung nahe der Vollladung, außerhalb der Kontrolle
   dieses Moduls (die Wallbox/das Fahrzeug entscheiden das selbst, nicht der Central-
   System-Server).

**Ladepunkt 0.2.8**: dabei einen echten Bug im Diagnose-Feature selbst gefunden und
gefixt — `diagnoseFromTibber()` rief `TIBBERGR_GetActiveControls()` OHNE die
erforderliche InstanceID auf (`ArgumentCountError`), wodurch `DiagnoseBlockReason()`
beim ersten echten Ladeablehnungs-Fall dieser Sitzung komplett abstürzte, bevor es
überhaupt eine Erklärung anzeigen konnte — derselbe Fehlertyp wie beim
`RemoteStart()`-Fund vom 31.08., hier schlicht selbst wiederholt. Fix: erste
gefundene Tibber-Instanz wird jetzt korrekt mit übergeben.

## 0.6.4 (01.09.2026)

**Architekturkorrektur nach Dietmars Frage: "Gibt es keine andere Möglichkeit, wie
die Abrechnungs-Instanz direkt unter die Splitter-Instanz zu nageln?"** — die
Antwort: ja, die gibt es bereits, und sie ist besser als die Baumposition. Der
Splitter bindet seine Abrechnung-Instanz von Anfang an über ein Attribut
(`AbrechnungID`), NICHT über die Position im Objektbaum — `IPS_SetParent()` bei der
Erstanlage war immer nur ein einmaliger, kosmetischer Startort. Der gestern gebaute
Warnhinweis (0.3.3) prüfte aber fälschlich genau diese Baumposition
(`hasSplitterParent()`) statt der echten Bindung — hätte also fälschlich "nicht
verbunden" gemeldet, sobald die echte Instanz verschoben wird, und umgekehrt eine
zufällig unter denselben Splitter gehängte zweite, unbenutzte Instanz fälschlich als
verbunden durchgehen lassen.

- **Splitter 0.2.10**: neue öffentliche Methode `GetAbrechnungID()` — gibt die
  tatsächlich gebundene Abrechnung-Instanz-ID zurück.
- **Abrechnung 0.3.4**: `hasSplitterParent()` ersetzt durch `isRegisteredWithSplitter()`
  — fragt alle Splitter-Instanzen über `OHUB_GetAbrechnungID()` ab und prüft auf
  Übereinstimmung mit der eigenen InstanceID, unabhängig von der Baumposition.
  Warnhinweis (Konsole + Kachel) entsprechend umformuliert: nicht mehr "muss
  direktes Kind sein", sondern "muss vom Splitter tatsächlich als seine Abrechnung
  geführt werden — egal wo sie im Baum liegt". Praktische Folge für Dietmar: die
  Abrechnung-Instanz kann jetzt frei verschoben werden (z. B. in eine eigene
  Kategorie für eine aufgeräumtere WebFront-Einordnung), ohne die Funktion zu
  verlieren.

## 0.6.3 (01.09.2026)

**Abrechnung 0.3.3**: Dietmars nächster Screenshot-Vergleich deckte einen echten
Bug auf, keinen Kontrast-Fall — die neue Kachel zeigte in ALLEN vier Bereichen
"Noch keine Einträge", während die Konsole zur selben Zeit echte Daten (2 Fahrzeuge,
1 Gruppe, 1 Kunde, 1 Zugang) anzeigte. Ursache per Live-Abfrage gefunden: es
existieren zwei Abrechnung-Instanzen — die von Dietmar zum Kachel-Testen manuell
angelegte "OCPP-Kundenverwaltung" (Kind einer Kategorie "Test Kacheln", NICHT eines
OCPPHub-Splitters) ist leer, die echte "NRG-Stack OCPPHub Splitter Abrechnung"
(Kind des Splitters) enthält die Daten. Die Konsole warnt seit Stufe 2 bereits vor
genau diesem Fall (`hasSplitterParent()`), die neue Kachel bislang NICHT — sie zeigte
kommentarlos leere Daten ohne jeden Hinweis, warum. Fix: dieselbe Warnung jetzt auch
in `module.html` (`buildTilePayload()` liefert `connected`, Banner analog dem
Konsolen-Hinweis).

## 0.6.2 (31.08.2026)

**Abrechnung 0.3.2**: zweiter Kachel-Feinschliff nach Dietmars nächstem
Live-Screenshot — Symcons eigene Titelzeile (Instanzname + Symbol) über der Kachel
überlagerte weiterhin den Anfang des Inhalts. Dietmars pragmatischer Vorschlag: "10px
mehr Abstand nach unten". Screenshot-Vergleich (kompakte Grid-Kachel vs. die jetzt
gezeigte größere/aufgezogene Ansicht) zeigt aber: der von Symcon beanspruchte Bereich
oben ist NICHT konstant, sondern skaliert sichtbar mit der Kachelgröße — ein fester
px-Wert kann darum niemals für beide Ansichten gleichzeitig stimmen (vermutlich der
Grund, warum das laut Dietmar bei bisher KEINER Kachel im Verbund je sauber gelöst
wurde). Fix daher bewusst nicht 10px, sondern ein von der Kachelhöhe abhängiger
Abstand (`padding-top: clamp(28px, 9vh, 100px)`) statt eines festen Pixelwerts —
sollte sich automatisch an kompakte und vergrößerte Ansicht anpassen. Noch nicht
final mit echtem Live-Blick verifiziert, ob die Formel in beiden Fällen genau genug
trifft.

## 0.6.1 (31.08.2026)

**Abrechnung 0.3.1**: Kontrast-/Theming-Fix an der neuen Konfigurationskachel.
Dietmars Rückmeldung nach dem ersten Live-Blick: *"Die Sichtbarkeit ist mehr schlecht
als Recht."* Live in einem echten Browser nachgestellt (dunkles UND helles WebFront-
Theme): `module.html` nutzte `color: inherit` ohne eigenes `color-scheme` — dadurch
blieb der Text im dunklen WebFront praktisch schwarz auf schwarz (Browser-Default,
unabhängig vom tatsächlichen Theme, solange keine eigene Farbe gesetzt ist), nur im
hellen Theme zufällig lesbar. Fix: eigene helle/dunkle Farbpalette über
`@media (prefers-color-scheme: dark)`, `color-scheme: light dark` gesetzt, Panels
bekommen jetzt außerdem eine leichte eigene Hintergrundfläche zur klareren optischen
Abgrenzung. Zusätzlich entfernt: ein eigener `<h1>`-Titel in der Kachel, der sich mit
Symcons eigener Instanzname-Überschrift überlagerte (Verbundregel „Kachel zeigt
keinen eigenen Titel", hier selbst übersehen).

## 0.6.0 (31.08.2026)

**Neue Funktion: Konfigurationskachel** — Dietmars Idee, nachdem die "Karte
anlernen"-Funktion nur im Konsolenformular verfügbar war: *"Wir brauchen eine oder
mehrere Konfigurationskacheln. Die können wir dann verschiedenen Webfronts zuordnen
die gesichert sind. In den Administrationskacheln muss mindestens das gleiche wie in
der Konsole möglich sein."*

**Abrechnung 0.3.0**: die komplette Kundenverwaltung (Fahrzeuge/Gruppen/Kunden/
Zugänge inkl. Karte-anlernen-Hinweis) steht jetzt zusätzlich als eigene WebFront-Kachel
zur Verfügung — Konsolenformular bleibt unverändert bestehen (Parallelbetrieb,
Dietmars Wunsch). Technisch, weil Symcons Listen-Editor (Formularelement `List`, mit
Zeilen hinzufügen/löschen) ein reines Konsole-Formularelement ist und in WebFront gar
nicht rendert: eigene HTML/JS-Tabellenverwaltung in `module.html`, verbunden über
einen WebHook (`/hook/ohubadmin<InstanzID>`) — Muster 1:1 von `NRGDashboardTile`
übernommen (`RegisterHook()`/`MessageSink()`/`ProcessHookData()`, dort bereits
verbundweit bewährt).
- `GetVisualizationTile()` liefert die Kachel mit aktuellem Datenstand.
- `ProcessHookData()` bedient sowohl die eingebettete Kachel als auch eine
  eigenständige Seite (IPSView/Browser) und persistiert Änderungen je Bereich
  (`?area=Fahrzeuge|Gruppen|Kunden|Zugaenge`) SOFORT per `IPS_SetProperty()`+
  `IPS_ApplyChanges()` — anders als im Konsolenformular gibt es hier keinen
  umschließenden "Übernehmen"-Dialog, der die Selbstpersistenz-Regel für
  Formular-Buttons auslösen würde.
- Serverseitige Whitelist+Typprüfung (`AREA_SCHEMA`/`sanitizeRows()`) je Bereich —
  verhindert sowohl beliebige Property-Namen über `?area=` als auch Datenmüll durch
  ungeprüfte JS-Werte (die fehlende Formular-Typprüfung der Konsole muss die Kachel
  selbst nachholen).
- "Karte anlernen" bekommt in der Kachel eine direktere Variante
  (`adoptUnknownIdTagDirect()`): speichert sofort statt wie im Konsolenformular nur
  zu stagen, da es hier keinen Konsolen-"Übernehmen"-Schritt gibt, der das nachholen
  könnte.
- Zugriffsschutz bewusst NICHT modul-eigen: läuft komplett über Symcons Standard-
  WebFront-Sichtbarkeit je Instanz — ein separates, gesichertes WebFront zeigt diese
  Instanz, ein normales Familien-WebFront nicht. Kein zusätzliches Passwort im Modul.

## 0.5.3 (31.08.2026)

**Neue Funktion: Karte anlernen** — Dietmars Wunsch, nachdem er selbst wieder eine
idTag von Hand aus dem Systemlog abtippen musste: *"irgendwie müssen die idTags ja in
die Konfiguration kommen. D.h. wir brauchen eine Sequenz um die idTags anzulernen."*

**Abrechnung 0.2.12**:
- `CheckAuthorization()` merkt sich jetzt jede unbekannte idTag (`LastUnknownIdTag` +
  Zeitstempel-Attribute), sobald eine Karte aufgelegt wird, für die noch kein Zugang
  existiert.
- Neuer Hinweisblock oben im Formular, sobald eine unbekannte Karte vorliegt — mit
  Klartext-idTag, Zeitpunkt und einem Button „Als neuen Zugang übernehmen".
- Neue Methode `AdoptLastUnknownIdTag()`: staged einen Entwurfs-Zugang (idTag
  vorausgefüllt, Rest leer) per `UpdateFormField()` in die Zugänge-Liste — keine
  Selbstpersistenz im Button (Verbundregel), erst Dietmars eigenes „Übernehmen" im
  Formular speichert wirklich. Klappt dafür gezielt den Zugänge-Reiter auf, OHNE
  `ReloadForm()` — das würde die gerade gestagte, noch ungespeicherte Zeile sofort
  wieder verwerfen (Formular würde komplett neu aus `GetConfigurationForm()`
  aufgebaut). Kleiner bewusster Kompromiss: der Reiter rückt dabei nicht wie beim
  normalen Ziehharmonika-Klick ganz nach rechts, bleibt aber an seiner Stelle
  aufgeklappt sichtbar.

## 0.5.2 (31.08.2026)

**Ladepunkt 0.2.7**: Boot-Timing-Wache in `Update()` (proaktiv übernommen von
ChargerHub, Commit `e32ed18`, nach unserer Meldung eines „InstanceInterface is not
available"-Fehlers bei ihnen) — der `SurplusTimer` kann beim Systemstart feuern, bevor
der Kernel alle Instanzen fertig angebunden hat, jeder Property-Zugriff würde dann
kurzzeitig mit `InstanceInterface is not available` scheitern. Jetzt `if
(IPS_GetKernelRunlevel() !== KR_READY) return;` als erste Zeile — der Timer feuert kurz
danach ohnehin erneut, kein Datenverlust.

## 0.5.1 (31.08.2026)

**Splitter 0.2.9**: Selbst in dieselbe Falle getappt, die `block_reason` eigentlich
lösen sollte — eine per Live-Zugriff ad-hoc gesendete `GetConfiguration`-Abfrage lief
NICHT über `sendCall()` (Rohbefehl per `WC_PushMessage()`), tauchte deshalb nicht in
der `PendingCalls`-Korrelation auf, UND eine erfolgreiche `GetConfiguration`-Antwort
hat gar kein `status`-Feld — landete also weder in der Ablehnungs-Protokollierung noch
sonst irgendwo dauerhaft, nur im längst geschlossenen Debug-Fenster. Fix:
- Neue Diagnosefunktion `OHUB_GetConfigurationKeys($cpid, $keys)` — läuft korrekt über
  `sendCall()`, damit sie erfasst wird.
- Antworten auf `GetConfiguration` werden jetzt IMMER dauerhaft geloggt (nicht nur bei
  Ablehnung) — bewusst nur für diese eine, selten manuell zur Fehlersuche gesendete
  Aktion, kein Spam-Risiko im Normalbetrieb.

## 0.5.0 (31.08.2026)

**Neue Funktion: automatische Ladefreigabe für erkannte Fahrzeuge** ("so etwas wie
Autocharge", Dietmars Wunsch — nachdem die Recherche zeigte, dass echtes OCPP-
Autocharge strukturell auf DC-Schnelllader beschränkt ist und für keine der hier
relevanten AC-Heimwallboxen infrage kommt). Design vorher mit Dashboard abgestimmt
(deren Zuständigkeit für Fahrzeug-Zeitkorrelation direkt berührt, siehe „EIN
Korrelationsmechanismus im Verbund"-Regel).

- **Abrechnung 0.2.11**: neue Methode `FindIdTagForVehicleName()` — reine Suche
  (Fahrzeugname → verknüpfter Zugang → idTag), keine eigene Gültigkeitsprüfung.
- **Splitter 0.2.8**: neue Methode `AutoAuthorizeVehicle($cpid, $vehicleName)` — findet
  einen passenden idTag und jagt ihn durch DIESELBE `checkIdTag()`-Prüfung wie eine
  echte Kartenauflage (Limits/Zeitfenster/Reservierung/Kunde aktiv gelten identisch,
  keine laxere Sonderlogik), nur bei Betriebsart ②. Bei Erfolg: echter idTag (nicht
  `'symcon'`) an `RemoteStart()`, damit `RecordConsumption()` den Verbrauch dem
  richtigen Kunden zuordnet.
- **Ladepunkt 0.2.6**: `OHUBL_SetVehicleName()` um `bool $TimeCorrelated` erweitert
  (KEIN Standardwert — Symcons generierte globale Funktion ignoriert PHP-Standardwerte
  ohnehin, siehe 0.2.2-Fund) — Dashboard setzt das künftig auf `true` bei echter
  Zeitkorrelation (nicht bei deren Ein-Wallbox/Ein-Fahrzeug-Blindzuordnungs-
  Sonderfall). Bei `true` + noch keiner Ladefreigabe: löst `AutoAuthorizeVehicle()`
  aus, mit 60s-Sperrfrist gegen wiederholte Versuche (Dashboards Aufruf ist laut deren
  eigener Aussage kein Einmal-Ereignis, sondern wiederholt sich bei jedem
  Power-/SoC-Update oder deren 5-Minuten-Timer).

## 0.4.2 (31.08.2026)

Klarstellung im Formular (Ladepunkt 0.2.5) und in `.docs/recherche.md`: go-e unterstützt
den OCPP-Kernbefehl `ReserveNow` nicht (live bestätigt, siehe 0.4.1-Nachtrag in
`architektur.md`). Damit das nicht wie eine generelle Einschränkung unseres Moduls
wirkt (Dietmar: das Modul ist für den ganzen Nutzerkreis gedacht, nicht nur go-e) —
`ReserveNow`/`CancelReservation` bleiben unverändert protokollkonform im Code, unsere
eigene serverseitige Blockade-Durchsetzung ist davon unabhängig, und die Funktion
kommt Nutzern mit reservierungsfähiger Hardware (z. B. Easee) direkt zugute.

## 0.4.1 (31.08.2026)

**Ladepunkt 0.2.4** — zwei Anzeigefehler, beide beim ersten Live-Test des neuen
Diagnose-Features aufgefallen:

- **„Stromlimit: 10.0 A" statt „10 A"**: Dashboard (die bewusst nur `GetValueFormatted()`
  aufrufen, keine eigene Formatierung — richtig so) zeigte an, was IP-Symcon aus dem
  zugewiesenen Profil macht. Live geprüft: das geteilte `NRG.Ampere`-Profil existiert
  auf Dietmars System als FLOAT mit 1 Nachkommastelle (von einem anderen Modul zuerst
  angelegt, sinnvoll für echte fraktionale Ladestrom-Messwerte) — für unser
  ganzzahliges Sollwert-Limit `ctl_curr_limit` aber unpassend. Fix: eigenes Profil
  `OHUB.AmpereLimit` (Integer, 0 Nachkommastellen) statt das geteilte Profil
  umzudeuten (Verbund-Regel: gemeinsame Profile nie überschreiben).
- **„Fahrzeug angesteckt: An/Aus"**: `vehicle_plugged` hatte gar kein Profil zugewiesen
  → Symcons Boolean-Standardanzeige „Ein/Aus", passt aber nicht zu einer
  Angesteckt-Frage. Fix: eigenes Profil `OHUB.Connected` (Ja/Nein), exakt analog
  ChargerHubs `CHB.Connected`.

## 0.4.0 (31.08.2026)

**Neue Funktion: Ladeablehnung erklären.** Auslöser: Dietmar testete live einen
Ladestart, go-e lehnte `RemoteStartTransaction` sauber mit `Rejected` ab und ein
`SetChargingProfile` wurde zwar `Accepted`, blieb aber wirkungslos — ohne jede
Begründung im Dashboard sichtbar ("Ich hasse es, wenn etwas nicht funktioniert und man
keine möglichen Fehlermeldungen erhält"). Vor dem Bauen mit den betroffenen
Nachbar-Sitzungen abgestimmt (Problem geschildert, nicht nur die eigene Lösungsidee
abgefragt) — dabei fand Tibber Grid Rewards strukturell überzeugend, dass sie als
Ursache für eine Ablehnung VOR Sitzungsbeginn unwahrscheinlich sind (sie greifen erst
nach Sitzungsstart auf Fahrzeugseite ein), und Tessie fand live einen konkreten Befund:
140 Minuten alte Telemetrie am Testfahrzeug — typisches Muster für ein schlafendes
Tesla, das gerade nicht mit der Wallbox verhandelt.

- **Splitter 0.2.7**: leichte Korrelation gesendete-uniqueId → Aktion (`PendingCalls`-
  Attribut, Einträge >5 Min verworfen) — bei einer eindeutigen Ablehnung (`CALLERROR`
  oder `status` ≠ `Accepted`) auf `RemoteStartTransaction`/`SetChargingProfile` wird
  jetzt `OHUBL_DiagnoseBlockReason()` am zugehörigen Ladepunkt aufgerufen; klappt
  derselbe Aufruftyp später doch, wird eine zuvor gesetzte Begründung über
  `OHUBL_ClearBlockReason()` wieder gelöscht.
- **Ladepunkt 0.2.3**: neue Variable `block_reason` (auch additiv im
  `GetContractEntry()`-Vertrag, `blockReasonID`, contractVersion 1.1→1.2). Bei einer
  Ablehnung: verknüpftes Tessie-Fahrzeug abfragen (`TESSIE_GetVehicleState()`) —
  `scheduledChargingActive` → „eigene Ladeplanung aktiv", `soc >= chargeLimit` →
  „Ladelimit erreicht", veraltete Telemetrie (`InstanceStatus === 203`) → automatisch
  `TESSIE_WakeUp()` anstoßen und „Fahrzeug antwortet gerade nicht" melden. Ergänzend ein
  Namensabgleich gegen `TIBBERGR_GetActiveControls()`, ausdrücklich als „möglicherweise,
  nicht sicher zuordenbar" markiert (Tibber selbst empfiehlt das nur als Ausschluss-
  Zusatzinfo, siehe oben). `LastVehicleTessieId`-Attribut wird beim Abstecken
  zurückgesetzt, analog `vehicle_name`.
- **Abrechnung 0.2.10**: `CheckAuthorization()` liefert additiv
  `vehicleTessieInstanceId`, damit der Splitter bei der idTag-Direktzuordnung die
  verknüpfte Tessie-Instanz an den Ladepunkt weiterreichen kann
  (`OHUBL_SetVehicleTessieId()`).
- **Nebenbei**: Tibber Grid Rewards hat auf unsere Nachfrage `TIBBERGR_GetActiveControls()`
  selbst nachgebessert — `deviceId` liefert jetzt echte Tibber-`vehicleId`/`batteryId`
  statt hart `0` (contractVersion 1.0→2.0, deren Commit `0cecc66`). Tessie hat parallel
  `TESSIE_WakeUp($id)` als neue öffentliche Funktion ergänzt (deren Commit `4e47688`).

## 0.3.3 (31.08.2026)

Nach dem ArgumentCountError-Fix (0.3.2) lief der Aufruf zwar durch, die Wallbox lud aber
weiterhin nicht — Dashboard fand über die Netzwerk-Analyse `200 OK`/`{"ok":true}`, aber
keine reale Ladung. Live per Debug-Dump bestätigt: go-e antwortete auf
`RemoteStartTransaction` sofort mit `{"status":"Rejected"}` (kein Timeout, echte
Ablehnung).

- **Splitter 0.2.6**: `RemoteStartTransaction` fehlte das Feld `connectorId` — go-e
  (und vermutlich andere Wallboxen mit mehreren gemeldeten Connectors, hier WB2 mit
  Connector 0 = ganze Wallbox und Connector 1 = tatsächlicher Stecker) weiß dann nicht,
  welchen Connector es starten soll, und lehnt strukturell ab. Jetzt fest
  `connectorId: 1` gesetzt — live bestätigt als der Connector, über den eine echte
  kartenausgelöste `StartTransaction` tatsächlich läuft.
- **Ablehnungen jetzt dauerhaft sichtbar**: jede erkennbare Ablehnung (`CALLERROR` oder
  ein `status` ungleich `"Accepted"`) auf einen von uns gesendeten Aufruf wird
  zusätzlich per `IPS_LogMessage()` ins dauerhafte Systemlog geschrieben — vorher nur im
  flüchtigen Debug-Fenster sichtbar, wodurch genau dieser Fehlschlag für Dashboard
  komplett unsichtbar war (`{"ok":true}` kam trotzdem zurück, da wir den Rückgabewert
  von `WC_PushMessage()`/die spätere Antwort bisher nicht auswerten).

## 0.3.2 (31.08.2026)

**Kritischer Fix** (Dashboard-Sitzung fand den exakten Stacktrace beim Debuggen eines
scheinbaren Netzwerkfehlers): Klick auf „Laden starten" ließ den Ladepunkt mit
`ArgumentCountError: Too few arguments to function OHUB_RemoteStart(), 2 passed ... and
exactly 3 expected` abstürzen — jeder manuelle Ladestart über Dashboard/`ctl_enable`
schlug fehl.

Ursache (live per `ReflectionFunction` verifiziert): `OCPPHubSplitter::RemoteStart()`
deklariert `string $idTag = 'symcon'` mit PHP-Standardwert, aber **Symcons generierte
globale Instanzfunktion (`OHUB_RemoteStart($InstanceID, ...)`) ignoriert PHP-
Standardwerte auf Parametern komplett** — alle drei Parameter sind in der generierten
Funktion zwingend, unabhängig vom Default im Quellcode. `OCPPHubLadepunkt` rief
`OHUB_RemoteStart($splitterId, $cpid)` mit nur 2 Argumenten auf und verließ sich auf den
(wirkungslosen) Default.

Fix: dritter Parameter (`'symcon'`) wird jetzt explizit übergeben; die PHP-Standardwerte
auf `RemoteStart()` (Splitter) und `ManualStart()` (Ladepunkt, derselbe Fehlertyp,
bisher folgenlos weil kein interner Aufrufer den Parameter je wegließ) wurden ganz
entfernt, damit derselbe Fehler nicht wieder passieren kann. Nebenbei entdeckter
Symcon-Fallstrick, verbundweit relevant — an die EMS-Sitzung (SUITE.md-Pflege)
gemeldet.

## 0.3.1 (31.08.2026)

Live-Test-Fund: Dietmars neu angelegter Zugang blieb dauerhaft `Invalid`, obwohl die
Daten sichtbar gespeichert waren. Ursache per Live-Zugriff gefunden: es existierten
**zwei** OCPPHub-Abrechnung-Instanzen — die vom Splitter automatisch angelegte (direktes
Kind des Splitters, `ensureAbrechnung()` fragt IMMER nur diese) war leer; Dietmar hatte
seine Daten in eine zweite, manuell an anderer Stelle angelegte Instanz eingetragen, die
vom Splitter nie konsultiert wird. Daten wurden live in die richtige Instanz kopiert.

- **Splitter 0.2.4**: Warnhinweis im Formular (bei der Betriebsart) — „OCPPHub
  Abrechnung" wird automatisch als direktes Kind DIESER Splitter-Instanz angelegt,
  niemals selbst zusätzlich eine solche Instanz anlegen.
- **Abrechnung 0.2.9**: Neue Selbstdiagnose — prüft beim Formularaufbau, ob die eigene
  Instanz tatsächlich direktes Kind einer OCPPHub-Splitter-Instanz ist
  (`hasSplitterParent()`). Falls nicht, erscheint ganz oben im Formular ein
  unübersehbarer Warnhinweis, dass diese Instanz von keinem Splitter verwendet wird und
  gelöscht werden sollte.

## 0.3.0 (30.08.2026)

**Splitter 0.2.3**: Dietmar wollte den idTag einer aufgelegten Karte nachschlagen,
fand aber das Debug-Fenster schon wieder geschlossen — `SendDebug()`-Ausgaben sind
nur live sichtbar, solange das Debug-Fenster offen ist, und danach unwiederbringlich
weg. Fix: `checkIdTag()` schreibt jede Kartenauflage (Authorize/StartTransaction)
zusätzlich dauerhaft ins Symcon-Systemlog (`IPS_LogMessage`) — idTag + Ergebnis
(Accepted/Blocked/…) bleiben damit auch nachträglich nachschlagbar, z. B. um eine neue
Karte in der Abrechnung-Instanz anzulegen.

## 0.2.9 (30.08.2026)

**Splitter 0.2.2**: Betriebsart-Auswahlfeld war zu schmal, die Auswahltexte
("② Mehrere Nutzer — zentrale Autorisierung über die Abrechnung-Instanz") wurden
abgeschnitten. Fix: `width` auf `560px` gesetzt.

## 0.2.8 (30.08.2026)

Dietmar: 450px/1800px waren noch zu breit, außerdem sollte der gerade aktive Reiter
immer ganz rechts stehen (statt an seiner festen Ursprungsposition).

- **Breite weiter reduziert** auf 400px (eingeklappt) / 1600px (aufgeklappt).
- **Aktiver Reiter rückt ans rechte Ende**: die vier Panels werden jetzt aus einer
  benannten Definition (`$panelDefs`) gebaut und je nach `ActiveAccordionPanel` neu
  sortiert — die drei nicht-aktiven behalten ihre relative Reihenfolge, der aktive
  Reiter wird ans Ende der Liste (= ganz rechts) verschoben. Da `UpdateFormField` die
  Reihenfolge von Elementen innerhalb eines `RowLayout` nicht ändern kann (nur
  Eigenschaften bestehender Elemente), ruft `OHUBA_OnPanelToggle()` jetzt stattdessen
  `ReloadForm()` auf — das Formular wird bei jedem Klick komplett aus
  `GetConfigurationForm()` neu aufgebaut, inklusive neuer Reihenfolge.

## 0.2.7 (30.08.2026)

Feinjustierung nach Dietmars Rückmeldung: `WIDTH_NARROW`/`WIDTH_FULL` von 480px/1920px
auf 450px/1800px reduziert (480/1920 waren auf seinem Bildschirm zu breit).

## 0.2.6 (30.08.2026)

Dietmar wollte beide Effekte aus 0.2.5 zusammen statt nur einen: die vier Reiter sollen
eingeklappt gemeinsam die volle Formularbreite ausfüllen UND ein aufgeklappter Reiter
soll allein die volle Breite einnehmen.

- **Feste, deterministische Breiten statt der bisherigen inhaltsabhängigen
  Flex-Berechnung**: neues Attribut `ActiveAccordionPanel` merkt sich serverseitig,
  welcher der vier Reiter gerade offen ist (der `onClick`-Callback allein verrät nicht,
  ob gerade auf- oder zugeklappt wurde). Alle vier `ExpansionPanel`s bekommen daraus
  berechnet: eingeklappt `480px` (× 4 = `1920px`, zusammen die volle Formularbreite),
  der aktive Reiter `1920px` allein. `OHUBA_OnPanelToggle()` schreibt das Attribut und
  zieht `width`+`expanded` alle vier Panels per `UpdateFormField()` nach — verifiziert,
  dass `width` bei `UpdateFormField` änderbar ist (nur `name`/`type` und explizit als
  "nicht änderbar" dokumentierte Felder sind es laut Symcon-Doku nicht).
- Damit ist das Verhalten jetzt unabhängig vom tatsächlichen Inhalt jedes Reiters
  (Listenspaltenzahl, Erklärtextlänge) — genau deshalb war es in 0.2.3–0.2.5 noch
  uneinheitlich.

## 0.2.5 (30.08.2026)

Dietmar schickte Screenshots aller vier Reiter: je nachdem, WELCHER Reiter geöffnet
wurde, verhielt sich die Zeile anders — mal öffnete sich das Panel in voller Breite und
die anderen drei rutschten in eine eigene Zeile darunter (Fahrzeuge, Zugänge), mal blieb
das geöffnete Panel zwischen den weiterhin schmalen Reitern in derselben Zeile stehen
(Gruppen, Kunden). Ursache gefunden: der Erklärtext in Fahrzeuge/Gruppen hatte keine
feste Breite — ein längerer, nicht umgebrochener Fließtext lässt den Browser die
„natürliche" Breite des ganzen Panels an der Textlänge bemessen (bei Fahrzeuge fast die
komplette Formularbreite), nicht an der eigentlich benötigten Listenbreite. Zusätzlich
wollte Dietmar echtes Ziehharmonika-Verhalten: Öffnen eines Reiters soll die anderen
automatisch zuklappen.

- **Erklärtexte auf feste Breite begrenzt** (540px, Fahrzeuge/Gruppen) — Panelgröße
  richtet sich jetzt überall nach dem tatsächlichen Platzbedarf (Liste bzw. begrenzter
  Text), nicht nach unbegrenzt langem Fließtext.
- **Ziehharmonika-Verhalten**: alle vier `ExpansionPanel`s bekommen einen `onClick`-
  Handler (`OHUBA_OnPanelToggle()`) — bei jedem Klick auf einen Reiter werden die
  jeweils anderen drei per `UpdateFormField(..., 'expanded', false)` zugeklappt. Es ist
  zu jedem Zeitpunkt höchstens ein Reiter offen.

## 0.2.4 (30.08.2026)

Korrektur zu 0.2.3: Dietmar stellte klar, dass er nicht vier verbreiterte Panels wollte,
sondern das genaue Gegenteil vom optischen Eindruck her — vor 0.2.3 saßen Fahrzeuge und
Gruppen kollabiert als schmale, reiterartige Köpfe (~20px) nebeneinander; erst per Klick
öffnete sich das jeweilige, beliebig breite ExpansionPanel. Genau dieses Reiter-Verhalten
sollte auf alle vier Gruppen ausgeweitet werden, nicht die feste Verbreiterung aus 0.2.3.

- Alle vier `width`-Vorgaben aus 0.2.3 wieder entfernt — Panels sind wieder unskaliert
  (Reiterbreite richtet sich nach der Beschriftung, nicht nach einer festen Pixelzahl).
- Fahrzeuge, Gruppen, Kunden und Zugänge stehen jetzt als EIN gemeinsames `RowLayout`
  mit vier Reitern (vorher zwei getrennte Zweierreihen) — genau das Bild „4 Reiter auf
  einer Höhe" aus Dietmars Beschreibung.
- Kunden/Zugänge starten jetzt ebenfalls eingeklappt (`expanded: false`, vorher `true`)
  — einheitliches Reiter-Verhalten für alle vier statt eines Sonderfalls.

## 0.2.3 (30.08.2026)

Dietmar fand das Fahrzeuge/Gruppen-Nebeneinander aus 0.2.2 gut und wollte es
konsequent zu Ende gedacht: auch Kunden und Zugänge nebeneinander, und alle vier
Listen breiter, damit sie die verfügbare Formularbreite besser ausnutzen.

- **Kunden/Zugänge jetzt ebenfalls in einer Zeile** (analog Fahrzeuge/Gruppen), statt
  wie bisher gestapelt.
- **Alle vier Panels verbreitert** (`width` auf jedem `ExpansionPanel` in der
  `RowLayout`: Fahrzeuge 600px, Gruppen 450px, Kunden 900px, Zugänge 1100px — nach
  Spaltenanzahl gestaffelt). Bewusst feste Pixelwerte statt Prozent: IP-Symcon 9.0 hat
  einen bestätigten Darstellungsfehler bei Prozent-Breiten in `RowLayout` (Elemente
  überlappen/stauchen sich), Fix laut Symcon-Entwickler erst für 9.1 angekündigt —
  echte responsive 100%-Breite ist damit auf 9.0 nicht sauber möglich, feste Pixelwerte
  sind der verifizierte Workaround. Kunden/Zugänge bleiben inhaltlich unverändert breit
  (6 bzw. 8 Spalten) — bei schmalen Fenstern scrollt die jeweilige Liste intern, wie
  bisher schon bei den einzeln stehenden Panels.

## 0.2.2 (30.08.2026)

Dietmar wies darauf hin, dass die Fahrzeuge-Liste in OCPPHub Abrechnung bislang
freihändig getippte Namen erwartete, obwohl seine echten Fahrzeuge im NRG-Stack-Verbund
längst per Tessie bekannt sind ("Schneeflocke", "Kohlekasten") — die beiden Datenwelten
hatten keine Verbindung.

- **Fahrzeuge ↔ Tessie verknüpfbar**: neue Spalte „Tessie-Fahrzeug" (`SelectInstance`,
  gefiltert auf `TessieVehicle`) in der Fahrzeuge-Liste von OCPPHub Abrechnung. Ist eine
  Instanz verknüpft, gilt deren `IPS_GetName()` als Anzeigename (immer aktuell, kein
  doppeltes Pflegen) — sonst weiterhin das manuelle Namensfeld. Wirkt überall dort, wo
  bislang der freihändige Fahrzeugname verwendet wurde: Fahrzeug-Dropdown im Formular,
  `CheckAuthorization()`s `vehicleName`-Rückgabe (und damit die idTag-Direktzuordnung
  des `vehicle_name` am Ladepunkt).
- **Fahrzeuge/Gruppen nebeneinander**: beide Panels stehen jetzt in einer `RowLayout`
  statt gestapelt untereinander (Dietmars Vorschlag beim Live-Test der
  Abrechnungs-Instanz).

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
