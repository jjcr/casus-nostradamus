<?php
declare(strict_types=1);

final class Db
{
    public static function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . Config::dbPath(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Pragma's gelden per connectie, dus bij elke connect.
        $pdo->exec('PRAGMA busy_timeout = 5000');   // wacht op lock i.p.v. direct falen
        $pdo->exec('PRAGMA journal_mode = WAL');    // lezers blokkeren schrijver niet
        $pdo->exec('PRAGMA synchronous = FULL');    // commit = op schijf (stroomuitval-veilig)
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
