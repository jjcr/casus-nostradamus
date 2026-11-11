<?php
declare(strict_types=1);

// Klasse "Foo" wordt geladen uit src/Foo.php. Geen composer nodig voor de app zelf.
// is_file: andere loaders (bijv. die van de tests) vragen ook om klassen die hier niet staan.
spl_autoload_register(function (string $class): void {
    $file = __DIR__ . '/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});
