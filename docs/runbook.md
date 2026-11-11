# Runbook: 't Klosterke event server

Voor Paul en het neefje. Alle opdrachten draai je in de projectmap op de Pi.

## Starten, stoppen, herstarten
Alle beheeropdrachten zitten in één script. Draai ze in de projectmap op de Pi.

| Wat | Opdracht |
|---|---|
| Starten (bouwt ook opnieuw na een codewijziging) | `./bin/klosterke start` |
| Stoppen (data blijft bewaard) | `./bin/klosterke stop` |
| Status bekijken | `./bin/klosterke status` |
| Na aanpassen van `data/*.json` of `docker/nginx.conf` | `./bin/klosterke herstart` |
| Back-up maken | `./bin/klosterke backup` |
| Back-up terugzetten | `./bin/klosterke herstel backups/<bestand>.sqlite` |
| PHP-bestanden op fouten controleren, vóór het bouwen | `./bin/klosterke controle` |
| Logboek bekijken | `./bin/klosterke logs php` (of `game-writer`, `nginx`) |

**Opnieuw bouwen op de Pi zonder internet:** `./bin/klosterke start` bouwt het PHP-image opnieuw en heeft het basisimage `php:8.3-fpm-alpine` (en `nginx:1.27-alpine`) al lokaal nodig. Die staan er na één keer starten met internet. Doe dat dus **vóór** het event en controleer met `docker images`. Zonder die images mislukt het bouwen.

## PHP aanpassen (voor het neefje)
De code staat in `src/` en `public/`. Ze zit in het Docker-image: een wijziging doet pas iets na `./bin/klosterke start`. Vergeet je dat, dan draait de oude code stil door.
1. `./bin/klosterke backup`: een veiligheidskopie van de database (mag ook tijdens het event).
2. Pas het bestand aan.
3. `./bin/klosterke controle`: vindt typefouten voordat je bouwt. "Geen syntaxfouten" zegt alleen dat de PHP leesbaar is, niet dat de logica klopt.
4. `./bin/klosterke start`: bouwt opnieuw en herstart php en game-writer, enkele seconden. Telefoons proberen bestellingen zelf opnieuw met dezelfde sleutel, dus er gaat niets verloren.
5. Controleer: `./bin/klosterke status` is groen en een proefbestelling op `order.html` lukt.

**Terugdraaien.** Is de projectmap een git-repository: `git status` toont wat je gewijzigd hebt. `git checkout -- <bestand>` zet dat bestand terug naar de laatst opgeslagen versie
(je wijziging is dan weg), daarna `./bin/klosterke start`. Is het geen repository: zet het bestand terug uit je eigen kopie. Ga er niet van uit dat dit op de Pi al zo is ingericht.

**Wat niet kan op de Pi:** de specs draaien (`extra/spec/`). De testfase haalt Kahlan met `composer install` binnen en dat heeft internet nodig. Test dus op een laptop.

**Haal niet weg:** de controle op `idempotency_key`, de voorraadcheck (`stock >= :qty`) en het schrijfslot (`WriteLock`) in `src/OrderController.php`. Ze zorgen dat een bestelling niet dubbel
of verloren gaat (zie ADR 2 en 3 in `docs/architecture.md`).

## Vóór het event
1. `data/questions.json`: zet `starts_at` op de starttijd van de quiz (bijvoorbeeld `"2026-11-14T20:00:00+01:00"`) en controleer de vragen.
2. `data/products.json`: echte muntjes en voorraad.
3. Een schone database: `./bin/klosterke stop`, dan `docker compose down -v` en daarna `./bin/klosterke start`. Dit wist alle bestellingen en antwoorden (niet terug te draaien), ook testgegevens van `bin/demo-quiz.php`.
4. Controleer de klok (`date`) en de images (`docker images`: `php:8.3-fpm-alpine` en `nginx:1.27-alpine` moeten er staan).

## Verbinden op het event
Er is geen internet, maar wel een lokaal netwerk: het access point in de zaal met de Pi aan een kabel. Bezoekers verbinden hun telefoon met dat wifi en
openen het adres van de Pi. Dit is niet getest op een echte Pi, een echt access point of echte telefoons.

**Eenmalig instellen**
1. Geef de Pi een **vast IP-adres**, bijvoorbeeld `192.168.1.10`. Doe dat op de Pi zelf of door in het access point een vast adres voor de Pi te reserveren.
   Anders kan het adres na een herstart veranderen en kloppen de QR-codes niet meer.
2. Zet in `.env` (naast `docker-compose.yml`): `HTTP_PORT=80`. Dan hoeft er geen poortnummer in het adres. Daarna `./bin/klosterke start`.
3. Gebruik het adres met `http://`, niet met `https://` (er is geen certificaat), en met het IP-adres, niet met een naam (zonder internet is er geen DNS).

**Op de tafels en bandjes**
- QR-code naar `http://192.168.1.10/` (de welkomstpagina). Pas het IP aan.
- QR-code om met het wifi te verbinden: `WIFI:T:WPA;S:<netwerknaam>;P:<wachtwoord>;;` Dit is een standaardformaat dat telefoons met de camera begrijpen; probeer het eerst uit.
- Netwerknaam en wachtwoord ook gedrukt, voor telefoons waarop de QR-code niet werkt.

**Vooraf testen (doe dit met meerdere telefoons, minimaal een iPhone en een Android)**
1. Verbind met het wifi en open `http://<ip>/`. Verschijnt de welkomstpagina met een bijnaam en de wachttijd?
2. Veel telefoons waarschuwen "wifi zonder internet" en sommige schakelen over naar mobiele data. Blijft de pagina bereikbaar? Zo niet, schakel
   mobiele data uit op die telefoon of kies "verbonden blijven".
