<?php
declare(strict_types=1);

// Hulpfuncties over de toestand van de bar: instellingen, openstaande orders, verwachte wachttijd.
// "Openstaand" = status 'received' (Paul moet er nog iets mee doen).
final class Bar
{
    public static function setting(PDO $pdo, string $key): int
    {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        return (int) $stmt->fetchColumn();
    }

    public static function openOrders(PDO $pdo): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'received'")->fetchColumn();
    }

    // openstaande orders x gemiddelde tijd per order, naar boven afgerond op minuten
    public static function estimatedWaitMinutes(PDO $pdo): int
    {
        $seconds = self::openOrders($pdo) * self::setting($pdo, 'avg_seconds_per_order');
        return (int) ceil($seconds / 60);
    }
}
