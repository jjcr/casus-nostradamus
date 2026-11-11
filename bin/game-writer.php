<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

// Elke seconde: verlopen orders opruimen en game.json herschrijven. Alle state staat in SQLite,
// dus na een herstart bouwt de eerste ronde het bestand opnieuw op.
$pdo = null;

while (true) {
    try {
        $pdo ??= Db::connect();
        OrderExpiry::run($pdo, time());
        GameStateWriter::write($pdo, Config::gameJsonPath());
    } catch (Throwable $e) {
        fwrite(STDERR, 'game-writer: ' . $e->getMessage() . "\n");
        $pdo = null; // volgende ronde opnieuw verbinden
    }

    // Slaap tot de volgende hele seconde.
    usleep(1_000_000 - (int) (fmod(microtime(true), 1) * 1_000_000));
}
