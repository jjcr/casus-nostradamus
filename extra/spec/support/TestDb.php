<?php
declare(strict_types=1);

// Hulp voor de specs: een verse SQLite-database per spec, met het echte schema.
final class TestDb
{
    // Maakt een lege database met 3 producten (elk `$stock` op voorraad), 1 afkoopgroep en standaardinstellingen.
    public static function fresh(int $stock = 10): PDO
    {
        // DB_PATH wijzigen = Db::connect() (en kindprocessen) gebruiken deze database.
        putenv('DB_PATH=' . sys_get_temp_dir() . '/klosterke-spec-' . bin2hex(random_bytes(6)) . '.sqlite');

        $pdo = Db::connect();
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/schema.sql'));

        foreach (['ordering_paused' => 0, 'max_open_orders' => 60, 'avg_seconds_per_order' => 30,
                  'batch_size' => 15, 'pickup_timeout_seconds' => 600] as $key => $value) {
            $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute([$key, (string) $value]);
        }

        $insert = $pdo->prepare('INSERT INTO products (id, name, coins, stock) VALUES (?, ?, ?, ?)');
        $insert->execute([1, 'Rode Wijn', 1, $stock]);
        $insert->execute([2, 'Witte Wijn', 1, $stock]);
        $insert->execute([3, 'Kloosterbier', 1, $stock]);

        $pdo->exec("INSERT INTO groups (group_code, name, size) VALUES ('KLOOSTER-001', 'Testgroep', 20)");

        return $pdo;
    }

    public static function stock(PDO $pdo, int $productId): int
    {
        return (int) $pdo->query("SELECT stock FROM products WHERE id = $productId")->fetchColumn();
    }

    public static function count(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    // Een bestelling in een eigen PHP-proces plaatsen. Alle processen starten tegelijk,
    // dus dit test echt gelijktijdige requests tegen dezelfde database.
    // $orders: lijst van [key, visitor_id, product_id, quantity]. Geeft per bestelling de statuscode terug.
    public static function placeInParallel(array $orders): array
    {
        $running = [];
        foreach ($orders as $i => $order) {
            $command = [PHP_BINARY, __DIR__ . '/place-order.php', ...array_map('strval', $order)];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $running[$i] = [$process, $pipes];
        }

        $statuses = [];
        foreach ($running as $i => [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            proc_close($process);
            $statuses[$i] = (int) $output;
            if ($errors !== '') {
                fwrite(STDERR, $errors);
            }
        }
        return $statuses;
    }
}
