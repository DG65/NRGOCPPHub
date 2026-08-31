# Start-Recherche OCPP (30.08.2026, ChargerHub-Session)

Ergebnis zweier Web-Recherchen als Entscheidungsgrundlage „lohnt sich ein eigenes
OCPP-Steuerungs- und Abrechnungsmodul für Symcon?" — Antwort: **ja**, siehe Fazit unten.

## Offizielles Symcon-OCPP-Modul (die Lücke, die wir füllen)

- Repo: https://github.com/symcon/OCPP (Symcon GmbH/paresy, seit 05/2023; OCPP Splitter,
  OCPP Configurator, OCPP Charging Point). Doku:
  https://www.symcon.de/de/service/dokumentation/modulreferenz/geraete/ocpp/
  Forum: https://community.symcon.de/t/modul-ocpp/133352
- Central System, **OCPP 1.6J** (kein 2.0.1). Eingehend: BootNotification, Heartbeat,
  StatusNotification, MeterValues, Start-/StopTransaction, Authorize, DataTransfer.
  Ausgehend: RemoteStart/Stop, TriggerMessage, ChangeAvailability.
- RFID: ID-Tag-Validierung, 5 Modi, zentrale/lokale Whitelist (seit v1.1-Beta 04/2025).
- **Fehlt: SmartCharging komplett** — kein SetChargingProfile, keine Strommodulation →
  kein PV-Überschussladen, kein Lastmanagement. Explizite, unerfüllte Forum-Wünsche.
- **Fehlt: Abrechnung** — nur Wh je Einzeltransaktion; keine Summen je Karte/Nutzer,
  keine Kosten, keine Berichte. paresy hat Verbesserungen angekündigt (04/2025), unklar.
- Qualität laut Forum: „Datenausbeute eher gering" (paresy), MeterValues nur bei
  Statuswechsel/vollen kWh, kein konfigurierbares Intervall. Gerätequirks: EVBox Elfi
  nicht erkannt, Wattpilot ab FW 40.7, ABL EM4 RFID-/Messwert-Zuordnung fehlerhaft,
  Pulsar Plus verträgt keine internen Zeitpläne parallel. Keine Lizenzdatei im Repo.

## SteVe (bekanntester Open-Source-OCPP-Server)

- https://github.com/steve-community/steve — Java/Spring/MySQL, GPL-3.0, seit 2013,
  aktiv (~1.100 Stars). OCPP 1.2–1.6 (S+J) inkl. Security-Extensions. Web-GUI + REST.
- Kann: Ladepunkte/Nutzer/RFID (inkl. Ablauf/Sperre), Transaktionen, Reservierungen,
  alle Remote-Ops, SetChargingProfile — aber **nur als manuelle Operation/REST-Durchreiche,
  keine Regelungslogik**. Kein OCPP 2.x.
- Kann nicht: Abrechnung/Tarife, Eichrecht, OCPI/Roaming, PV-Regelung, Mandanten.

## Weitere Referenzen

