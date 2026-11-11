<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

// Maakt een consistente kopie van de database (ook terwijl er bestellingen binnenkomen).
// VACUUM INTO doet hetzelfde als sqlite3 ".backup", zonder sqlite3-programma nodig te hebben.
// Wordt aangeroepen door ./bin/klosterke backup. Print het pad van de kopie.
$dir = dirname(Config::dbPath()) . '/backups';
if (!is_dir($dir)) {
    mkdir($dir, 0755);
}

$file = $dir . '/klosterke-' . date('Ymd-His') . '.sqlite';
Db::connect()->exec("VACUUM INTO '$file'");
echo $file . "\n";
