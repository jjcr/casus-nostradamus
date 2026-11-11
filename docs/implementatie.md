# Implementatie: feiten uit de code

Alleen feiten uit de code, per keuze met de plek waar je ze terugvindt. Geen onderbouwing: die staat in `docs/architecture.md`.

## Keuzes en waar ze in de code staan
| Keuze | Waar |
|---|---|
| `GET /game` is een bestand, geschreven 1×/s (tmp + rename); nginx serveert het, PHP niet | `src/GameStateWriter.php`, `bin/game-writer.php`, `docker/nginx.conf` |
| Alleen schrijven en health via PHP-FPM, 10 vaste workers | `docker/php-fpm.conf`, `public/index.php` |
| Geen keepalive nginx → FPM (hield workers vast, 60 s-hangers); nginx zoekt `php` per verzoek opnieuw op (na reboot kan het IP wisselen) | `docker/nginx.conf` |
| Bestelling niet dubbel: `idempotency_key` UNIQUE, eerste check, opnieuw binnen de transactie | `src/OrderController.php`, `database/schema.sql` |
| Bestelling niet verloren: 201 pas na commit, `synchronous=FULL`, WAL | `src/OrderController.php`, `src/Db.php` |
| Voorraad nooit negatief: `UPDATE … WHERE stock >= :qty` + `CHECK (stock >= 0)` | `src/OrderController.php`, `database/schema.sql` |
| Schrijvers in de rij vóór SQLite (gemeten bij 100 bestellingen/s: 95% binnen 154 ms zonder slot, binnen 6 ms met slot) | `src/WriteLock.php`, `docs/measurement.md` |
| Per vraag telt elke bezoeker één keer: `UNIQUE(question_id, visitor_id)` | `database/schema.sql`, `extra/spec/AnswersSpec.php` |
| Muntjes, geen geld: `coins` per product, totaal server-side, afkoopgroep = 0 | `src/OrderController.php`, `data/products.json`, `data/groups.json` |
| Bar beschermen: pauze, max open orders (503), max 2 per bezoeker (429), max 4 stuks | `src/OrderController.php`, `src/Bar.php`, `data/settings.json` |
| Batch-barscherm: één tik "klaar" per batch; zelf ophalen; niet opgehaald = verlopen + voorraad terug; de bezoeker ziet "vervallen" (`expired_orders` in `game.json`, laatste 200) | `public/bar.html`, `src/BarController.php`, `src/OrderExpiry.php` |
| Configuratie als data (producten, groepen, vragen, instellingen) | `data/*.json`, `bin/seed.php` |
| Klok na reboot wordt gecontroleerd | `src/HealthController.php` |
| Logging begrensd (SD-slijtage): geen nginx access log, docker-logs 10 MB × 3 | `docker/nginx.conf`, `docker-compose.yml` |

## De drie vragen
**Wat checkt `/health`?** (`src/HealthController.php`, 200 of 503 met per check `ok` + `detail`)
- `database`: een `UPDATE` op de tabel `health` (bewijst lezen én schrijven). Waarom: een kaart die na een fout alleen-lezen wordt, laat een `SELECT` slagen terwijl elke bestelling mislukt.
- `disk`: vrije ruimte ≥ `MIN_FREE_MB` (standaard 500). Waarom: bij een volle schijf kan SQLite niet meer committen en gaan bestellingen verloren; het WAL-bestand groeit tijdens het event (~380 MB gemeten bij hoge belasting).
- `clock`: niet vóór `EVENT_DATE_MIN` en niet terug t.o.v. de laatste goede meting. Waarom: de Pi heeft geen RTC en geen internet; na een reboot kan de klok fout staan en dan openen en sluiten de quizvragen op het verkeerde moment.
- `game_json`: niet ouder dan 5 s (anders draait game-writer niet). Waarom: scorebord, quizvraag, wachttijd en het verlopen van klare orders hangen van dat proces af; als het stilvalt, bevriest alles zonder dat PHP een fout geeft.
Niet gecontroleerd: nginx zelf, netwerk/access point, SD-slijtage.

**Wat gebeurt er bij een reboot om 03:00?**
1. Docker start (systemd) en daarmee nginx, php en game-writer (`restart: unless-stopped`). game-writer leest eerst `data/*.json` opnieuw in (`bin/seed.php`, idempotent), daarna start php.
2. SQLite herstelt via WAL bij de eerstvolgende verbinding. Bestellingen met een 201 staan op schijf.
3. game-writer bouwt `game.json` binnen 1 s opnieuw op uit SQLite.
4. **Klok:** geen RTC en geen internet, dus de klok kan achterlopen. Vragen openen en sluiten op absolute tijden, dus dit raakt de quiz. `/health` meldt het via `clock`. Herstel handmatig (zie runbook); een lokale tijdbron is niet gebouwd.