- **CitrineOS** (https://github.com/citrineos/citrineos-core, LF Energy, Apache-2.0):
  modulares CSMS in TypeScript, Fokus 2.0.1, inzwischen auch 1.6. Baukasten, kein Produkt.
- **Open e-Mobility / ev-server** (https://github.com/sap-labs-france/ev-server,
  Apache-2.0): vollständigstes OSS-CSMS (NodeJS/MongoDB, Dashboard, Smart Charging,
  Stripe-Abrechnung), Aktivität abgeflacht, Deployment komplex.
- **EVerest** (LF Energy): Ladestations-FIRMWARE (Client-Seite), kein CSMS — als
  Test-Gegenstück nützlich.
- **Chargy / OpenChargingCloud** (https://github.com/OpenChargingCloud/ChargyDesktopApp):
  Eichrecht-Transparenzsoftware (PTB-zertifiziert) — zeigt, was wir bewusst NICHT bauen.
- **mobilityhouse/ocpp** (Python, MIT): Standard-Bibliothek 1.6+2.0.1, gute Logik-Referenz.
- **mikuso/ocpp-rpc** (Node): sauberes OCPP-J-RPC-Framing 1.6/2.0.1/2.1.
- **evcc** (https://docs.evcc.io/en/chargers/ocpp-1-6j-compatible/): agiert selbst als
  OCPP-1.6J-Central-System (Port 8887) und regelt PV-Überschuss über OCPP — **der
  Machbarkeitsbeweis** für unseren Steuerungsteil. evcc empfiehlt OCPP als Fallback;
  Qualität hängt an der Wallbox-Implementierung.
- PHP-Referenzen (dünn, aber existent): gennadiygnezdilov/php-ocpp-1.6J,
  solutionforest/ocpp-php. Chargepoint-Emulator zum Testen: apostoldevel/ocpp-cs.

## Protokoll-Kern OCPP 1.6J (bewusst schlank)

- WebSocket, Subprotokoll `ocpp1.6`. Framing als JSON-Arrays (WAMP-ähnlich):
  `[2, msgId, action, payload]` = CALL, `[3, msgId, payload]` = CALLRESULT,
  `[4, msgId, code, desc, details]` = CALLERROR.
- Funktionsfähiger Kern ≈ 10 Handler: BootNotification, Heartbeat, StatusNotification,
  Authorize, StartTransaction, StopTransaction, MeterValues (beantworten) +
  RemoteStartTransaction, RemoteStopTransaction, SetChargingProfile (senden).
- Aufwandstreiber sind NICHT das Protokoll, sondern: dauerhafter WebSocket-Serverprozess
  (Symcon bringt Server-Socket/WebSocket-Instanzen mit — kein externer Daemon),
  Reconnect-/Timeout-Handling, Hersteller-Quirks (MeterValues-Formate,
  TxDefaultProfile vs. TxProfile), Persistenz der Transaktionen.
- Leitfaden-Gist: https://gist.github.com/ChxGuillaume/a3d072cf711a196459e7ac9e5d5bb446

## OCPP-Fähigkeit des relevanten Wallbox-Bestands

- **go-e Gemini** (Dietmars WB1/WB2): OCPP 1.6J ab FW 56.1 (besser ≥56.8), Aktivierung
  per App, WSS + HTTP-Basic-Auth, Phasenumschaltung auch über OCPP möglich.
  https://support.clever-pv.com/hc/de/articles/34328191729682-go-e-Charger-OCPP
  **Reservation-Profil NICHT unterstützt** (live 31.08.2026 bestätigt: `ReserveNow` →
  `CALLERROR "NotImplemented"`, deckt sich mit go-es eigener Feature-Profile-Tabelle in
  deren OCPP-Doku). Unser `ReserveNow`/`CancelReservation` bleibt trotzdem protokoll-
  konform im Modul — betrifft nur die Wallbox-seitige Umsetzung, nicht unseren eigenen
  serverseitigen Blockade-Mechanismus (siehe `.docs/architektur.md` „Reservierung"),
  UND soll anderen Nutzern mit reservierungsfähiger Hardware zugutekommen (Dietmar
  30.08.2026: das Modul ist nicht nur für seine eigene Anlage gedacht).
- **KEBA P30**: OCPP 1.6 nur x-series (c-series: UDP/Modbus).
- **Alfen Eve**: OCPP-nativ (Primärprotokoll).
- **Heidelberg Energy Control**: KEIN OCPP (Modbus RTU) — bleibt bei ChargerHub.
- **Easee**: von Dietmar als möglicher Nutzerkreis genannt (31.08.2026) — OCPP-1.6-
  Unterstützung inkl. Reservation-Profil noch nicht recherchiert, TODO bei Bedarf.

## Autocharge (Fahrzeug-MAC als idTag) — Recherche 31.08.2026

Auf Dietmars Nachfrage recherchiert, ob ANDERE Wallbox-Fabrikate das können, was go-e
nicht kann (siehe `.docs/architektur.md` „Authentifizierung"). Ergebnis: **Autocharge
ist strukturell auf DC-Schnellladen (CCS/CHAdeMO) beschränkt** — zwei unabhängige
Quellen: Chargekeeper-Spec („compatible chargers: only DC chargers with a Combo CCS
connector"), emobilitysimplified.com („Autocharge will only work with CCS-based
vehicles"). Format: `VID:` + 12-stellige Hex-MAC ohne Trenner, Großschreibung (Beispiel
`VID:A014310E004E`, Quelle Chargekeeper-Doku, deckt sich mit dem schon vorher in
architektur.md notierten Beispiel). **Konsequenz**: KEINE der für dieses Modul
relevanten AC-Heimwallboxen (go-e, Easee, KEBA, Alfen, Heidelberg) wird Autocharge im
klassischen Sinne je senden, unabhängig vom Fabrikat — das ist ein Kategorie-Thema
(AC-Wallbox vs. DC-Schnelllader), kein go-e-spezifisches. Relevanter für die Zukunft:
ISO-15118 „Plug & Charge" (zertifikatsbasiert), zunehmend auch für AC-Laden — unser
generisches `idTag`-Datenmodell nimmt das ohne Änderung auf, sobald erste AC-Wallboxen
damit ausgeliefert werden.

## Was kommerzielle Anbieter besser machen (bewusst außerhalb unseres Scopes)

Eichrechtskonforme signierte Zählwerte + Transparenzsoftware, OCPI/Roaming
(Hubject/Gireve), Ad-hoc-Bezahlung/AFIR, Mandanten/SLA (Monta, reev, be.ENERGISED,
ChargePoint, Zaptec). Unsere Abrechnung: privatrechtlich belastbar
(Haushalt/Dienstwagen-Nachweis), kein öffentlicher Stromverkauf.

## Fazit

Ein Symcon-natives OCPP-Modul mit (a) echtem SmartCharging inkl. PV-Überschussregelung
(Logik aus ChargerHub portieren) und (b) Nutzer-/Karten-Abrechnung wäre das einzige
seiner Art. Der OCPP-Kern ist überschaubar; der Differenzierer ist die im NRG-Stack
bereits erprobte Regelung + der abgestimmte `*_GetFunctions`-Vertrag.
