<?php
// DIC configuration

$container = $app->getContainer();

// view renderer
$container['renderer'] = function ($c) {
    $settings = $c->get('settings')['renderer'];
    return new Slim\Views\PhpRenderer($settings['template_path']);
};

// monolog
$container['logger'] = function ($c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Monolog\Logger($settings['name']);
    $logger->pushProcessor(new Monolog\Processor\UidProcessor());
    $logger->pushHandler(new Monolog\Handler\StreamHandler($settings['path'], $settings['level']));
    return $logger;
};

// PDO database library
$container['db'] = function ($c) {
    $settings = $c->get('settings')['db'];
    $pdo = new PDO("mysql:host=" . $settings['host'] . ";dbname=" . $settings['dbname'],
    $settings['user'], $settings['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
};

// PDO database library SQL SERVER
$container['db_mssql'] = function ($c) {
    $settings = $c->get('settings')['db_mssql'];
    $pdo = new PDO("odbc:Driver={SQL Server};Server=" . $settings['host'] . "; Database=" . $settings['dbname'],
        $settings['user'], $settings['pass']);
    // $pdo = new PDO("sqlsrv:Server=" . $settings['host'] . "; Database=" . $settings['dbname'],
    //     $settings['user'], $settings['pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
};


$container['upload_directory'] = function($c){
    $settings = $c->get('settings')['upload_directory'];
    return $settings['path'];
};

$container['validator'] = function () {
    return new Awurth\SlimValidation\Validator();
};
