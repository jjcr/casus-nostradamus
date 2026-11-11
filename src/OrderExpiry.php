<?php
declare(strict_types=1);

// Klare orders die niet worden opgehaald verlopen vanzelf: Paul zet alleen "klaar", er is geen stap "opgehaald".
// Een verlopen order geeft zijn voorraad terug. Draait elke seconde in bin/game-writer.php.
final class OrderExpiry
{
    // Geeft het aantal verlopen orders terug.
    public static function run(PDO $pdo, int $now): int
    {
        $cutoff = $now - Bar::setting($pdo, 'pickup_timeout_seconds');

        // Eerst zonder schrijfslot kijken: meestal is er niets te doen.
        $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE status = 'ready' AND ready_at <= ? LIMIT 1");
        $stmt->execute([$cutoff]);
        if (!$stmt->fetchColumn()) {
            return 0;
        }

        $lock = WriteLock::acquire(10.0);
        if ($lock === null) {
            return 0; // volgende seconde opnieuw
        }
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            // Opnieuw selecteren binnen het slot: een andere writer kan het al gedaan hebben.
            $stmt = $pdo->prepare("SELECT id FROM orders WHERE status = 'ready' AND ready_at <= ?");
            $stmt->execute([$cutoff]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $items = $pdo->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = ?');
            $giveBack = $pdo->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
            $expire = $pdo->prepare("UPDATE orders SET status = 'expired', expired_at = ? WHERE id = ?");

            foreach ($ids as $orderId) {
                $items->execute([$orderId]);
                foreach ($items->fetchAll() as $item) {
                    $giveBack->execute([$item['quantity'], $item['product_id']]);
                }
                $expire->execute([$now, $orderId]);
            }

            $pdo->exec('COMMIT');
            return count($ids);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $e;
        } finally {
            fclose($lock);
        }
    }
}
