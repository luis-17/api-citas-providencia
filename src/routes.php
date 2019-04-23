<?php

use Slim\Http\Request;
use Slim\Http\Response;
// use Slim\Http\UploadedFile;
use \Slim\Middleware\JwtAuthentication;
// use \Firebase\JWT\JWT;
// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;

// Routes

$app->get('/[{name}]', function (Request $request, Response $response, array $args) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/' route");

    // Render index view
    return $this->renderer->render($response, 'index.phtml', $args);
});

$app->post('/login', 'App\Routes\Acceso:login');

$app->post('/registro', 'App\Routes\Acceso:registro');

$app->post('/validaRegistro', 'App\Routes\Acceso:validaRegistro');

$app->post('/recuperaPassword', 'App\Routes\Acceso:recuperaPassword');

$app->post('/validaPassword', 'App\Routes\Acceso:validaPassword');

$app->post('/actualizaPassword', 'App\Routes\Acceso:actualizaPassword');

$app->group('/api', function(\Slim\App $app) {

    $app->get('/cargar_perfil_general', 'App\Routes\Cliente:cargar_perfil_general' );

    $app->get('/cargar_familiares', 'App\Routes\Cliente:cargar_familiares');

    $app->post('/agregar_familiar', 'App\Routes\Cliente:agregar_familiar');

    $app->post('/editar_familiar', 'App\Routes\Cliente:editar_familiar');

    $app->post('/anular_familiar', 'App\Routes\Cliente:anular_familiar');

    $app->get('/cargar_parentesco', 'App\Routes\Cliente:cargar_parentesco');

    $app->post('/editar_perfil', 'App\Routes\Cliente:editar_perfil');

    $app->post('/subir_foto', 'App\Routes\Cliente:subir_foto');

    $app->post('/cambiar_password', 'App\Routes\Cliente:cambiar_password');

    $app->get('/cargar_paciente', 'App\Routes\Cliente:cargar_paciente');

    $app->get('/cargar_garante', 'App\Routes\Garante:cargar_garante');

    $app->post('/registrar_cita', 'App\Routes\Cita:registrar_cita');

    $app->post('/crear_token_culqi', 'App\Routes\Cita:crear_token_culqi');

    $app->post('/registrar_pago', 'App\Routes\Cita:registrar_pago');
});