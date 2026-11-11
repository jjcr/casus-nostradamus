# Meting en vertaling naar Raspberry Pi 3B

**Er is geen echte Pi 3B beschikbaar.** Alles hieronder is gemeten op een laptop (WSL2, 16 cores). De vertaling naar de Pi
berust op een band van gepubliceerde benchmarks, niet op eigen metingen. De sweep en de conclusie staan hieronder.

## Methode
1. `./bin/loadtest.sh` start een eigen stack, zet de voorraad omhoog en draait `loadtest/k6.js`. De app staat op `APP_CPUSET`
   (standaard alle cores; in `.env` bijvoorbeeld `0-3`) en `APP_CPUS` (standaard 4); k6 draait op `K6_CPUSET`, zodat de load generator
   niet met de app concurreert. `mem_limit`: php 704 MB + game-writer 128 MB + nginx 128 MB, samen onder 1 GB.
2. `k6.js`: ramp-up naar 3500 req/s (90 s), daarna 120 s vasthouden, mix 90% `GET /game`, 8% `GET /order/status` (501-stub), 2% `POST /order/new`.
   Drempels (95% van de verzoeken moet sneller zijn dan de grens): `/game` < 100 ms, `/order/new` < 500 ms, fouten < 1%, `dropped_iterations` < 100 (anders haalde de generator de snelheid niet).
3. **Kalibratie met een band:** `bin/calibrate.php` meet `sysbench cpu` (1 thread) hier en deelt door een *band* van Pi 3B-scores.
   Resultaat: twee waarden voor `APP_CPUS` (pessimistisch en optimistisch); draai de load-test op beide.
4. **Sweep:** `./loadtest/sweep.sh` draait de load-test voor 4, 2, 1, 0,57 en 0,07 cores (totaal) en zoekt per niveau het hoogste
   aanbod (3500, 2500, 1700, 1200, 800 … req/s) dat binnen de drempels blijft. Een run houdt 30 s vast (`HOLD_SECONDS`); de tabel is
   dus een schatting van de grens, geen bevestiging met 120 s. Resultaat: `loadtest/results/sweep.txt`.
   - Vanaf 1 core wordt het totaal begrensd met `cpuset` (alle containers samen). Onder 1 core kan dat niet: dan krijgt elke container de helft
     (`APP_CPUS`), omdat nginx en php de twee drukke zijn. Het mag dus niet méér halen dan dit totaal, maar wel iets minder (een container kan
     de ongebruikte helft van de ander niet lenen).

