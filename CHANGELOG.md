# Changelog

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
