# OCPPHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Status](https://img.shields.io/badge/Status-Stufe_1_ungetestet-orange)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)

OCPP-1.6J-Central-System für IP-Symcon: Wallboxen beliebiger Hersteller verbinden sich
per WebSocket zu Symcon und werden dort **gesteuert** (SmartCharging, PV-Überschussladen,
Lastmanagement) und **abgerechnet** (kWh und Kosten je RFID-Karte/Nutzer, Berichte,
CSV-Export — privatrechtlich, bewusst ohne Eichrecht/Roaming).

Teil des **NRG-Stack** — Geschwistermodul zu
[ChargerHub](https://github.com/DG65/NRGChargerHub) (Modbus TCP). Konsumenten (EMS,
Dashboard) sollen nicht merken, ob eine Wallbox per Modbus oder OCPP angebunden ist.

**Warum dieses Modul?** Das offizielle Symcon-OCPP-Modul kann kein SmartCharging (kein
Stromlimit, kein PV-Überschuss) und keine Nutzer-Abrechnung; SteVe & Co. haben keine
Regelungslogik und keine Symcon-Integration. Details und Quellen: `.docs/recherche.md`.

## Status

Stufe 1 (siehe `.docs/pflichtenheft.md` „Ausbaustufen") geschrieben, **UNGETESTET** —
weder gegen einen OCPP-Emulator noch gegen eine echte Wallbox verifiziert (nächster
Schritt laut `.docs/architektur.md` „Test-Strategie"). Enthält:

- **OCPPHub Splitter**: nimmt WebSocket-Verbindungen über einen Symcon-Hook entgegen
  (kein externer Daemon), Kernprotokoll (BootNotification, Heartbeat, StatusNotification,
  Authorize — Stufe 1 immer „Accepted", StartTransaction, StopTransaction, MeterValues),
  sendet RemoteStart/-Stop/SetChargingProfile, `OHUB_GetFunctions`-Vertrag.
- **OCPPHub Ladepunkt**: Variablen (Ident-Vokabular wie ChargerHub), eigenständiges
  PV-Überschussladen als EMS-loser Fallback (Logik aus ChargerHub portiert).
- **OCPPHub Konfigurator**: listet gesehene, noch nicht angelegte Charge-Point-Identities.

Bewusst NICHT in Stufe 1: RFID-Pflicht/Kundenverwaltung/Tarife/Reservierung/
Verbrauchslimits (Betriebsart ②/③), Phasenumschaltung, Lastverteilung über mehrere
eigene Ladepunkte. Details/Begründung: `.docs/architektur.md`.

Dokumentation:

- `CLAUDE.md` — Verbund-Regeln + OCPP-spezifische Festlegungen (zuerst lesen)
- `.docs/recherche.md` — Marktrecherche und Protokoll-Grundlagen
- `.docs/architektur.md` — Architektur (Instanzmodell, Nachrichten-Mapping, Formular-
  Struktur, Authentifizierung, Abrechnungs-Datenmodell, Tarifmodell, offene Fragen)
- `.docs/pflichtenheft.md` — Muss/Soll/Kann-Anforderungen, Ausbaustufen
- `.tools/check-standalone.php` — Eigenständigkeitsprüfung (Verbund-Standard)
