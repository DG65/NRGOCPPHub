# OCPPHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Status](https://img.shields.io/badge/Status-Konzeptphase-orange)
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

Konzeptphase. Startpaket (30.08.2026, aus der ChargerHub-Session):

- `CLAUDE.md` — Verbund-Regeln + OCPP-spezifische Festlegungen (zuerst lesen)
- `.docs/recherche.md` — Marktrecherche und Protokoll-Grundlagen
- `.docs/architektur.md` — Architektur-Entwurf (Instanzmodell, Nachrichten-Mapping,
  Regelungs-Portierung aus ChargerHub, Abrechnungs-Datenmodell, offene Fragen)
- `.tools/check-standalone.php` — Eigenständigkeitsprüfung (Verbund-Standard)

Noch kein installierbares Modul.
