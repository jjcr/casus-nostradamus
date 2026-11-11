<?php
declare(strict_types=1);

// Interne routes voor Paul's barscherm (public/bar.html):
//   GET  /bar/batch  de volgende batch om te tappen + status van de bar
//   POST /bar/ready  een batch in een tik op "klaar" zetten
//   POST /bar/pause  bestellen aan/uit
//
// Let op: geen inlog. Wie het adres kent kan bestellen pauzeren of op "klaar" zetten (zie docs/implementatie.md, risico's).
final class BarController
{
    private const MAX_READY_PER_CALL = 100;

    public static function batch(): never
    {
        $pdo = Db::connect();

        // Batch = de oudste openstaande orders, zoveel als batch_size.
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'received' ORDER BY id LIMIT ?");
        $stmt->execute([Bar::setting($pdo, 'batch_size')]);
        $orderIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $batch = null;
        if ($orderIds !== []) {
            // Tapopdracht: per product het totaal over alle orders in de batch.
            $marks = implode(',', array_fill(0, count($orderIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT p.id AS product_id, p.name, SUM(oi.quantity) AS quantity
                 FROM order_items oi JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id IN ($marks)
                 GROUP BY p.id ORDER BY p.id"
            );
            $stmt->execute($orderIds);
            $batch = [
                'order_ids' => $orderIds,
                'first_order' => $orderIds[0],
                'last_order' => $orderIds[count($orderIds) - 1],
                'products' => $stmt->fetchAll(),
            ];
        }

        Http::json(200, [
            'ordering_paused' => Bar::setting($pdo, 'ordering_paused') === 1,
            'open_orders' => Bar::openOrders($pdo),
            'estimated_wait_minutes' => Bar::estimatedWaitMinutes($pdo),
            'batch' => $batch,
            'stock' => $pdo->query('SELECT id, name, stock, available FROM products ORDER BY id')->fetchAll(),
        ]);
    }

    // Body: { "order_ids": [41, 42, ...] }. Alleen orders die nog 'received' zijn worden klaar.
    public static function ready(): never
    {
        $ids = Http::readJson()['order_ids'] ?? null;
        if (!is_array($ids) || $ids === [] || count($ids) > self::MAX_READY_PER_CALL || array_filter($ids, 'is_int') !== $ids) {
            Http::error(400, 'invalid_order_ids', 'order_ids moet een lijst met hele getallen zijn.');
        }

        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Db::connect()->prepare(
            "UPDATE orders SET status = 'ready', ready_at = ? WHERE status = 'received' AND id IN ($marks)"
        );
        $stmt->execute([time(), ...$ids]);

        Http::json(200, ['marked_ready' => $stmt->rowCount()]);
    }

    // Body: { "paused": true|false }
    public static function pause(): never
    {
        $paused = Http::readJson()['paused'] ?? null;
        if (!is_bool($paused)) {
            Http::error(400, 'invalid_paused', 'paused moet true of false zijn.');
        }

        Db::connect()->prepare("UPDATE settings SET value = ? WHERE key = 'ordering_paused'")
            ->execute([$paused ? '1' : '0']);

        Http::json(200, ['ordering_paused' => $paused]);
    }
}
