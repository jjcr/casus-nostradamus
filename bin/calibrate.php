<?php
declare(strict_types=1);

// Kalibratie: hoeveel sneller is deze machine dan een Pi 3B, voor een CPU-benchmark?
//
// Er is geen echte Pi 3B om op te meten. Je geeft daarom een BANDBREEDTE op van Pi 3B-scores
// (uit publicaties, zie docs/measurement.md), gemeten met EXACT dezelfde opdracht als $cmd hieronder.
// Gepubliceerde scores lopen sterk uiteen (andere sysbench-versie, tijdslimiet, governor): een
// enkel getal zou schijnzekerheid geven.
//
// Gebruik:
//   PI3_EVENTS_PER_SEC_LOW=<laag> PI3_EVENTS_PER_SEC_HIGH=<hoog> php bin/calibrate.php
// (alleen _LOW invullen mag ook: dan is de band een punt)

$low = (float) getenv('PI3_EVENTS_PER_SEC_LOW');
$high = (float) getenv('PI3_EVENTS_PER_SEC_HIGH') ?: $low;
if ($low <= 0 || $high < $low) {
    fwrite(STDERR, "Zet PI3_EVENTS_PER_SEC_LOW en PI3_EVENTS_PER_SEC_HIGH (events/sec van een Pi 3B, laag <= hoog).\n");
    fwrite(STDERR, "Opdracht: sysbench cpu --cpu-max-prime=20000 --threads=1 --time=10 run\n");
    exit(2);
}

$cmd = 'sysbench cpu --cpu-max-prime=20000 --threads=1 --time=10 run 2>&1';
$runs = [];
for ($i = 1; $i <= 3; $i++) {
    $output = (string) shell_exec($cmd);
    if (!preg_match('/events per second:\s+([\d.]+)/', $output, $m)) {
        fwrite(STDERR, "Kan sysbench-uitvoer niet lezen:\n$output\n");
        exit(1);
    }
    $runs[] = (float) $m[1];
    echo "run $i: {$m[1]} events/sec\n";
}

sort($runs);
$local = $runs[1]; // mediaan van 3

// factor = hoeveel van deze machine een Pi-core waard is. Lage Pi-score = kleine factor = weinig cpu.
$factorLow = $low / $local;
$factorHigh = $high / $local;

echo "\nmediaan hier : $local events/sec\n";
echo "Pi 3B (band) : $low - $high events/sec\n";
echo "factor       : " . round($factorLow, 3) . " - " . round($factorHigh, 3) . " (Pi-core t.o.v. een core hier)\n";
echo "\n4 Pi-cores komen overeen met " . round(4 * $factorLow, 2) . " tot " . round(4 * $factorHigh, 2) . " cores hier.\n";
echo "Draai de load-test op beide grenzen:\n";
echo "  APP_CPUS=" . round(4 * $factorLow, 2) . " docker compose --profile loadtest run --rm k6\n";
echo "  APP_CPUS=" . round(4 * $factorHigh, 2) . " docker compose --profile loadtest run --rm k6\n";
echo "\nLet op: single-thread CPU-benchmark, geen PHP/nginx/SQLite-werk. Geen SD I/O, netwerk of throttling.\n";
echo "Daarnaast: de Pi-band zelf is onzeker. Zie docs/measurement.md.\n";
