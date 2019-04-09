<?php
if (PHP_SAPI == 'cli-server') {
    // To help the built-in PHP dev server, check if the request was actually for
    // something which should probably be served as a static file
    $url  = parse_url($_SERVER['REQUEST_URI']);
    $file = __DIR__ . $url['path'];
    if (is_file($file)) {
        return false;
    }
}

require __DIR__ . '/../vendor/autoload.php';

session_start();

// Instantiate the app
$settings = require __DIR__ . '/../src/settings.php';
$app = new \Slim\App($settings);

// Set up dependencies
require __DIR__ . '/../src/dependencies.php';

// Register middleware
require __DIR__ . '/../src/middleware.php';

// Register routes
require __DIR__ . '/../src/routes.php';


// Seteo configuraciones locales
require __DIR__ . '/../src/config.php';

require __DIR__ . '/../src/upload.php';

require __DIR__ . '/../src/controllers/Acceso.php';
require __DIR__ . '/../src/controllers/Cliente.php';
require __DIR__ . '/../src/controllers/Garante.php';
require __DIR__ . '/../src/controllers/Cita.php';


// Run app
$app->run();