3. Loop met een telefoon over het terras en het terrein: reikt het wifi overal? Eén access point in de zaal dekt dat misschien niet.
4. Plaats een proefbestelling en tik op het barscherm KLAAR: verschijnt "staat klaar" op de telefoon?

**Als bezoekers de pagina niet bereiken**
| Symptoom | Controleer |
|---|---|
| Alleen sommige telefoons komen er niet op | Mobiele data staat aan en neemt het over: zet die uit. |
| Niemand komt erop | Draai `./bin/klosterke status` op de Pi. Kijk dan naar het IP-adres van de Pi: `hostname -I`. |
| Het adres werkte gisteren, nu niet | Het IP van de Pi is veranderd (geen vast IP ingesteld). Zet het vast en print de QR-codes opnieuw. |
| Pagina laadt maar is traag | Te veel telefoons op één access point: dat is waarschijnlijk de eerste bottleneck, niet de Pi. |

## Schermen
- Welkomstpagina voor bezoekers: `http://<ip-van-de-pi>:8080/` (QR-code op tafels/bandjes). Toont de wachttijd en of de quiz loopt, met knoppen naar bestellen en de quiz.
- Bestelpagina voor bezoekers: `http://<ip-van-de-pi>:8080/order.html`. Toont producten uit `game.json`, wachttijd en "order 47 staat klaar".
- Statusscherm: `http://<ip-van-de-pi>:8080/status.html`. Groen = ok, rood = er staat onder welke controle faalt.
- Barscherm voor Paul: `http://<ip-van-de-pi>:8080/bar.html`. Geen inlog: houd het adres bij de bar.
  - Toont de volgende batch als tapopdracht ("12× Kloosterbier, 5× Rode Wijn"). Tik **KLAAR** als de batch klaar staat op de afhaaltafel.
  - **Bestellen pauzeren** zet bestellen uit (bezoekers zien dat). Nog eens tikken zet het weer aan.
  - Voorraad: alleen te zien. Corrigeren (gemorst, direct aan de bar verkocht) kan met DB Browser for SQLite: tabel `products`, kolom `stock` (eerst `./bin/klosterke backup`). Een product uitzetten: `"available": false` in `data/products.json` en `./bin/klosterke herstart`.
- Scorebord zaal: `http://<ip-van-de-pi>:8080/scores.html`.

Klare orders die niemand ophaalt verlopen na 10 minuten vanzelf en geven hun voorraad terug. De bezoeker ziet dan op zijn telefoon "Order N is niet opgehaald en vervallen. Bestel opnieuw of vraag het aan de bar."

## Producten, groepen en quiz instellen
- `data/products.json`: naam, `coins` (prijs in muntjes), `available` (false = niet te bestellen), `stock` (**beginvoorraad**, alleen voor een nieuw product).
  Voorraad later aanpassen: zie Barscherm.
- `data/groups.json`: afkoopgroepen met `group_code` (staat op het bandje).
- `data/settings.json`: beginwaarden voor o.a. `max_open_orders` (60) en `batch_size` (15). Een bestaande waarde wijzig je in de database (DB Browser for SQLite op een backup, of vraag hulp).
- `data/questions.json`: vragen, opties, juiste antwoord (`correct_option`, telt vanaf 0) en duur. Zet voor het event `starts_at`
  op de starttijd, bijvoorbeeld `"2026-11-14T20:00:00+01:00"`. Verwijder geen vraag waar al antwoorden op zijn.
Daarna `./bin/klosterke herstart`.

## Backup
- Maken: `./bin/klosterke backup`. Mag tijdens het event. Resultaat: `backups/klosterke-<datum>-<tijd>.sqlite`.
- Terugzetten: `./bin/klosterke herstel backups/<bestandsnaam>.sqlite`. Het script stopt alles, zet de database terug en start opnieuw.
  Bestellingen en antwoorden van na de backup gaan verloren.

## Statusscherm is rood: wat nu?
| Controle | Betekenis | Doen |
|---|---|---|
| `database` | Database niet bereikbaar of niet beschrijfbaar | `./bin/klosterke logs php`. Daarna `./bin/klosterke herstart`. Helpt dat niet: backup terugzetten. |
| `disk` | Schijf bijna vol | Oude `backups/` verwijderen (`docker system prune` ruimt oude images op). |
| `clock` | Klok van de Pi staat fout (na stroomuitval/herstart) | `sudo date -s "2026-11-14 19:30:00"`, daarna `./bin/klosterke herstart`. Quizvragen openen en sluiten op kloktijd, dus klok eerst goed. |
| `game_json` | De game-writer draait niet of hangt: scorebord en quiz bevriezen, klare orders verlopen niet | `./bin/klosterke herstart`. Zie `./bin/klosterke logs game-writer`. |
| Hele scherm: "GEEN ANTWOORD" | nginx of de Pi is onbereikbaar | `./bin/klosterke status`. Containers weg? `./bin/klosterke start`. Pi zelf onbereikbaar: netwerk en stroom controleren. |

## Pi herstart
Na het opstarten starten de containers vanzelf (Docker start bij het opstarten, `restart: unless-stopped`).
Verwacht: binnen ongeveer 1 tot 2 minuten groen op het statusscherm. Rood op `clock`? Zie hierboven.
Bestellingen en antwoorden staan op schijf en zijn er nog.
