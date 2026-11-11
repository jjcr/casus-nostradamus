<?php
declare(strict_types=1);

// POST /order/new
// Eisen: bestelling niet kwijt (201 pas na commit), niet dubbel (idempotency_key),
// voorraad nooit negatief, nooit prijs van de client vertrouwen.
//
// handle() geeft [statuscode, body] terug zonder te printen of te stoppen, zodat de specs
// het direct kunnen aanroepen. create() is de dunne HTTP-kant.
final class OrderController
{
    private const MAX_ITEMS_PER_ORDER = 4;       // stuks per order, samen
    private const MAX_OPEN_PER_VISITOR = 2;      // openstaande orders per bezoeker
    private const RETRY_AFTER_SECONDS = 15;      // hint voor de client bij 503

    public static function create(): never
    {
        [$status, $body] = self::handle(Db::connect(), Http::readJson(), time());
        if (isset($body['retry_after'])) {
            header('Retry-After: ' . $body['retry_after']);
        }
        Http::json($status, $body);
    }

    public static function handle(PDO $pdo, array $input, int $now): array
    {
        $key = self::text($input, 'idempotency_key');
        if ($key === null) {
            return self::fail(400, 'invalid_idempotency_key', 'idempotency_key is verplicht (tekst, max 64 tekens).');
        }

        // 1. Bekende key? Dan altijd de bestaande bestelling teruggeven, ook als de bar op pauze staat.
        $existing = self::findByKey($pdo, $key);
        if ($existing) {
            return [200, self::orderBody($pdo, $existing)];
        }

        // 2. Beschermt Paul: pauze of te veel openstaande orders.
        if (Bar::setting($pdo, 'ordering_paused') === 1) {
            return self::fail(503, 'ordering_paused', 'Bestellen staat tijdelijk uit.', ['retry_after' => self::RETRY_AFTER_SECONDS]);
        }
        if (Bar::openOrders($pdo) >= Bar::setting($pdo, 'max_open_orders')) {
            return self::fail(503, 'bar_busy', 'De bar is vol, probeer zo opnieuw.', ['retry_after' => self::RETRY_AFTER_SECONDS]);
        }

        // 3. Invoer controleren.
        $visitorId = self::text($input, 'visitor_id');
        if ($visitorId === null) {
            return self::fail(400, 'invalid_visitor_id', 'visitor_id is verplicht (tekst, max 64 tekens).');
        }

        $items = self::parseItems($input['items'] ?? null);
        if ($items === null) {
            return self::fail(400, 'invalid_items', 'items: 1 tot ' . self::MAX_ITEMS_PER_ORDER . ' stuks in totaal, elk met product_id en quantity > 0.');
        }

        $groupCode = $input['group_code'] ?? null;
        if ($groupCode !== null) {
            if (!is_string($groupCode) || $groupCode === '' || strlen($groupCode) > 64) {
                return self::fail(400, 'invalid_group_code', 'group_code moet tekst zijn of null.');
            }
            $stmt = $pdo->prepare('SELECT 1 FROM groups WHERE group_code = ?');
            $stmt->execute([$groupCode]);
            if (!$stmt->fetchColumn()) {
                return self::fail(422, 'unknown_group', 'Onbekende group_code.');
            }
        }

        $prices = self::productPrices($pdo, array_keys($items));
        if ($prices === null) {
            return self::fail(422, 'unknown_product', 'Een of meer producten bestaan niet.');
        }

        // Muntjes server-side berekend: nooit een prijs van de client. Afkoopgroep betaalt niets.
        $total = 0;
        foreach ($items as $productId => $quantity) {
            $total += $prices[$productId] * $quantity;
        }
        if ($groupCode !== null) {
            $total = 0;
        }

        // 4. Alles in een transactie. Eerst in de rij (WriteLock), dan BEGIN IMMEDIATE voor het schrijfslot:
        //    bestellingen komen achter elkaar, dus voorraad en de tellers hieronder kloppen ook bij gelijktijdige requests.
        $lock = WriteLock::acquire();
        if ($lock === null) {
            return self::fail(503, 'bar_busy', 'Het systeem is druk, probeer zo opnieuw.', ['retry_after' => self::RETRY_AFTER_SECONDS]);
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Tweede request met dezelfde key was net sneller: die bestelling teruggeven.
            $existing = self::findByKey($pdo, $key);
            if ($existing) {
                $pdo->exec('COMMIT');
                return [200, self::orderBody($pdo, $existing)];
            }

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE visitor_id = ? AND status = 'received'");
            $stmt->execute([$visitorId]);
            if ((int) $stmt->fetchColumn() >= self::MAX_OPEN_PER_VISITOR) {
                $pdo->exec('ROLLBACK');
                return self::fail(429, 'too_many_open_orders', 'Je hebt al ' . self::MAX_OPEN_PER_VISITOR . ' openstaande bestellingen.');
            }

            // Voorraad verlagen kan alleen als er genoeg is. 0 rijen = tekort: alles terugdraaien.
            $takeStock = $pdo->prepare('UPDATE products SET stock = stock - :qty WHERE id = :id AND available = 1 AND stock >= :qty');
            foreach ($items as $productId => $quantity) {
                $takeStock->execute([':qty' => $quantity, ':id' => $productId]);
                if ($takeStock->rowCount() === 0) {
                    $pdo->exec('ROLLBACK');
                    return self::fail(409, 'sold_out', 'Uitverkocht of niet genoeg voorraad.', ['product_id' => $productId]);
                }
            }

            $pdo->prepare('INSERT INTO orders (idempotency_key, visitor_id, group_code, total_coins, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$key, $visitorId, $groupCode, $total, $now]);
            $orderId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare('INSERT INTO order_items (order_id, product_id, quantity, coins) VALUES (?, ?, ?, ?)');
            foreach ($items as $productId => $quantity) {
                $insertItem->execute([$orderId, $productId, $quantity, $prices[$productId]]);
            }

            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        } finally {
            fclose($lock); // volgende schrijver mag
        }

        // Pas hier antwoorden: de bestelling staat op schijf.
        return [201, [
            'order_id' => $orderId,
            'status' => 'received',
            'total_coins' => $total,
            'estimated_wait_minutes' => Bar::estimatedWaitMinutes($pdo),
        ]];
    }

    private static function fail(int $status, string $code, string $message, array $extra = []): array
    {
        return [$status, ['error' => $code, 'message' => $message] + $extra];
    }

    // Tekst van 1 tot 64 tekens, anders null.
    private static function text(array $input, string $field): ?string
    {
        $value = $input[$field] ?? null;
        return is_string($value) && $value !== '' && strlen($value) <= 64 ? $value : null;
    }

    // Geeft [product_id => quantity] of null. Dezelfde product_id twee keer = optellen.
    private static function parseItems(mixed $raw): ?array
    {
        if (!is_array($raw) || $raw === []) {
            return null;
        }
        $items = [];
        $total = 0;
        foreach ($raw as $item) {
            $productId = is_array($item) ? ($item['product_id'] ?? null) : null;
            $quantity = is_array($item) ? ($item['quantity'] ?? null) : null;
            if (!is_int($productId) || !is_int($quantity) || $quantity < 1) {
                return null;
            }
            $items[$productId] = ($items[$productId] ?? 0) + $quantity;
            $total += $quantity;
        }
        return $total <= self::MAX_ITEMS_PER_ORDER ? $items : null;
    }

    // [product_id => muntjes per stuk], of null als een product niet bestaat.
    private static function productPrices(PDO $pdo, array $productIds): ?array
    {
        $marks = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $pdo->prepare("SELECT id, coins FROM products WHERE id IN ($marks)");
        $stmt->execute($productIds);
        $prices = [];
        foreach ($stmt->fetchAll() as $row) {
            $prices[$row['id']] = $row['coins'];
        }
        return count($prices) === count($productIds) ? $prices : null;
    }

    private static function findByKey(PDO $pdo, string $key): array|false
    {
        $stmt = $pdo->prepare('SELECT id, status, total_coins FROM orders WHERE idempotency_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetch();
    }

    private static function orderBody(PDO $pdo, array $order): array
    {
        return [
            'order_id' => $order['id'],
            'status' => $order['status'],
            'total_coins' => $order['total_coins'],
            'estimated_wait_minutes' => Bar::estimatedWaitMinutes($pdo),
        ];
    }
}
