# Changelog

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