**Hoe weet Paul dat er iets mis is?** `public/status.html` bij de bar: groot groen of rood, elke 5 s, met de falende check; "GEEN ANTWOORD VAN SERVER" als de Pi of nginx weg is. Niemand krijgt een melding (geen internet, geen alarmering): Paul moet kijken.

## Aannames
- **Niet in de casus, zelf gekozen:** max 4 stuks per order, max 2 openstaande orders per bezoeker, `max_open_orders`, muntjes in plaats van geld, geen inlog op het barscherm, de verkeersmix en de pollintervallen. De casus geeft alleen: 1200 bezoekers, 3500 req/s, geen verlies of dubbele bestellingen, één antwoord per vraag.
- **Belasting:** de eis is 3500 req/s (casus). De load-test neemt aan: 1200 × ~3 req/s is *polling*, niet klikken, met mix ~90% `GET /game`, ~8% order-status, ~2% `POST /order/new`. De pagina's pollen minder vaak (`order.html` elke 5 s, dat is ~240 req/s bij 1200 bezoekers); zie `docs/measurement.md`. Afkoop kan het aandeel bestellingen verhogen.
- **Bezoeker:** `visitor_id` is een token in `localStorage` (alternatief: QR op polsbandje). Niet gecontroleerd; een ander token invullen werkt.
- **Paul staat (vrijwel) alleen:** ~150 tot 250 drankjes per uur. De bar is de bottleneck, niet de Pi. Advies aan de organisatie: tweede persoon.
- **Betalen:** het systeem verwerkt geen geld (geen internet). Muntjes vooraf bij de ingang of afkoopbandje. De prijs in muntjes op het scherm (`coins`) is wat de bezoeker aan de bar inlevert; het systeem controleert niet of hij die muntjes heeft.
- **Alcohol:** leeftijdscheck door barpersoneel bij uitgifte. **BBQ** en eten vallen buiten het systeem; kwaliteit en verkoopdatum uit Jeffreys systeem zijn genegeerd.
- **Onderhoud:** het neefje kan data (`data/*.json`) en de dagelijkse handelingen aan (`./bin/klosterke`, zie het runbook). Codewijzigingen kan hij alleen met de checklist in het runbook (back-up, controle, bouwen, proefbestelling, terugdraaien). Docker en het netwerk richt iemand anders eenmalig in. Dit is niet met het neefje uitgeprobeerd.
- **Afhalen:** de bezoeker laat zijn telefoon met het ordernummer zien ("Order 47 staat klaar!" in `public/order.html`). Het systeem controleert niet dat een nummer van de vrager is: nummers lopen op en staan in `/game`. Bewust zo gelaten (drankjes, één avond, mensen bij de bar kijken mee).
- **Barscherm:** geen inlog. Voorraad corrigeren gebeurt via de database (DB Browser), niet via het scherm.
- **Placeholders:** muntjes per product (nu 1) en voorraad (150/150/400) in `data/products.json` zijn niet van de organisatie.

## Alleen beschreven (niet gebouwd; antwoorden 501)
**`GET /order/status`**: `?order_id=` geeft `received`, `ready` of `expired`. Deels al aanwezig: `game.json` bevat `ready_orders`. Voor de volledige status: dezelfde aanpak als `/game` (statusbestand door game-writer, nginx serveert), of een PHP-endpoint met een `SELECT` per poll (8% van 3500 req/s = 280 req/s door FPM).

**`POST /game`**: `{ visitor_id, question_id, option }`. `UNIQUE(question_id, visitor_id)` dwingt één antwoord af (getest in `extra/spec/AnswersSpec.php`). Dubbel antwoord: 409 of het bestaande teruggeven (voorkeur: idempotent). Alleen binnen `opens_at <= nu < closes_at`, server-autoritatief. Scores komen al uit `answers` in `game.json`.

## Risico's
- Eén access point voor 1200 telefoons: waarschijnlijk de eerste bottleneck.
- 100 Mbit ethernet op de Pi 3 (deelt USB-bus).
- SD-corruptie bij stroomuitval: WAL + `synchronous=FULL` helpen, `./bin/klosterke backup` maakt een kopie.
- Klok na reboot (zie boven).
- `bar.html` en `/bar/*` hebben geen inlog: iedereen op de wifi die het adres kent kan bestellen pauzeren of orders op "klaar" zetten. Bewuste vereenvoudiging; afdekken kan met een wachtwoord of een IP-beperking in nginx.
- Geen alarmering; geen eigen meting op een Pi (zie `measurement.md`).
