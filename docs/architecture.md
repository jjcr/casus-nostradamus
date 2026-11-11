# Architectuur: 't Klosterke event server

**Ontwerp.** Bestellen en quiz voor één avond, ~1200 bezoekers, op één Raspberry Pi 3B (1 GB, geen internet). Telefoons verbinden met het wifi in de zaal. Drie containers: nginx, PHP-FPM (bestellen, health, barknoppen) en game-writer (schrijft elke seconde `game.json`). SQLite is de enige opslag. `GET /game` is een bestand uit nginx; `POST /order/new` gaat via PHP naar SQLite. Paul tikt per batch één keer KLAAR; bezoekers betalen met muntjes en halen zelf op.
**Aannames.** ~90% van het verkeer is `/game`; Paul staat vrijwel alleen; geld blijft buiten het systeem; ophalen wordt niet gecontroleerd.
**Niet gebouwd.** `GET /order/status`: een statusbestand zoals `/game`. `POST /game`: een insert met `UNIQUE (question_id, visitor_id)` binnen het tijdvenster; een tweede antwoord geeft het bestaande terug.
**Meting.** Laptop (4 cores): 3500 req/s gehaald. Verwachting Pi: 200 tot 1700 req/s, onder de eis (`docs/measurement.md`).

## Beslissingen (MADR)

### ADR 1: `GET /game` is een bestand dat nginx uitdeelt
*Status: geaccepteerd, 2026-11-11*
**Context.** Het meeste verkeer is telefoons die `/game` ophalen; de eis is 3500 req/s op een Pi 3B. **Factoren:** weinig CPU, een paar seconden achterlopen mag.
**Opties.** Elk verzoek via PHP en SQLite · cache in PHP · bestand door nginx.
**Besluit.** Bestand door nginx: game-writer schrijft elke seconde `game.json`, PHP en de database komen er niet aan te pas.
**Gevolgen.** Goed: laptop 3500 req/s, 95% sneller dan 0,65 ms (Pi niet gemeten). Slecht: de stand loopt tot ~2 s achter; valt de writer stil, dan bevriest de quiz, dus `/health` controleert `game.json` (< 5 s).
**Bevestiging.** Load-test (`loadtest/k6.js`), `GameStateWriterSpec`.

### ADR 2: SQLite, met de regels in de database
*Status: geaccepteerd, 2026-11-11*
**Context.** Bestellingen niet verloren of dubbel, één antwoord per vraag en bezoeker. **Factoren:** 1 GB, geen IT-afdeling, de eisen ook bij een fout in de code.
**Opties.** MySQL of Postgres · gewone bestanden · SQLite.
**Besluit.** SQLite met WAL en `synchronous=FULL`, 201 pas na de commit. In het schema: `UNIQUE (idempotency_key)`, `CHECK (stock >= 0)`, `UNIQUE (question_id, visitor_id)`.
**Gevolgen.** Goed: de eisen gelden altijd; een back-up is één bestand. Slecht: één schrijver tegelijk (ADR 3); `synchronous=FULL` is op een SD-kaart trager (niet gemeten).
**Bevestiging.** Specs voor gelijktijdige bestellingen, voorraad en antwoorden.

### ADR 3: Schrijvers in de rij zetten
*Status: geaccepteerd, 2026-11-11*
**Context.** SQLite laat één schrijver toe; bij een piek wachten alle workers via `busy_timeout`. **Factoren:** snel falen, geen verloren bestelling.
**Opties.** Alleen `busy_timeout` · `flock`-slot · apart schrijfproces.
**Besluit.** `WriteLock`: een `flock` die elke ms kijkt en na 2 s een 503 geeft; de telefoon probeert opnieuw met dezelfde `idempotency_key`.
**Gevolgen.** Goed: bij 100 bestellingen/s 95% binnen 154 ms zonder slot, binnen 6 ms met slot (laptop). Slecht: bij overbelasting een 503; alleen op één machine.
**Bevestiging.** Meting met en zonder slot (`docs/measurement.md`).
