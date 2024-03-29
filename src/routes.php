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
// $app->hook('slim.before', function () use ($app) {
//     $app->view()->appendData(array('baseFile' => '/uploads'));
// });
$app->group('/api', function(\Slim\App $app) {

    $app->post('/login', 'App\Routes\Acceso:login');

    $app->post('/registro', 'App\Routes\Acceso:registro');

    $app->post('/validaRegistro', 'App\Routes\Acceso:validaRegistro');

    $app->post('/recuperaPassword', 'App\Routes\Acceso:recuperaPassword');

    $app->post('/validaPassword', 'App\Routes\Acceso:validaPassword');

    $app->post('/actualizaPassword', 'App\Routes\Acceso:actualizaPassword');

    $app->get('/ver_plantilla_correo', 'App\Routes\Acceso:verPlantillaHTML');

    $app->group('/cron', function(\Slim\App $app) {
        $app->post('/envioCorreoPacientesNotifCita', 'App\Routes\CronJobs:envioCorreoPacientesNotifCita');
    });

    $app->group('/platform', function(\Slim\App $app) {
        $app->get('/cargar_perfil_general', 'App\Routes\Cliente:cargar_perfil_general'); // ok

        $app->get('/cargar_familiares', 'App\Routes\Cliente:cargar_familiares'); // ok

        $app->post('/agregar_familiar', 'App\Routes\Cliente:agregar_familiar'); // ok

        $app->post('/editar_familiar', 'App\Routes\Cliente:editar_familiar'); // ok

        $app->post('/anular_familiar', 'App\Routes\Cliente:anular_familiar'); // ok

        $app->get('/cargar_parentesco', 'App\Routes\Cliente:cargar_parentesco'); // ok

        $app->post('/editar_perfil', 'App\Routes\Cliente:editar_perfil'); // ok

        $app->post('/subir_foto', 'App\Routes\Cliente:subir_foto'); // ok

        $app->post('/cambiar_password', 'App\Routes\Cliente:cambiar_password'); // ok

        $app->get('/cargar_paciente', 'App\Routes\Cliente:cargar_paciente'); // ok

        $app->get('/cargar_garante', 'App\Routes\Garante:cargar_garante');

        $app->post('/registrar_cita', 'App\Routes\Cita:registrar_cita');

        $app->post('/registrar_transaccion', 'App\Routes\Cita:registrar_transaccion');

        $app->post('/anular_cita', 'App\Routes\Cita:anular_cita'); // ok

        $app->post('/crear_token_culqi', 'App\Routes\Cita:crear_token_culqi');

        $app->post('/registrar_pago', 'App\Routes\Cita:registrar_pago');

        $app->get('/cargar_citas_pendientes', 'App\Routes\Cita:cargar_citas_pendientes'); // ok

        $app->get('/cargar_citas_realizadas', 'App\Routes\Cita:cargar_citas_realizadas'); // ok

        $app->get('/cargar_especialidades', 'App\Routes\Cita:cargar_especialidades');

        $app->post('/cargar_medicos_por_especialidad', 'App\Routes\Cita:cargar_medicos_por_especialidad');

        $app->post('/cargar_fechas_mock', 'App\Routes\Cita:cargar_fechas_mock');
        
        $app->post('/cargar_fechas_programadas', 'App\Routes\Cita:cargar_fechas_programadas');

        $app->post('/cargar_horario', 'App\Routes\Cita:cargar_horario');

    });
});