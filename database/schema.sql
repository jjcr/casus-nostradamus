-- Tijden zijn unix-seconden (INTEGER): simpel vergelijken, geen tijdzone-gedoe.

CREATE TABLE IF NOT EXISTS products (
    id          INTEGER PRIMARY KEY,
    name        TEXT    NOT NULL,
    coins       INTEGER NOT NULL CHECK (coins >= 0),            -- prijs in muntjes; het systeem verwerkt geen geld
    stock       INTEGER NOT NULL CHECK (stock >= 0),            -- nooit negatief: de database dwingt het af
    available   INTEGER NOT NULL DEFAULT 1 CHECK (available IN (0, 1))
);

-- Afkoopgroepen: vooraf aangemaakt, code staat op polsbandje/kaartje.
CREATE TABLE IF NOT EXISTS groups (
    group_code TEXT    PRIMARY KEY,
    name       TEXT    NOT NULL,
    size       INTEGER NOT NULL
);

-- Instellingen die tijdens de avond kunnen veranderen (pauzeknop) of de bar beschermen.
CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS orders (
    id              INTEGER PRIMARY KEY,
    idempotency_key TEXT    NOT NULL UNIQUE,   -- zelfde key = zelfde bestelling, nooit dubbel
    visitor_id      TEXT    NOT NULL,
    group_code      TEXT    REFERENCES groups(group_code),
    total_coins     INTEGER NOT NULL,          -- muntjes, server-side berekend; 0 bij afkoopgroep
    status          TEXT    NOT NULL DEFAULT 'received'
                    CHECK (status IN ('received', 'ready', 'expired')),
    created_at      INTEGER NOT NULL,
    ready_at        INTEGER,                   -- gezet als Paul "klaar" tikt
    expired_at      INTEGER                    -- gezet als niet opgehaald
);

CREATE INDEX IF NOT EXISTS idx_orders_status  ON orders (status, id);
CREATE INDEX IF NOT EXISTS idx_orders_visitor ON orders (visitor_id, status);

CREATE TABLE IF NOT EXISTS order_items (
    order_id   INTEGER NOT NULL REFERENCES orders(id),
    product_id INTEGER NOT NULL REFERENCES products(id),
    quantity   INTEGER NOT NULL CHECK (quantity > 0),
    coins INTEGER NOT NULL,              -- muntjes per stuk op het moment van bestellen
    PRIMARY KEY (order_id, product_id)
);

CREATE TABLE IF NOT EXISTS questions (
    id             INTEGER PRIMARY KEY,
    text           TEXT    NOT NULL,
    options        TEXT    NOT NULL,           -- JSON-array met antwoordteksten
    correct_option INTEGER NOT NULL,           -- index in options (0-based)
    opens_at       INTEGER NOT NULL,
    closes_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS answers (
    question_id INTEGER NOT NULL REFERENCES questions(id),
    visitor_id  TEXT    NOT NULL,
    option      INTEGER NOT NULL,
    created_at  INTEGER NOT NULL,
    UNIQUE (question_id, visitor_id)           -- per vraag telt elke bezoeker een keer
);

-- Eén rij, door /health bijgewerkt: bewijst dat schrijven lukt en onthoudt de laatste goede klok.
CREATE TABLE IF NOT EXISTS health (
    id           INTEGER PRIMARY KEY CHECK (id = 1),
    pings        INTEGER NOT NULL DEFAULT 0,
    last_good_at INTEGER NOT NULL DEFAULT 0
);
