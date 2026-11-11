<?php
declare(strict_types=1);

// Zet schrijvers in een rij, vóór ze bij SQLite komen.
//
// SQLite laat maar een schrijver tegelijk toe. Met alleen busy_timeout wachten de PHP-workers door
// steeds even te slapen (oplopend tot 100 ms) en daarna opnieuw te proberen: het schrijfslot staat
// dan vaak vrij terwijl iedereen slaapt, en de doorvoer zakt in als er veel schrijvers wachten.
// Dit slot controleert elke milliseconde en geeft op na een paar seconden, zodat een overbelaste
// bar een snelle 503 geeft (de client probeert opnieuw met dezelfde idempotency_key) en de
// PHP-workers niet allemaal blijven hangen.
final class WriteLock
{
    // Geeft een handle terug (sluiten = vrijgeven) of null als het slot niet op tijd vrijkwam.
    public static function acquire(float $timeoutSeconds = 2.0)
    {
        $handle = fopen(dirname(Config::dbPath()) . '/write.lock', 'c');
        $deadline = microtime(true) + $timeoutSeconds;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);
                return null;
            }
            usleep(1000);
        }
        return $handle;
    }
}
