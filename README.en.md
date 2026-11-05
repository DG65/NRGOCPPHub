🇩🇪 [Deutsche Fassung](README.md)

# OCPPHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Status](https://img.shields.io/badge/Status-Stage_2_partly_tested_live-orange)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)

OCPP 1.6J Central System for Symcon: wallboxes from any manufacturer connect to Symcon via
WebSocket and are **controlled** there (smart charging, PV surplus charging, load management)
and **billed** (kWh and costs per RFID card/user, reports, CSV export — private-law billing,
deliberately without calibration law/roaming).

Part of the **NRG-Stack** — sibling module of
[ChargerHub](https://github.com/DG65/NRGChargerHub) (Modbus TCP). Consumers (EMS, dashboard)
should not notice whether a wallbox is connected via Modbus or OCPP.

**Why this module?** The official Symcon OCPP module cannot do smart charging (no current
limit, no PV surplus) and no user billing; SteVe & Co. have no control logic and no Symcon
integration. Details and sources: `.docs/recherche.md` (German).

## Language

The form, the messages, the variable and profile names and the configuration tile of the
billing instance are available in **German and English** and follow the Symcon language
setting. German is the reference text; the English text is written as technical English, not
word for word. Variables that already exist keep their names (you can rename them yourself).

## Status

**Stage 1** (see `.docs/pflichtenheft.md`, “Ausbaustufen”) has been verified live on Dietmar’s
WB1 (30.08.2026) — core protocol and PV surplus charging work. **Stage 2** is written, but
still partly **UNTESTED**. It contains:

- **OCPPHub Splitter**: accepts WebSocket connections through a Symcon hook (no external
  daemon), core protocol (BootNotification, Heartbeat, StatusNotification, Authorize,
  StartTransaction, StopTransaction, MeterValues), sends RemoteStart/-Stop/SetChargingProfile/
  ReserveNow/CancelReservation, `OHUB_GetFunctions` contract, operating mode selection
  (① single user / ② multiple users).
- **OCPPHub Charge Point** (module name “OCPPHub Ladepunkt”): variables (ident vocabulary as
  in ChargerHub), standalone PV surplus charging as an EMS-less fallback (logic ported from
  ChargerHub and cross-checked against it), reservation, vehicle assignment (as in ChargerHub),
  “Control” area in the form (charge enable and current limit with feedback).
- **OCPPHub Configurator** (“OCPPHub Konfigurator”): lists charge point identities that have
  been seen but not yet created.
- **OCPPHub Billing** (“OCPPHub Abrechnung”): customers/access entries (cards)/vehicles/groups
  with consumption limits — created automatically by the splitter, only has an effect in
  operating mode ②.

Deliberately NOT in stage 2: tariffs/cost calculation, reports/CSV export, reservation fee,
phase switching, load distribution across several own charge points — that is stage 3.
Details/rationale: `.docs/architektur.md` (German).

The module names in the Symcon module list are the German ones (“OCPPHub Splitter”,
“OCPPHub Ladepunkt”, “OCPPHub Konfigurator”, “OCPPHub Abrechnung”); the English names
“Charge Point”, “Configurator” and “Billing” are found as search aliases.

Documentation (German):

- `CLAUDE.md` — stack rules + OCPP-specific decisions (read first)
- `.docs/recherche.md` — market research and protocol basics
- `.docs/architektur.md` — architecture (instance model, message mapping, form structure,
  authentication, billing data model, tariff model, open questions)
- `.docs/pflichtenheft.md` — must/should/could requirements, development stages
- `.tools/check-standalone.php` — independence check (stack standard)
