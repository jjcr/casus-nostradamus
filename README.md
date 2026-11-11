# 't Klosterke event server

Bestellen en quiz voor één festivalavond (~1200 bezoekers) op een Raspberry Pi 3B. PHP 8, nginx, SQLite, geen framework.

## Starten
Vereist Docker met Compose. Eén commando:

```
docker compose up
```

Open daarna:
- `http://localhost:8080/`: welkomstpagina voor bezoekers (telefoon), met knoppen naar bestellen en de quiz. Zet de QR-code hierop.
- `http://localhost:8080/order.html`: bestelpagina voor bezoekers (telefoon)
- `http://localhost:8080/scores.html`: scorebord voor de zaalschermen
- `http://localhost:8080/status.html`: statuspagina voor Paul (groen/rood)
- `http://localhost:8080/bar.html`: barscherm voor Paul (geen inlog, zie `docs/implementatie.md`)

Poort aanpassen: `HTTP_PORT=80 docker compose up`, of zet `HTTP_PORT=80` in `.env`. Na een wijziging in `docker/nginx.conf`: `docker compose restart nginx`
(het bestand wordt bij het starten gelezen, niet automatisch herladen).
Voor dagelijks gebruik (ook op de Pi) is er één script: `./bin/klosterke start | stop | status | herstart | backup | herstel <bestand> | controle | logs [dienst]`. Zie `docs/runbook.md`.

