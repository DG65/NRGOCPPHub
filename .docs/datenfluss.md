# Datenfluss zwischen den OCPPHub-Modulen

Seit 0.7.0 (30.10.2026, Symcon-Store-Review: „Du suchst dir in Splitter bzw. Gerät das jeweilige
Gegenstück heraus und führst auf dieser Instanz öffentliche Funktionen aus. Sauber wäre es die
Kommunikation über Nachrichten auf dem Datenfluss zu machen.“) reden die vier Module nur noch über
den Symcon-Datenfluss miteinander. Kein Modul sucht mehr Instanzen eines anderen OCPPHub-Moduls
und ruft deren Funktionen auf.

```
 Wallbox ──WebSocket──▶ Splitter ◀── Konfigurator            (Gerät, Typ 5)
                          │  ▲  ◀── Ladepunkt (je Wallbox)     (Gerät, Typ 3)
 SendDataToChildren ──────┘  └────── SendDataToParent / ForwardData
                             ◀── Abrechnung (genau eine)      (Gerät, Typ 3)
```

Der Splitter ist das **echte übergeordnete Gerät** (Symcon-Verbindung, `ConnectionID`) von
Ladepunkten, Abrechnung und Konfigurator. Welcher Splitter, wählt der Nutzer wie bei jedem
Symcon-Gerät oben im Formular bei der Schnittstelle („Gateway“). Die Position im Objektbaum spielt
keine Rolle. Die frühere Property `SplitterID` bleibt nur für die einmalige Migration bestehen.

## Schnittstellen (module.json)

| Richtung | DataID | Sender (`parentRequirements` bzw. `childRequirements`) | Empfänger (`implemented`) |
|---|---|---|---|
| Kind → Splitter | `{6ED0496A-EDD0-4600-9549-04D18B8387F5}` | Ladepunkt, Abrechnung, Konfigurator (`parentRequirements`) | Splitter, `ForwardData` |
| Splitter → Kind | `{DD617475-D014-4559-BC1C-6A39FCA4EDEE}` | Splitter (`childRequirements`) | Ladepunkt, Abrechnung, `ReceiveData` |

## Splitter → Kinder (`SendDataToChildren`)

Jede Nachricht geht an **alle** Kinder, jedes entscheidet in `ReceiveData`, ob sie ihm gilt, und
antwortet sonst mit einer leeren Zeichenkette. Antwort des gemeinten Kindes:
`{"ok": true, "daten": {...}}`. Der Splitter nimmt die erste nichtleere Antwort; keine Antwort heißt
„es gibt keinen Ladepunkt für diese Kennung“ (`anKinder()` liefert `null`).

Adressierung: Ladepunkte über `Cpid` (Charge-Point-Identity, muss zur Property `CPID` passen), die
Abrechnung über `Ziel: "Abrechnung"` plus `InstanzID` (der Splitter führt genau EINE, Attribut
`AbrechnungID`; eine zweite, unbenutzte Abrechnung bekommt nie etwas).

### An den Ladepunkt (`Cpid`)

| Aktion | Parameter | Wirkung am Ladepunkt |
|---|---|---|
| `Lebenszeichen` | `Ip` | Quell-IP merken, `LastSeenAt`/`ocpp_connected` setzen (bei **jeder** eingehenden Nachricht; die Antwort sagt zugleich, ob es den Ladepunkt gibt) |
| `Boot` | `Hersteller`, `Modell`, `Seriennummer` | `UpdateBootInfo` |
| `Status` | `Status`, `Fehlercode` | `UpdateStatus` |
| `Messwerte` | `Leistung` (W), `Energie` (Wh), `Soc` (%), je `null` möglich | `UpdateMeterValues` |
| `TransaktionStart` | `TransaktionsId`, `IdTag`, `ZaehlerStart` | `StartTransaction` |
| `TransaktionStopp` | `TransaktionsId`, `ZaehlerStop` | `StopTransaction` |
| `Kapazitaeten` | `MaxA`, `MinA`, `Phasen` (−1 = unbekannt) | `SetStationCapabilities` |
| `Blockiert` | `Grund` | `ReportBlockedStart` |
| `BlockGrundLoeschen` / `BlockGrundDiagnose` | | `ClearBlockReason` / `DiagnoseBlockReason` |
| `Fahrzeug` | `Name`, `Zeitkorrelation` | `SetVehicleName` |
| `FahrzeugTessie` | `Id` | `SetVehicleTessieId` |
| `AutoStartBestaetigt` | | `ConfirmAutoStart` |
| `Zustand` | | Antwort `daten`: `state`, `deactivated`, `externallyManaged`, `lastIdTag`, `meterStartWh`, `reservedIdTag`, `vehicleName` (ein Aufruf statt sieben Einzelabfragen; Grundlage für Schreibsperre, Reservierungsprüfung, Verbrauchsbuchung) |
| `Vertrag` (ohne `Cpid`, alle Ladepunkte antworten) | | Antwort `daten`: `cpid`, `eintrag` (= Eintrag für `OHUB_GetFunctions`) |