**Onzekerheid in de Pi-score.** Gepubliceerde sysbench-scores voor een Pi 3 (B+, nooit de B zelf) lopen een factor 8 uiteen:
~27 events/s (sysbench 0.4.12, 60 s; [Armbian forum](https://forum.armbian.com/topic/7763-benchmarking-cpus/)) tegen ~233 events/s
(sysbench 2018; [OpenBenchmarking](https://openbenchmarking.org/result/2001279-HU-CPUMEMORY84&grt&export=html)). Oorzaak niet vastgesteld.
De B+ klokt 1,4 GHz, de B 1,2 GHz. Gebruik de band als grove aanwijzing en noteer bron en versie bij elk getal.

## Resultaten
Kalibratie (`bin/calibrate.php`): `sysbench cpu` hier = 1638 events/s per core. Pi 3B-band 27 tot 233 events/s geeft factor 0,016 tot 0,142 per core,
dus **4 Pi-cores ≈ 0,07 tot 0,57 core hier** (pessimistisch tot optimistisch).

| Cores hier (totaal) | Betekenis | Hoogste aanbod binnen drempels | 95% van `/game` sneller dan | 95% van `/order/new` sneller dan | Fouten |
|---|---|---|---|---|---|
| 4 | laptop-opstelling | 3500 req/s (doel, niet het maximum) | 0,65 ms | 26 ms | 0% |
| 2 | | 3500 req/s (doel) | 0,56 ms | 15 ms | 0% |
| 1 | | 3500 req/s (doel) | 6,3 ms | 141 ms | 0% |
| 0,57 | optimistische Pi-grens | **1700 req/s** | 37 ms | 347 ms | 0% |
| 0,07 | pessimistische Pi-grens | **200 req/s** | 41 ms | 141 ms | 0% |

Bij het volgende aanbod erboven faalde elke grens: 0,57 core bij 2500 req/s (`/order/new` 95% pas na 1,9 s, 1936 gedropt); 0,07 core bij 300 req/s
(`/order/new` 95% pas na 2,8 s). Bij 4, 2 en 1 core is 3500 het hoogste dat getest is, dus het echte maximum ligt daarboven. Uitvoer: `loadtest/results/sweep.txt`.
Eerdere volle run (4 cores, 120 s, 585.999 verzoeken, 0 mislukt): zie `loadtest/results/laatste-run.txt`.

## Geheugen tijdens de load-test
Gemeten met `docker stats` elke paar seconden tijdens een volle run (3500 req/s, 120 s vasthouden; 102 metingen) op de laptop met 4 cores:

| Container | Limiet | Piek |
|---|---|---|
| php | 704 MB | 380 MB |
| nginx | 128 MB | 15 MB |
| game-writer | 128 MB | 8 MB |
| **samen** (zonder k6) | 960 MB | **400 MB** op één moment |

- Geen enkele container is afgebroken wegens geheugen (`docker events --filter event=oom` bleef leeg).
- De piek van php (380 MB) is waarschijnlijk deels bestandscache van het WAL-bestand, dat in dezelfde run tot ~360 MB groeide. Dat deel geeft het besturingssysteem terug als het geheugen nodig is.
  Ik heb niet gemeten hoeveel van die 380 MB cache is.
- Op een Pi komt Docker en het besturingssysteem erbij (mijn schatting: 100 tot 200 MB, niet gemeten). Ruim 400 MB plus 200 MB past in 1 GB, maar de marge is niet groot.
- Gemeten op een 64-bit laptop met x86. Op de Pi (ARM) kan het geheugengebruik anders zijn; niet gemeten.

## Wat de metingen opleverden
1. **60 s-hangers door nginx-keepalive naar FPM.** De eerste runs haalden alle thresholds, maar 0,12 tot 0,17% van de PHP-requests
   (ook `/order/status`, zonder database) liep na 60 s in een time-out; de 95%-grens zag dat niet (te weinig trage verzoeken om die grens te halen; alleen het maximum en het foutaantal toonden het). Een open `fastcgi_keep_conn`-verbinding houdt een
   FPM-worker vast; met 10 workers en 16 verbindingen bleven aanvragen wachten tot nginx de verbindingen sloot. Keepalive staat nu uit
   (`docker/nginx.conf`). Daarna geen hangers meer.
2. **`WriteLock`** (`src/WriteLock.php`), alleen `POST /order/new`, 20 s per niveau:

   | Aanbod | zonder slot | met slot |
   |---|---|---|
   | 100 bestellingen/s | 95% binnen 154 ms, 99% binnen 542 ms | 95% binnen 6 ms, 99% binnen 8 ms |
   | 300 tot 1000/s (overbelast) | ~165 tot 175 afgehandeld/s | ~230 tot 270 afgehandeld/s |

   Zonder slot wachten workers via SQLite's `busy_timeout` (pollt met slaapjes, laat het slot vaak onbenut). Het slot zet schrijvers in de rij en geeft na 2 s een 503.
3. **10% snel mislukt (nginx hield een oud IP-adres vast).** Een aantal runs meldde precies ~10% mislukte requests (alle PHP-routes, < 1 ms, `orders=0`).
   Oorzaak: `docker compose run k6` maakte de php-container opnieuw aan; die kreeg een ander IP-adres, terwijl nginx het oude adres bij het starten had
   onthouden (`connect() failed (111: Connection refused)`). Dit komt alleen soms voor, afhankelijk van de IP-toewijzing. Opgelost op twee plekken:
   `bin/loadtest.sh` draait k6 met `--no-deps`, en nginx zoekt `php` per verzoek opnieuw op (`resolver 127.0.0.11`, zie `docker/nginx.conf`).
   Dat laatste geldt ook voor de Pi: na een reboot kunnen containers een ander IP-adres krijgen. Daarna gaf de test 0 mislukte requests.

## Conclusie met marge
**Een Pi 3B haalt volgens deze meting ongeveer 200 tot 1700 req/s**, in de aangenomen mix (90% `/game`, 8% status, 2% bestellen). De bovengrens is
8× de ondergrens: dat komt rechtstreeks uit de factor 8 tussen de gepubliceerde Pi-scores en is met de beschikbare gegevens niet kleiner te maken.

- **De eis van de organisatie is 3500 req/s (uit de casus). Die wordt op beide grenzen niet gehaald.** Pessimistisch haalt de Pi 6% van die eis, optimistisch de helft. De eis zelf blijft staan; het antwoord is dus "dit haalt het niet, en dit is waarom", plus de volgende regel over het werkelijke aanbod.
- De casus zegt niet hoe de 3500 is opgebouwd. Mijn aanname (1200 bezoekers × ~3 req/s) is polling. Het werkelijke aanbod hangt af van het pollinterval van de telefoon:
  1200 bezoekers die elke 3 s `/game` ophalen = 400 req/s (past binnen de optimistische grens, niet binnen de pessimistische);
  elke 6 s = 200 req/s (past op de pessimistische grens, zonder marge).
- **Wat de pagina's nu echt aanbieden:** `order.html` haalt `/game` elke 5 s op (`public/order.html`), `welkom.html` elke 5 s, `scores.html` elke 1 s (alleen de zaalschermen), `bar.html` elke 3 s en `status.html` elke 5 s. 1200 bezoekers met `order.html` open zijn dus ongeveer **240 req/s**, niet 3500 (met een interval van 2 s, zoals eerder, was dat 600 req/s). Dat past binnen de optimistische grens (≈ 1450 req/s na correctie, ruim onder de helft) en ligt ongeveer 1,4 keer boven de pessimistische (≈ 170 req/s). De eis van 3500 req/s staat in de casus; dat het werkelijke aanbod van deze pagina's lager is, is een eigen schatting en geen weerlegging van de eis. Een langer interval betekent dat "staat klaar" tot 5 s later op de telefoon verschijnt (plus ongeveer 2 s van game-writer en cache).
- **Extra marge die ik afraad weg te laten:** de B klokt 1,2 GHz, de Pi-scores in de band zijn van de B+ (1,4 GHz): reken met ongeveer 15% minder
  (≈ 170 tot 1450 req/s). Reken daarnaast niet op de volle capaciteit: houd het verwachte aanbod onder de helft van de grens.
- Dat betekent: **plan met 170 req/s als zekere ondergrens en 1450 als hoop.** Zonder echte Pi-meting kan ik niet zeggen waar daartussen de Pi zit.
  Eén meting op een echte Pi (`sysbench` zoals in `bin/calibrate.php`, daarna dezelfde sweep) maakt de band smal.

## Wat niet gemeten is
- Alles op een echte Pi 3B
- SD-kaart I/O: `synchronous=FULL` is op een SD-kaart langzamer dan op een laptop-SSD en raakt vooral `POST /order/new`
- Netwerk: 100 Mbit ethernet op de Pi, het access point, 1200 telefoons
- Thermal throttling; geheugenbandbreedte (Cortex-A53 is veel trager); echte PHP/nginx/SQLite-werklast (de kalibratie meet alleen rekenwerk)
- `GET /order/status` en `POST /game` (niet gebouwd); de barstromen (batch, klaar, verlopen) onder belasting; reconnect-golven van telefoons
- Groei van het WAL-bestand (~380 MB na 4 minuten met ~70 bestellingen/s; `journal_size_limit` is niet gezet)
- `APP_CPUS` geldt per container, niet samen; `APP_CPUSET` begrenst het totaal
