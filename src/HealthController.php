<?php
declare(strict_types=1);

// GET /health. 200 als alles ok, 503 als een check faalt. Body toont per check wat er is.
final class HealthController
{
    // game.json mag maximaal zo oud zijn. game-writer schrijft elke seconde.
    private const GAME_JSON_MAX_AGE = 5;

    public static function show(): never
    {
        $pdo = null;
        try {
            $pdo = Db::connect();
        } catch (Throwable $e) {
            // database-check hieronder meldt de fout
        }

        $checks = [
            'database' => self::checkDatabase($pdo),
            'disk' => self::checkDisk(),
            'clock' => self::checkClock($pdo),
            'game_json' => self::checkGameJson(),
        ];

        $allOk = true;
        foreach ($checks as $check) {
            $allOk = $allOk && $check['ok'];
        }

        Http::json($allOk ? 200 : 503, [
            'status' => $allOk ? 'ok' : 'fail',
            'time' => date('c'),
            'checks' => $checks,
        ]);
    }

    // Kan de database bereikt EN beschreven worden? Een kleine write bewijst dat.
    private static function checkDatabase(?PDO $pdo): array
    {
        if ($pdo === null) {
            return ['ok' => false, 'detail' => 'kan database niet openen'];
        }
        try {
            $rows = $pdo->exec('UPDATE health SET pings = pings + 1 WHERE id = 1');
            if ($rows !== 1) {
                return ['ok' => false, 'detail' => 'health-rij ontbreekt (is seed gedraaid?)'];
            }
            return ['ok' => true, 'detail' => 'lezen en schrijven ok'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    private static function checkDisk(): array
    {
        $dir = dirname(Config::dbPath());
        $free = @disk_free_space($dir);
        if ($free === false) {
            return ['ok' => false, 'detail' => "kan vrije ruimte van $dir niet lezen"];
        }
        $freeMb = (int) ($free / 1024 / 1024);
        $min = Config::minFreeMb();
        return [
            'ok' => $freeMb >= $min,
            'detail' => "$freeMb MB vrij (minimaal $min MB)",
        ];
    }

    // Pi 3 heeft geen RTC en geen internet: na reboot kan de klok ver achterlopen.
    // Twee checks: niet eerder dan de eventdatum, en niet terug t.o.v. de laatste goede meting.
    private static function checkClock(?PDO $pdo): array
    {
        $now = time();
        $min = strtotime(Config::eventDateMin());
        if ($min === false || $now < $min) {
            return ['ok' => false, 'detail' => 'klok loopt achter: ' . date('c', $now)];
        }

        if ($pdo === null) {
            return ['ok' => true, 'detail' => date('c', $now) . ' (laatste-goede-meting niet gecontroleerd)'];
        }

        try {
            $last = (int) $pdo->query('SELECT last_good_at FROM health WHERE id = 1')->fetchColumn();
            if ($now < $last) {
                return ['ok' => false, 'detail' => 'klok staat terug: nu ' . date('c', $now) . ', eerder ' . date('c', $last)];
            }
            $pdo->prepare('UPDATE health SET last_good_at = ? WHERE id = 1')->execute([$now]);
            return ['ok' => true, 'detail' => date('c', $now)];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    // Oud of ontbrekend game.json = game-writer draait niet.
    private static function checkGameJson(): array
    {
        $path = Config::gameJsonPath();
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return ['ok' => false, 'detail' => 'game.json bestaat niet (draait game-writer?)'];
        }
        $age = time() - $mtime;
        return [
            'ok' => $age <= self::GAME_JSON_MAX_AGE,
            'detail' => "$age s oud (maximaal " . self::GAME_JSON_MAX_AGE . ' s)',
        ];
    }
}
