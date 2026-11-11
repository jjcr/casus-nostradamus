<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

// Alleen voor een demo: zet de quiz in een tussenstand zodat GET /game en scores.html iets laten zien.
// De laatste vraag staat open (4 minuten te gaan), alle eerdere vragen zijn gesloten en hebben antwoorden
// van demo-bezoekers. Nooit op het event zelf gebruiken: het verschuift de tijden van de vragen.
//
// Gebruik (na ./bin/klosterke start):
//   docker compose exec -T -u www-data php php bin/demo-quiz.php
// Opruimen: docker compose down -v (wist ook de bestellingen) of de database terugzetten uit een backup.

$pdo = Db::connect();

$real = (int) $pdo->query("SELECT COUNT(*) FROM answers WHERE visitor_id NOT LIKE 'demo-%'")->fetchColumn();
if ($real > 0) {
    fwrite(STDERR, "demo-quiz: er staan echte antwoorden in de database ($real). Niets gedaan.\n");
    exit(1);
}

$questions = $pdo->query('SELECT id, correct_option FROM questions ORDER BY id')->fetchAll();
if (!$questions) {
    fwrite(STDERR, "demo-quiz: geen vragen in de database (draait game-writer?).\n");
    exit(1);
}

// Laatste vraag: nog 240 s open. Elke eerdere vraag duurt 300 s en is daarvoor gesloten.
$now = time();
$n = count($questions);
$base = $now - 60 - ($n - 1) * 300;
$setTimes = $pdo->prepare('UPDATE questions SET opens_at = ?, closes_at = ? WHERE id = ?');
foreach ($questions as $k => $q) {
    $setTimes->execute([$base + $k * 300, $base + ($k + 1) * 300, $q['id']]);
}

// Vaste demo-bezoekers; de uitkomst per vraag is afgeleid van de naam, dus elke run geeft dezelfde stand.
$visitors = ['anna', 'bram', 'cas', 'daan', 'eva', 'fien'];
$answer = $pdo->prepare('INSERT OR IGNORE INTO answers (question_id, visitor_id, option, created_at) VALUES (?, ?, ?, ?)');
$count = 0;
foreach (array_slice($questions, 0, $n - 1) as $q) {
    foreach ($visitors as $v) {
        $hash = crc32("$v-{$q['id']}");
        $option = $hash % 3 === 0 ? ((int) $q['correct_option'] + 1) % 4 : (int) $q['correct_option'];
        $answer->execute([$q['id'], "demo-$v", $option, $now]);
        $count++;
    }
}

echo "demo-quiz: " . ($n - 1) . " vragen gesloten, laatste vraag open, $count antwoorden van demo-bezoekers.\n";
echo "Kijk op /scores.html of /game (de stand verschijnt binnen 1 s).\n";
