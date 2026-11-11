<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

// Simpele router: methode + pad. nginx stuurt alleen de PHP-routes hierheen (zie docker/nginx.conf).
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

try {
    match ("$method $path") {
        'GET /health' => HealthController::show(),
        'POST /order/new' => OrderController::create(),
        'GET /bar/batch' => BarController::batch(),
        'POST /bar/ready' => BarController::ready(),
        'POST /bar/pause' => BarController::pause(),
        // Alleen beschreven, niet gebouwd: zie docs/implementatie.md
        'GET /order/status', 'POST /game' => Http::error(501, 'not_implemented', 'Niet gebouwd, zie docs/implementatie.md.'),
        default => Http::error(404, 'not_found', 'Onbekend endpoint.'),
    };
} catch (PDOException $e) {
    // Meestal "database is locked" na busy_timeout. Client mag opnieuw proberen met dezelfde key.
    error_log('database: ' . $e->getMessage());
    header('Retry-After: 1');
    Http::error(503, 'database_unavailable', 'Database tijdelijk niet beschikbaar, probeer opnieuw.');
} catch (Throwable $e) {
    error_log('fout: ' . $e->getMessage());
    Http::error(500, 'internal_error', 'Interne fout.');
}
