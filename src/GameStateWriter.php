<?php
declare(strict_types=1);

// Bouwt de spelstand + barstatus uit SQLite en schrijft die naar game.json.
// nginx serveert dat bestand als GET /game: 1200 telefoons lezen een bestand, geen PHP, geen database.
final class GameStateWriter
{
    private const TOP_SCORES = 10;
    private const MAX_READY_LISTED = 200;
    private const MAX_EXPIRED_LISTED = 200;

    public static function build(PDO $pdo, int $now): array
    {
        // Open vraag = nu tussen opens_at en closes_at. correct_option gaat NIET mee naar de client.
        $stmt = $pdo->prepare(
            'SELECT id, text, options, closes_at FROM questions
             WHERE opens_at <= :now AND closes_at > :now
             ORDER BY opens_at LIMIT 1'
        );
        $stmt->execute([':now' => $now]);
        $q = $stmt->fetch();

        $question = null;
        $remaining = 0;
        if ($q) {
            $question = [
                'id' => $q['id'],
                'text' => $q['text'],
                'options' => json_decode($q['options'], true),
            ];
            // Server-autoritatief: de client telt alleen af tot de volgende poll.
            $remaining = max(0, $q['closes_at'] - $now);
        }

        // Score = aantal goede antwoorden op gesloten vragen.
        $stmt = $pdo->prepare(
            'SELECT a.visitor_id, COUNT(*) AS score
             FROM answers a
             JOIN questions q ON q.id = a.question_id
             WHERE a.option = q.correct_option AND q.closes_at <= :now
             GROUP BY a.visitor_id
             ORDER BY score DESC, a.visitor_id
             LIMIT ' . self::TOP_SCORES
        );
        $stmt->execute([':now' => $now]);
        $scores = $stmt->fetchAll();

        // Orders die klaar staan op de afhaaltafel: bezoeker ziet "order 47 staat klaar".
        $ready = $pdo->query(
            "SELECT id FROM orders WHERE status = 'ready' ORDER BY id LIMIT " . self::MAX_READY_LISTED
        )->fetchAll(PDO::FETCH_COLUMN);

        // Niet opgehaald en vervallen: de bezoeker moet dat zien, anders blijft zijn order voor altijd "wordt gemaakt".
        // Alleen de laatste orders, zodat het bestand klein blijft (het wordt 1200 keer per paar seconden gelezen).
        $expired = $pdo->query(
            "SELECT id FROM orders WHERE status = 'expired' ORDER BY id DESC LIMIT " . self::MAX_EXPIRED_LISTED
        )->fetchAll(PDO::FETCH_COLUMN);

        // Producten voor de bestelpagina. `available` = bestelbaar: niet uitgezet en nog op voorraad.
        // De exacte voorraad gaat niet mee naar de client.
        $products = [];
        foreach ($pdo->query('SELECT id, name, coins, available, stock FROM products ORDER BY id') as $row) {
            $products[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'coins' => $row['coins'],
                'available' => $row['available'] === 1 && $row['stock'] > 0,
            ];
        }

        return [
            'question' => $question,
            'remaining_seconds' => $remaining,
            'scores' => $scores,
            'products' => $products,
            'ordering_paused' => Bar::setting($pdo, 'ordering_paused') === 1,
            'estimated_wait_minutes' => Bar::estimatedWaitMinutes($pdo),
            'ready_orders' => array_map('intval', $ready),
            'expired_orders' => array_map('intval', $expired),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        ];
    }

    // Atomair: eerst naar een tijdelijk bestand, dan rename. Lezers zien nooit een half bestand.
    public static function write(PDO $pdo, string $path): void
    {
        $json = json_encode(self::build($pdo, time()), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException("kan $tmp niet schrijven");
        }
        rename($tmp, $path);
    }
}