### An die Abrechnung (`Ziel: "Abrechnung"`, `InstanzID`)

| Aktion | Parameter | Antwort `daten` |
|---|---|---|
| `Autorisierung` | `IdTag` | Ergebnis von `CheckAuthorization` (`status`, `reason`, `vehicleName`, …) |
| `Verbrauch` | `IdTag`, `Kwh`, `Zeit` | leer |
| `IdTagZuFahrzeug` | `Name` | `idTag` |

## Kinder → Splitter (`SendDataToParent`, Antwort aus `ForwardData`)

Antwort immer `{"ok": true, "daten": ...}` oder `{"ok": false, "fehler": "..."}`.

| Aktion | Absender | Parameter | Antwort `daten` |
|---|---|---|---|
| `ManuellStart` | Ladepunkt | `Cpid` | leer |
| `Stopp` | Ladepunkt | `Cpid`, `TransaktionsId` | leer |
| `Stromlimit` | Ladepunkt | `Cpid`, `Ampere` | leer |
| `AutoFreigabe` | Ladepunkt | `Cpid`, `Name` | leer |
| `ForceLockLoesen` | Ladepunkt | `Cpid` | leer |
| `Reservieren` | Ladepunkt | `Cpid`, `IdTag`, `Bis` | `reservierungsId` |
| `ReservierungAufheben` | Ladepunkt | `Cpid`, `ReservierungsId` | leer |
| `Vorfuehrmodus` | Ladepunkt, Abrechnung | | `aktiv` |
| `Vertraege` | Ladepunkt | | `liste` (Vertragseinträge aller Ladepunkte, für „Duplikat von OCPPHub-Ladepunkt“) |
| `AbrechnungGebunden` | Abrechnung | `InstanzID` | `gebunden` (führt der Splitter genau diese Abrechnung?) |
| `GeseheneWallboxen` | Konfigurator | | Liste `Cpid → Zeitstempel` |

## Migration bestehender Instanzen

- **Ladepunkt, Konfigurator:** Nach dem Übernehmen startet ein Einmal-Timer (`VerbindungPruefen`).
  Ist die Instanz noch nicht verbunden, wird sie mit dem Splitter verbunden, in dieser Reihenfolge:
  Property `SplitterID` → übergeordnete Instanz im Objektbaum (nur wenn sie ein Splitter ist) →
  der einzige Splitter im System. Bei mehreren Splittern ohne Hinweis wird **nicht geraten**, das
  Verbindungs-Panel zeigt es an. Eine bereits verbundene Instanz bleibt unberührt.
- **Abrechnung:** Der Splitter verbindet seine Abrechnung (Attribut `AbrechnungID`) in
  `ensureAbrechnung()` beim Übernehmen, sofern sie noch nirgends verbunden ist. Neu angelegte
  Abrechnungen werden schon beim Anlegen verbunden.

## Bewusst unverändert

- Die **öffentlichen Funktionen** (`OHUBL_ManualStart`, `OHUBL_Reserve`, `OHUBL_SetActive`,
  `OHUB_GetFunctions`, …) bleiben als Schnittstelle für Verbund-Konsumenten (EMS, Dashboard,
  MeterHubVirtual) erhalten. Sie rufen intern über den Datenfluss weiter; kein veröffentlichter
  Vertrag wird umbenannt.
- Aufrufe an **andere** Verbund-Module (MeterHub, ChargerHub, Tessie, Tibber, EMS) laufen weiter
  über deren veröffentlichte Verträge (`*_GetFunctions`) mit `function_exists()`-Absicherung; das
  sind keine Geschwister desselben Moduls.

## Prüfstand

`php .tools/test-datenfluss.php`: echte Modulklassen über einen nachgebildeten Bus. Prüft
Verdrahtung (`module.json`, Konstanten), jede Splitter→Kind- und Kind→Splitter-Nachricht,
Adressierung (nur das gemeinte Kind reagiert), Schreibsperre und Reservierung über `Zustand`,
Verhalten ohne Kinder bzw. ohne Splitter, die Migration (inklusive „nicht raten“) und dass im Code
keine `OHUB*_`-Funktion einer anderen Instanz mehr aufgerufen wird.
