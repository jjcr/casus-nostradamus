<?php
declare(strict_types=1);

// Kindproces voor TestDb::placeInParallel: plaatst een bestelling en print de statuscode.
// Gebruik: php place-order.php <key> <visitor_id> <product_id> <quantity>
require __DIR__ . '/../../src/autoload.php';

[, $key, $visitorId, $productId, $quantity] = $argv;

[$status] = OrderController::handle(Db::connect(), [
    'idempotency_key' => $key,
    'visitor_id' => $visitorId,
    'items' => [['product_id' => (int) $productId, 'quantity' => (int) $quantity]],
], time());

echo $status;
