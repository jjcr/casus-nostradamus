<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

// Maakt het schema aan en leest data/*.json in de database.
// Veilig om vaker te draaien (game-writer doet dat bij elke start): bestellingen, antwoorden en voorraad blijven staan.

function readJson(string $file): array
{
    $data = json_decode((string) file_get_contents(__DIR__ . '/../data/' . $file), true);
    if (!is_array($data)) {
        fwrite(STDERR, "seed: data/$file is geen geldige JSON\n");
        exit(1);
    }
    return $data;
}

$pdo = Db::connect();
$pdo->exec((string) file_get_contents(__DIR__ . '/../database/schema.sql'));
$pdo->exec('INSERT OR IGNORE INTO health (id) VALUES (1)');

// Producten: naam, prijs en available worden bijgewerkt. `stock` uit de JSON is de BEGINvoorraad:
// alleen gebruikt bij een nieuw product. Anders zou een herstart de voorraad terugzetten.
$stmt = $pdo->prepare(
    'INSERT INTO products (id, name, coins, stock, available) VALUES (?, ?, ?, ?, ?)
     ON CONFLICT(id) DO UPDATE SET name = excluded.name, coins = excluded.coins,
                                   available = excluded.available'
);
foreach (readJson('products.json') as $p) {
    $stmt->execute([$p['id'], $p['name'], $p['coins'], $p['stock'], $p['available'] ? 1 : 0]);
}

// Afkoopgroepen: toevoegen of bijwerken op group_code.
$stmt = $pdo->prepare(
    'INSERT INTO groups (group_code, name, size) VALUES (?, ?, ?)
     ON CONFLICT(group_code) DO UPDATE SET name = excluded.name, size = excluded.size'
);
foreach (readJson('groups.json') as $g) {
    $stmt->execute([$g['group_code'], $g['name'], $g['size']]);
}

// Instellingen: alleen beginwaarden. Bestaande waarden blijven staan (de pauzeknop mag niet terugspringen).
$stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)');
foreach (readJson('settings.json') as $key => $value) {
    $stmt->execute([$key, (string) $value]);
}

// Vragen: toevoegen of bijwerken op id. De vragen volgen elkaar op, elk met eigen duur.
// - starts_at ingevuld: vaste planning, elke seed zet de tijden opnieuw (zelfde uitkomst).
// - starts_at null: nieuwe vragen starten nu; bestaande vragen houden hun tijden.
//   Zo begint de quiz niet opnieuw als seed na een herstart weer draait.
$quiz = readJson('questions.json');
$fixed = !empty($quiz['starts_at']);
$start = $fixed ? strtotime($quiz['starts_at']) : time();
if ($start === false) {
    fwrite(STDERR, "seed: starts_at in data/questions.json is geen geldige datum\n");
    exit(1);
}

$timesUpdate = $fixed ? ', opens_at = excluded.opens_at, closes_at = excluded.closes_at' : '';
$stmt = $pdo->prepare(
    'INSERT INTO questions (id, text, options, correct_option, opens_at, closes_at) VALUES (?, ?, ?, ?, ?, ?)
     ON CONFLICT(id) DO UPDATE SET text = excluded.text, options = excluded.options,
                                   correct_option = excluded.correct_option' . $timesUpdate
);
$opensAt = $start;
foreach ($quiz['questions'] as $q) {
    $closesAt = $opensAt + $q['duration_seconds'];
    $stmt->execute([$q['id'], $q['text'], json_encode($q['options'], JSON_UNESCAPED_UNICODE), $q['correct_option'], $opensAt, $closesAt]);
    $opensAt = $closesAt;
}

echo 'seed: ' . $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() . ' producten, '
    . $pdo->query('SELECT COUNT(*) FROM groups')->fetchColumn() . ' groepen, '
    . $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn() . " vragen\n";
