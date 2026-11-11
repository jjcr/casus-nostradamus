#!/bin/sh
# Draait de load-test op een EIGEN stack (project "klosterke-load", poort 8081, eigen volumes).
# De echte database en voorraad blijven onaangeroerd. De test-stack wordt daarna weggegooid.
# Resultaat: ook in loadtest/results/laatste-run.txt.
set -eu
cd "$(dirname "$0")/.."

export HTTP_PORT=8081
COMPOSE="docker compose -p klosterke-load"
trap '$COMPOSE --profile loadtest down -v' EXIT

mkdir -p loadtest/results
$COMPOSE up -d --build

# De test bestelt veel meer dan er op voorraad is: voorraad en limiet voor de meting omhoog.
$COMPOSE exec -T -u www-data php php <<'PHP'
<?php
require 'src/autoload.php';
$pdo = Db::connect();
$pdo->exec('UPDATE products SET stock = 1000000000');
$pdo->exec("UPDATE settings SET value = '1000000000' WHERE key = 'max_open_orders'");
PHP

# --no-deps: anders maakt compose php opnieuw aan en houdt nginx een oud IP-adres vast (502)
# Uitvoer eerst naar het bestand, zodat de exitcode van k6 (99 = drempel gefaald) bewaard blijft.
rc=0
$COMPOSE --profile loadtest run --rm --no-deps k6 > loadtest/results/laatste-run.txt 2>&1 || rc=$?
cat loadtest/results/laatste-run.txt
exit $rc
