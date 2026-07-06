<?php

/**
 * PHPUnit bootstrap — works both inside the Typemill Docker container
 * and in a local environment (no Typemill checkout required).
 *
 * Docker:  loads Typemill's Composer autoloader and registers the Plugins\ namespace.
 * Local:   loads the local Composer autoloader (tests/php/vendor/autoload.php),
 *          then loads a stub for Typemill\Models\StorageWrapper so that plugin
 *          classes can be instantiated without a Typemill installation.
 *
 * PHPUNIT_CONTENT_ROOT is defined so that filesystem-touching tests know where
 * to create temporary directories:
 *   - Docker:  /var/www/html/content  (StorageWrapper resolves 'contentFolder' here)
 *   - Local:   sys_get_temp_dir()     (the stub's getFolderPath() returns this)
 */

// vendor location moved to system/vendor in newer Typemill versions; accept either
$typemillAutoloader = is_file('/var/www/html/vendor/autoload.php')
    ? '/var/www/html/vendor/autoload.php'
    : '/var/www/html/system/vendor/autoload.php';
$localAutoloader    = __DIR__ . '/vendor/autoload.php';

if (is_file($typemillAutoloader)) {
    // -------------------------------------------------------------------------
    // Docker path — Typemill is fully available inside the container.
    // -------------------------------------------------------------------------
    require $typemillAutoloader;

    define('PHPUNIT_CONTENT_ROOT', '/var/www/html/content');
    $pluginsRoot = '/var/www/html/plugins';

} elseif (is_file($localAutoloader)) {
    // -------------------------------------------------------------------------
    // Local path — no Typemill installation.
    // Load the PHPUnit installed via `composer install` in tests/php/,
    // then load the StorageWrapper stub so plugin classes can be instantiated.
    // -------------------------------------------------------------------------
    require $localAutoloader;
    require __DIR__ . '/stubs/TypemillStubs.php';

    define('PHPUNIT_CONTENT_ROOT', sys_get_temp_dir());
    $pluginsRoot = dirname(__DIR__, 2) . '/plugins';

} else {
    throw new \RuntimeException(
        "No autoloader found.\n" .
        "  Docker (recommended): npm run test:php\n" .
        "  Local (no Docker):    npm run test:php:local:setup && npm run test:php:local\n"
    );
}

// Register a PSR-4-style autoloader for the Plugins\ namespace so that plugin
// classes are resolvable without Typemill's plugin-discovery mechanism.
//
// The namespace prefix 'Plugins\' maps to $pluginsRoot, so it must be stripped
// before building the file path:
//   Plugins\versions\Models\VersionStore → $pluginsRoot/versions/Models/VersionStore.php
spl_autoload_register(function (string $class) use ($pluginsRoot): void {
    if (!str_starts_with($class, 'Plugins\\')) {
        return;
    }
    $relative = substr($class, strlen('Plugins\\'));
    $path = $pluginsRoot . DIRECTORY_SEPARATOR
          . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