## Testen op een telefoon (ngrok, optioneel)
Zet je gratis ngrok-token in `.env` (naast `docker-compose.yml`): `NGROK_AUTHTOKEN=...`. `./bin/klosterke start` start dan ook een tunnel en toont de https-URL
(of: `docker compose --profile tunnel up -d` en `docker compose logs ngrok | grep url=`). Open die URL op je telefoon (mobiel netwerk of wifi).
Via de tunnel zijn alleen `welkom.html`, `order.html`, `scores.html`, `/game` en `/order/new` bereikbaar; `bar.html`, `/bar/*`, `/health` en `status.html` geven 404 (geen inlog).
Paul's scherm open je lokaal: `http://localhost:8080/bar.html`. De gratis ngrok-tussenpagina moet je één keer wegklikken. Alleen voor testen: op het event is er geen internet.
Getest: de tweede nginx-server (200 op de routes hierboven, 404 op de rest) en een echte tunnel met een geldig token (de URL gaf 200 voor de pagina's). Niet getest op een telefoon.

## Let op: placeholders
Muntjes per product (`coins`, nu 1) en voorraad (150/150/400) in `data/products.json` zijn aannames, niet van de organisatie.
Het systeem verwerkt geen geld: bezoekers betalen aan de bar met muntjes of een afkoopbandje (`docs/implementatie.md`).

## Endpoints
| Endpoint | Status | Wie levert |
|---|---|---|
| `GET /health` | gebouwd | PHP (`src/HealthController.php`) |
| `POST /order/new` | gebouwd | PHP (`src/OrderController.php`) |
| `GET /game` | gebouwd | nginx uit `game.json`, geschreven door `bin/game-writer.php` |
| `GET /bar/batch`, `POST /bar/ready`, `/bar/pause` | gebouwd, intern voor `bar.html` | PHP (`src/BarController.php`) |
| `GET /order/status`, `POST /game` | alleen beschreven (501) | zie `docs/implementatie.md` |

Voorbeeld:
```
curl -i localhost:8080/health
curl -i localhost:8080/game
curl -i -X POST localhost:8080/order/new -H 'Content-Type: application/json' \
  -d '{"idempotency_key":"abc-1","visitor_id":"v1","group_code":null,"items":[{"product_id":1,"quantity":2}]}'
```
Dezelfde `idempotency_key` nogmaals sturen geeft 200 met dezelfde bestelling; geen tweede bestelling.
Andere antwoorden: 503 (bestellen gepauzeerd of bar vol, met `retry_after`), 429 (al 2 openstaande orders), 409 (uitverkocht),
422 (onbekend product of `group_code`), 400 (ongeldige invoer, max 4 stuks per order).

## Specs
Kahlan-specs in `extra/spec/` (idempotency, voorraad bij gelijktijdige bestellingen, afkoopgroep, bar-limieten, verlopen orders):
```
docker compose --profile test run --rm --build test
```

## Demo van de quiz
Zonder antwoorden is de tussenstand leeg (`POST /game` is niet gebouwd). Voor een demo vult dit de stand met demo-bezoekers:
```
docker compose exec -T -u www-data php php bin/demo-quiz.php
```
Daarna toont `/scores.html` een gevulde tussenstand en een open vraag. Het script weigert als er echte antwoorden staan.
Opruimen: `docker compose down -v` (wist ook de bestellingen). Niet op het event gebruiken.

## Data aanpassen
Producten, afkoopgroepen, instellingen en quizvragen staan in `data/*.json`. Aanpassen en daarna
`./bin/klosterke herstart`. Voorraad uit de JSON is alleen de beginvoorraad.

## Load-test herhalen
```
./bin/loadtest.sh
```
Start een eigen stack (project `klosterke-load`, poort 8081, eigen volumes), zet de voorraad voor de meting omhoog,
draait k6 daarin en ruimt alles op. De echte database en voorraad blijven onaangeroerd. Uitvoer ook in `loadtest/results/laatste-run.txt`.
Het script staat in `loadtest/k6.js`: ramp-up naar 3500 req/s, 2 minuten vasthouden, drempels op de reactietijd (95% van de verzoeken sneller dan een grens) en het foutpercentage.
`TARGET_RPS=1000 ./bin/loadtest.sh` verlaagt het doel.

CPU-instellingen (zet in een `.env`-bestand naast `docker-compose.yml`). De casus vraagt een meting onder `--cpus=4 --memory=1g`:
geheugen staat vast (`mem_limit`, samen 960 MB); voor 4 cores totaal zet je `APP_CPUSET=0-3` (alle app-containers delen die 4 cores) en `K6_CPUSET` op andere cores (vereist een machine met minimaal 8 cores; anders deelt k6 de cores met de app en is de meting te pessimistisch):
- `APP_CPUS` (standaard 4): CPU-limiet van elke app-container, zie `bin/calibrate.php` voor de omrekening naar een Pi 3
- `APP_CPUSET` en `K6_CPUSET`: kernen voor de app en voor k6, bijvoorbeeld `APP_CPUSET=0-3` en `K6_CPUSET=4-7`, zodat ze niet concurreren

Kalibratie zonder echte Pi 3B: geef een band van Pi-scores op (uit publicaties, zie `docs/measurement.md`) voor
`sysbench cpu --cpu-max-prime=20000 --threads=1 --time=10 run`:
```
PI3_EVENTS_PER_SEC_LOW=<laag> PI3_EVENTS_PER_SEC_HIGH=<hoog> docker compose --profile loadtest run --rm calibrate
```
Het script geeft twee waarden voor `APP_CPUS`. Draai de load-test op beide (`APP_CPUS=<waarde> ./bin/loadtest.sh`); dat is de bandbreedte.
Sweep over vijf CPU-niveaus (`APP_CPUS` en `APP_CPUSET` wisselen): `./loadtest/sweep.sh` (ongeveer 40 minuten), uitvoer in `loadtest/results/sweep.txt`.
Resultaten en methode: `docs/measurement.md`.

## Mappen
```
docker-compose.yml, docker/    compose, nginx.conf, Dockerfile, php.ini, php-fpm.conf
public/                        index.php (router), welkom.html, order.html, status.html, scores.html, bar.html
src/                           Db, Http, Bar, WriteLock, HealthController, OrderController, BarController, OrderExpiry, GameStateWriter
bin/                           klosterke (beheer), game-writer, seed, backup, calibrate, demo-quiz, loadtest.sh
data/                          products, groups, settings, questions (JSON)
database/schema.sql
extra/                         niet voor de casus gevraagd: Kahlan-specs en testconfig
loadtest/                      k6.js
docs/                          architecture (1 A4 + 3 ADR's), runbook, implementatie, measurement
```
