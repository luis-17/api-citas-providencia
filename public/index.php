<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);
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

require __DIR__.'/../src/culqi_php.php';

// Register routes
require __DIR__ . '/../src/routes.php';


// Seteo configuraciones locales
require __DIR__ . '/../src/config.php';

require __DIR__ . '/../src/upload.php';

require __DIR__ . '/../src/controllers/Acceso.php';
require __DIR__ . '/../src/controllers/CronJobs.php';
require __DIR__ . '/../src/controllers/Cliente.php';
require __DIR__ . '/../src/controllers/Garante.php';
require __DIR__ . '/../src/controllers/Cita.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: *');
// Run app
$app->run();
