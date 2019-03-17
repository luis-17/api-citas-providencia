<?php

use Slim\Http\Request;
use Slim\Http\Response;
// use Slim\Http\UploadedFile;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Routes

$app->get('/[{name}]', function (Request $request, Response $response, array $args) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/' route");

    // Render index view
    return $this->renderer->render($response, 'index.phtml', $args);
});

$app->post('/login', function (Request $request, Response $response, array $args) {

    $input = $request->getParsedBody();
    $sql = "
        SELECT
            us.idusuario,
            us.username,
            us.password,
            cl.idcliente
        FROM usuario AS us
        JOIN cliente cl ON us.idusuario = cl.idusuario
        WHERE us.username = :username
        AND us.estado_us = 1
        AND us.flag_mail_confirm = 2
        LIMIT 1";
    $resultado = $this->db->prepare($sql);
    $resultado->bindParam("username", $input['username']);
    $resultado->execute();
    $user = $resultado->fetchObject();

    // verify email address.
    if(!$user) {
        return $this->response->withJson(['error' => true, 'message' => 'El usuario no existe o aún no está validado.']);
    }

    // verify password.
    if (!password_verify($input['password'],$user->password)) {
        return $this->response->withJson(['error' => true, 'message' => 'La contraseña no es válida.']);
    }

    $settings = $this->get('settings')['jwt']; // get settings array.
    $time = time();
    $token = JWT::encode(
        [
            'idusuario' => $user->idusuario,
            'username' => $user->username,
            'idcliente' => $user->idcliente,
            'ini' => $time,
            'exp' => $time + (60*60)
        ],
        $settings['secret'],
        $settings['encrypt']
    );
    //Actualizar el ultimo inicio de sesion y de IP
    $ult_inicio_sesion = date('Y-m-d H:i:s');
    $ult_ip_address = $request->getAttribute('ip_address');

    $sql = "UPDATE usuario SET
                ult_inicio_sesion   = :ult_inicio_sesion,
                ult_ip_address      = :ult_ip_address
            WHERE idusuario = $user->idusuario
        ";

        $resultado = $this->db->prepare($sql);
        $resultado->bindParam(':ult_inicio_sesion', $ult_inicio_sesion);
        $resultado->bindParam(':ult_ip_address', $ult_ip_address);

        $resultado->execute();

    return $this->response->withJson(['token' => $token]);

});
/**
 * Servicio que realiza el registro de un nuevo usuario
 * Envia un  correo de confirmación
 *
 * Creado: 12/03/2019
 * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
 */
$app->post('/registro', function(Request $request, Response $response){
    // $input = $request->getParsedBody();
    $username           = $request->getParam('username');
    $nombres            = $request->getParam('nombres');
    $apellido_paterno   = $request->getParam('apellido_paterno');
    $apellido_materno   = $request->getParam('apellido_materno');
    $tipo_documento     = $request->getParam('tipo_documento');
    $numero_documento   = $request->getParam('numero_documento');
    $correo             = $request->getParam('correo');
    $fecha_nacimiento   = $request->getParam('fecha_nacimiento');
    $sexo               = $request->getParam('sexo');

    $password  = password_hash($request->getParam('password'),PASSWORD_DEFAULT);
    $ult_ip_address = $request->getAttribute('ip_address');
    $createdAt = date('Y-m-d H:i:s');
    $updatedAt = date('Y-m-d H:i:s');

    // VALIDACIONES
    $sql = "
        SELECT
            us.idusuario,
            us.username,
            us.password
        FROM usuario AS us
        WHERE us.username = :username
        LIMIT 1
    ";

    $resultado = $this->db->prepare($sql);
    $resultado->bindParam(":username", $username);
    $resultado->execute();
    $usuario = $resultado->fetchObject();

    if($usuario){
        return $this->response->withJson([
            'flag' => 0,
            'message' => "El usuario ya existe"
        ]);
    }

    $sql = "INSERT INTO usuario (
        username,
        password,
        ult_ip_address,
        createdAt,
        updatedAt
    ) VALUES (
        :username,
        :password,
        :ult_ip_address,
        :createdAt,
        :updatedAt
    )";

    $sql2 = "INSERT INTO cliente (
        idusuario,
        nombres,
        apellido_paterno,
        apellido_materno,
        tipo_documento,
        numero_documento,
        correo,
        fecha_nacimiento,
        sexo,
        createdAt,
        updatedAt
    ) VALUES (
        :idusuario,
        :nombres,
        :apellido_paterno,
        :apellido_materno,
        :tipo_documento,
        :numero_documento,
        :correo,
        :fecha_nacimiento,
        :sexo,
        :createdAt,
        :updatedAt
    )";

    try {
        // REGISTRO DE USUARIO
        $resultado = $this->db->prepare($sql);
        $resultado->bindParam(':username', $username);
        $resultado->bindParam(':password', $password);
        $resultado->bindParam(':ult_ip_address', $ult_ip_address);
        $resultado->bindParam(':createdAt', $createdAt);
        $resultado->bindParam(':updatedAt', $updatedAt);

        $resultado->execute();
        $idusuario =  $this->db->lastInsertId();

        // REGISTRO DE CLIENTE
        $resultado = $this->db->prepare($sql2);
        $resultado->bindParam(':idusuario', $idusuario);
        $resultado->bindParam(':nombres', $nombres);
        $resultado->bindParam(':apellido_paterno', $apellido_paterno);
        $resultado->bindParam(':apellido_materno', $apellido_materno);
        $resultado->bindParam(':tipo_documento', $tipo_documento);
        $resultado->bindParam(':numero_documento', $numero_documento);
        $resultado->bindParam(':correo', $correo);
        $resultado->bindParam(':fecha_nacimiento', $fecha_nacimiento);
        $resultado->bindParam(':sexo', $sexo);
        $resultado->bindParam(':createdAt', $createdAt);
        $resultado->bindParam(':updatedAt', $updatedAt);

        $resultado->execute();
        $idcliente =  $this->db->lastInsertId();


        // ENVIAR CORREO PARA VERIFICAR
            $settings = $this->get('settings'); // get settings array.
            $time = time();
            $token = JWT::encode(
                [
                    'idusuario' => $idusuario,
                    'username' => $username,
                    'iat' => $time,
                    'exp' => $time + (60*60)
                ],
                $settings['jwt']['secret'],
                $settings['jwt']['encrypt']
            );
            // $para = $correo;
            $paciente = ucwords(strtolower( $nombres . ' ' . $apellido_paterno . ' ' . $apellido_materno));
            $fromAlias = 'Clínica Providencia';

            $asunto = 'Confirma tu cuenta de Clínica Providencia';
            $mensaje = '<html lang="es">';
            $mensaje .= '<body style="font-family: sans-serif;padding: 10px 40px;" >';
            $mensaje .= '<div style="max-width: 700px;align-content: center;margin-left: auto; margin-right: auto;padding-left: 5%; padding-right: 5%;">';
            $mensaje .= '	<div style="font-size:16px;">
                                Estimado(a) paciente: '.$paciente .', <br /> <br /> ';

            $mensaje .= '     <a href="' . BASE_URL . 'public/validaRegistro/'. $token .'">Haz clic aquí para continuar con el proceso de registro.</a>';
            $mensaje .= '    </div>';
            $mensaje .= '    <div>
                                <p>Si no has solicitado la suscripción a este correo electrónico, ignóralo y la suscripción no se activará.</p>
                            </div>';
            $mensaje .=  '</div>';
            $mensaje .= '</body>';
            $mensaje .= '</html>';

            $mail = new PHPMailer();
            $mail->IsSMTP(true);
            $mail->SMTPAuth = true;
            //$mail->SMTPDebug = true;
            $mail->SMTPSecure = "tls";
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->Username =  SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;
            $mail->SetFrom(SMTP_USERNAME,$fromAlias);
            $mail->AddReplyTo(SMTP_USERNAME,$fromAlias);
            $mail->Subject = $asunto;
            $mail->IsHTML(true);
            $mail->AltBody = $mensaje;
            $mail->MsgHTML($mensaje);
            $mail->CharSet = 'UTF-8';
            $mail->AddAddress($correo);

            if($mail->Send()){

            }else{
                print_r("No se envio correo");
            }



        return $this->response->withJson([
            'flag' => 1,
            'message' => "El registro fue satisfactorio. Recibirás un mensaje en el correo para verificar la cuenta. En caso de no verlo en tu bandeja de entrada, no olvides revisar la bandeja de spam."
        ]);

    } catch (PDOException $e) {
        // echo '{ "error" : { "text" : ' . $e->getMessage() . ' } }';
        return $this->response->withJson([
            'flag' => 0,
            'message' => "Ocurrió un error. " . $e->getMessage()
        ]);
    }
});
/**
 * Servicio para validar el registro de un usuario
 * Actualiza la tabla usuario para habilitarlo
 *
 * Creado: 13/03/19
 * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
 */
$app->get('/validaRegistro/{tkn}', function(Request $request, Response $response, array $args){
    $token = $request->getAttribute('tkn');
    $settings = $this->get('settings')['jwt'];
    if(empty($token))
    {
        throw new Exception("Invalido token.");
    }
    try {
        $decode = JWT::decode(
            $token,
            $settings['secret'],
            array($settings['encrypt'])
        );
        $idusuario = $decode->idusuario;

        $sql = "UPDATE usuario SET flag_mail_confirm = 2 WHERE idusuario = $idusuario";
        try {
            $resultado = $this->db->prepare($sql);
            $resultado->execute();

            return $this->response->withJson([
                'flag' => 1,
                'message' => "Tu cuenta ha sido verificada exitosamente... Ya puedes iniciar sesión para comenzar a disfrutar los beneficios de ser un paciente de Clínica Providencia!."
            ]);
        } catch (PDOException $e) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Ocurrió un error. Inténtelo nuevamente."
            ]);
        }
        return $this->response->withJson($decode);
        //code...
    } catch (\Exception $th) {
        return $this->response->withJson([
            'flag' => 0,
            'message' => "El enlace no es válido o ya no está disponible."
        ]);
    }
});

/**
 * Servicio para recuperar una contraseña atraves del numero de DNI
 *
 * Creado: 14/03/2019
 * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
 */
$app->post('/recuperaPassword', function(Request $request, Response $response){
    $numero_documento = $request->getParam('numero_documento');

    $sql = "
        SELECT
            us.idusuario,
            us.username,
            us.password,
            cl.idcliente,
            cl.nombres,
            cl.apellido_paterno,
            cl.apellido_materno,
            cl.tipo_documento,
            cl.numero_documento,
            cl.correo
        FROM usuario AS us
        JOIN cliente cl ON us.idusuario = cl.idusuario
        WHERE cl.numero_documento = :numero_documento
        LIMIT 1
    ";

    $resultado = $this->db->prepare($sql);
    $resultado->bindParam(":numero_documento", $numero_documento);
    $resultado->execute();
    $user = $resultado->fetchObject();

    if( empty($user) ){
        return $this->response->withJson([
            'flag' => 0,
            'message' => "El número de documento no se encuentra registrado en el sistema."
        ]);
    }

    // ENVIAR CORREO PARA VERIFICAR
        $settings = $this->get('settings'); // get settings array.
        $time = time();
        $token = JWT::encode(
            [
                'idusuario' => $user->idusuario,
                'numero_documento' => $user->numero_documento,
                'iat' => $time,
                'exp' => $time + (60*60)
            ],
            $settings['jwt']['secret'],
            $settings['jwt']['encrypt']
        );
        // $para = $correo;
        $paciente = ucwords(strtolower(  $user->nombres . ' ' .  $user->apellido_paterno . ' ' .  $user->apellido_materno));
        $fromAlias = 'Clínica Providencia';

        $asunto = 'Olvidó su contraseña.';
        $mensaje = '<html lang="es">';
        $mensaje .= '<body style="font-family: sans-serif;padding: 10px 40px;" >';
        $mensaje .= '<div style="max-width: 700px;align-content: center;margin-left: auto; margin-right: auto;padding-left: 5%; padding-right: 5%;">';
        $mensaje .= '	<div style="font-size:16px;">
                            Estimado(a) paciente: '.$paciente .', <br /> <br /> ';

        $mensaje .= '     <a href="' . BASE_URL . 'public/validaPassword/'. $token .'">Haz clic aquí para continuar con el proceso de recuperación de contraseña.</a>';
        $mensaje .= '    </div>';
        $mensaje .= '    <div>
                            <p>Si no has solicitado el cambio de contraseña a este correo electrónico, ignóralo y el cambio no se realizará..</p>
                        </div>';
        $mensaje .=  '</div>';
        $mensaje .= '</body>';
        $mensaje .= '</html>';

        $mail = new PHPMailer();
        $mail->IsSMTP(true);
        $mail->SMTPAuth = true;
        //$mail->SMTPDebug = true;
        $mail->SMTPSecure = "tls";
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->Username =  SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SetFrom(SMTP_USERNAME,$fromAlias);
        $mail->AddReplyTo(SMTP_USERNAME,$fromAlias);
        $mail->Subject = $asunto;
        $mail->IsHTML(true);
        $mail->AltBody = $mensaje;
        $mail->MsgHTML($mensaje);
        $mail->CharSet = 'UTF-8';
        $mail->AddAddress( $user->correo);

        if($mail->Send()){
            return $this->response->withJson([
                'flag' => 1,
                'message' => "Recibirás un mensaje en el correo para cambiar su contraseña. En caso de no verlo en tu bandeja de entrada, no olvides revisar la bandeja de spam."
            ]);

        }else{
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Ocurrió un error en el envio de correo. Inténtelo nuevamente"
            ]);
        }

});

/**
 * Servicio para validar el correo de cambio de contraseña
 * Retorna un OK si el token es válido
 *
 * Creado: 14/03/19
 * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
 */
$app->get('/validaPassword/{tkn}', function(Request $request, Response $response, array $args){
    $token = $request->getAttribute('tkn');
    $settings = $this->get('settings')['jwt'];
    if(empty($token))
    {
        throw new Exception("Invalido token.");
    }
    try {
        $decode = JWT::decode(
            $token,
            $settings['secret'],
            array($settings['encrypt'])
        );
        $idusuario = $decode->idusuario;


        return $this->response->withJson([
            'flag' => 0,
            'message' => "El token es válido."
        ]);
        //code...
    } catch (\Exception $th) {
        return $this->response->withJson([
            'flag' => 0,
            'message' => "El token no es válido o ya no está disponible."
        ]);
    }
});

$app->post('/actualizaPassword', function(Request $request, Response $response){
    $password  = password_hash($request->getParam('password_new'),PASSWORD_DEFAULT);
    $token   = $request->getParam('token');
    $settings = $this->get('settings')['jwt'];
    try {
        $decode = JWT::decode(
            $token,
            $settings['secret'],
            array($settings['encrypt'])
        );
        $idusuario = $decode->idusuario;

        $sql = "UPDATE usuario SET password = :password, flag_mail_confirm = 2 WHERE idusuario = $idusuario";
        try {
            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(":password", $password);
            $resultado->execute();

            return $this->response->withJson([
                'flag' => 1,
                'message' => "Tu contraseña se actualizó exitosamente."
            ]);
        } catch (PDOException $e) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Ocurrió un error. Inténtelo nuevamente."
            ]);
        }
        return $this->response->withJson($decode);
        //code...
    } catch (\Exception $th) {
        return $this->response->withJson([
            'flag' => 0,
            'message' => "El enlace no es válido o ya no está disponible."
        ]);
    }

});

$app->group('/api', function(\Slim\App $app) {

    $app->get('/cargar_perfil_general', function(Request $request, Response $response, array $args) {
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;

            $sql = "
                SELECT
                    us.idusuario,
                    us.username,
                    us.password,
                    cl.idcliente,
                    cl.nombres,
                    cl.apellido_paterno,
                    cl.apellido_materno,
                    cl.tipo_documento,
                    cl.numero_documento,
                    cl.correo,
                    cl.sexo,
                    cl.telefono,
                    cl.peso,
                    cl.estatura,
                    cl.tipo_sangre,
                    cl.fecha_nacimiento
                FROM usuario AS us
                JOIN cliente cl ON us.idusuario = cl.idusuario
                WHERE us.idusuario = :idusuario
                LIMIT 1
            ";

            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();
            $cliente = $resultado->fetchObject();

            return $this->response->withJson([
                'datos' => $cliente,
                'flag' => 1,
                'message' => "Se encontró el cliente."
            ]);

        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "El token no es válido o ya no está disponible."
            ]);
        }

    });

    /**
     * Carga los familiares del paciente logueado
     *
     * Creado : 15/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->get('/cargar_familiares', function(Request $request, Response $response, array $args){
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;
            $data = array();
            $sql = "
                SELECT
                    fam.idcliente,
                    fam.nombres,
                    fam.apellido_paterno,
                    fam.apellido_materno,
                    fam.tipo_documento,
                    fam.numero_documento,
                    fam.correo,
                    fam.sexo,
                    fam.telefono,
                    fam.peso,
                    fam.estatura,
                    fam.tipo_sangre,
                    fam.fecha_nacimiento,
                    par.idparentesco,
                    par.descripcion_par
                FROM usuario AS us
                JOIN cliente cl ON us.idusuario = cl.idusuario
                JOIN cliente fam ON cl.idcliente = fam.idtitularcliente
                JOIN parentesco par ON fam.idparentesco = par.idparentesco
                WHERE us.idusuario = :idusuario
                AND fam.estado_pac = 1
            ";

            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();

            if ($data = $resultado->fetchAll()) {
                $message = "Se encontraron familiares";
                $flag = 1;
            }else{
                $message = "No tiene familiares registrados";
                $flag = 0;
            }

            return $this->response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);



        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    });

    /**
     * Servicio para agregar un familiar al cliente logueado
     * Se envia por POST el json con los datos
     *
     * nombres
     * apellido_paterno
     * apellido_materno
     * correo
     * tipo_documento
     * numero_documento
     * fecha_nacimiento
     * sexo
     * idparentesco
     * tipo_sangre
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 15/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->post('/agregar_familiar', function(Request $request, Response $response, array $args){
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idcliente = (int)$user->idcliente;

            $nombres            = $request->getParam('nombres');
            $apellido_paterno   = $request->getParam('apellido_paterno');
            $apellido_materno   = $request->getParam('apellido_materno');
            $correo             = $request->getParam('correo');
            $tipo_documento     = $request->getParam('tipo_documento');
            $numero_documento   = $request->getParam('numero_documento');
            $fecha_nacimiento   = $request->getParam('fecha_nacimiento');
            $sexo               = $request->getParam('sexo');
            $idparentesco       = $request->getParam('idparentesco');
            $tipo_sangre        = $request->getParam('tipo_sangre');

            $createdAt = date('Y-m-d H:i:s');
            $updatedAt = date('Y-m-d H:i:s');

            $sql = "INSERT INTO cliente (
                idparentesco,
                idtitularcliente,
                nombres,
                apellido_paterno,
                apellido_materno,
                tipo_documento,
                numero_documento,
                correo,
                fecha_nacimiento,
                sexo,
                tipo_sangre,
                createdAt,
                updatedAt
            ) VALUES (
                :idparentesco,
                :idtitularcliente,
                :nombres,
                :apellido_paterno,
                :apellido_materno,
                :tipo_documento,
                :numero_documento,
                :correo,
                :fecha_nacimiento,
                :sexo,
                :tipo_sangre,
                :createdAt,
                :updatedAt
            )";

            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(':idparentesco', $idparentesco);
            $resultado->bindParam(':idtitularcliente', $idcliente);
            $resultado->bindParam(':nombres', $nombres);
            $resultado->bindParam(':apellido_paterno', $apellido_paterno);
            $resultado->bindParam(':apellido_materno', $apellido_materno);
            $resultado->bindParam(':tipo_documento', $tipo_documento);
            $resultado->bindParam(':numero_documento', $numero_documento);
            $resultado->bindParam(':correo', $correo);
            $resultado->bindParam(':fecha_nacimiento', $fecha_nacimiento);
            $resultado->bindParam(':sexo', $sexo);
            $resultado->bindParam(':tipo_sangre', $tipo_sangre);
            $resultado->bindParam(':createdAt', $createdAt);
            $resultado->bindParam(':updatedAt', $updatedAt);
            $resultado->execute();

            return $this->response->withJson([
                'flag' => 1,
                'message' => "El registro fue satisfactorio."
            ]);


        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    });

    /**
     * Servicio para editar un familiar de un cliente logueado
     * Se envia por POST el json con los datos
     *
     * idcliente
     * nombres
     * apellido_paterno
     * apellido_materno
     * correo
     * tipo_documento
     * numero_documento
     * fecha_nacimiento
     * sexo
     * idparentesco
     * tipo_sangre
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 16/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->post('/editar_familiar', function(Request $request, Response $response, array $args){
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idtitularcliente   = (int)$user->idcliente;

            $idcliente          = $request->getParam('idcliente');
            $idparentesco       = $request->getParam('idparentesco');
            $nombres            = $request->getParam('nombres');
            $apellido_paterno   = $request->getParam('apellido_paterno');
            $apellido_materno   = $request->getParam('apellido_materno');
            $correo             = $request->getParam('correo');
            $tipo_documento     = $request->getParam('tipo_documento');
            $numero_documento   = $request->getParam('numero_documento');
            $fecha_nacimiento   = $request->getParam('fecha_nacimiento');
            $sexo               = $request->getParam('sexo');
            $tipo_sangre        = $request->getParam('tipo_sangre');


            $updatedAt = date('Y-m-d H:i:s');

            $sql = "UPDATE cliente SET
                idparentesco        = :idparentesco,
                nombres             = :nombres,
                apellido_paterno    = :apellido_paterno,
                apellido_materno    = :apellido_materno,
                tipo_documento      = :tipo_documento,
                numero_documento    = :numero_documento,
                correo              = :correo,
                fecha_nacimiento    = :fecha_nacimiento,
                sexo                = :sexo,
                tipo_sangre         = :tipo_sangre,
                updatedAt           = :updatedAt
                WHERE idcliente = $idcliente
                AND idtitularcliente = $idtitularcliente
            ";

            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(':idparentesco', $idparentesco);
            $resultado->bindParam(':nombres', $nombres);
            $resultado->bindParam(':apellido_paterno', $apellido_paterno);
            $resultado->bindParam(':apellido_materno', $apellido_materno);
            $resultado->bindParam(':tipo_documento', $tipo_documento);
            $resultado->bindParam(':numero_documento', $numero_documento);
            $resultado->bindParam(':correo', $correo);
            $resultado->bindParam(':fecha_nacimiento', $fecha_nacimiento);
            $resultado->bindParam(':sexo', $sexo);
            $resultado->bindParam(':tipo_sangre', $tipo_sangre);
            $resultado->bindParam(':updatedAt', $updatedAt);
            $resultado->execute();

            return $this->response->withJson([
                'flag' => 1,
                'message' => "Se actualizaron los datos correctamente."
            ]);


        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al actualizar los datos.",
                'error' => $th
            ]);
        }
    });

    /**
     * Servicio para anular un familiar de un cliente logueado
     * Se envia por POST el json con los datos
     *
     * idcliente    que se desea anular
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 16/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->post('/anular_familiar', function(Request $request, Response $response, array $args){
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idtitularcliente   = (int)$user->idcliente;

            $idcliente  = $request->getParam('idcliente');
            $updatedAt  = date('Y-m-d H:i:s');

            $sql = "UPDATE cliente SET
                estado_pac = 0,
                updatedAt  = :updatedAt
                WHERE idcliente = $idcliente
                AND idtitularcliente = $idtitularcliente
            ";

            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(':updatedAt', $updatedAt);
            $resultado->execute();

            return $this->response->withJson([
                'flag' => 1,
                'message' => "Se anuló el famiiar correctamente."
            ]);


        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al anular.",
                'error' => $th
            ]);
        }
    });

    /**
     * Maestro de la tabla PARENTESCO
     * se emplea para el combo de parentesco al egregar o editar un familiar
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 15/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->get('/cargar_parentesco', function(Request $request, Response $response, array $args){
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;
            $data = array();
            $sql = "
                SELECT
                    idparentesco,
                    descripcion_par
                FROM parentesco AS par
                WHERE par.estado_par = 1
                ORDER BY descripcion_par ASC
            ";

            $resultado = $this->db->prepare($sql);
            $resultado->execute();

            if ($data = $resultado->fetchAll()) {
                $message = "Se encontraron datos";
                $flag = 1;
            }else{
                $message = "No hay datos registrados";
                $flag = 0;
            }

            return $this->response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);



        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    });

    /**
     * Servicio para editar datos personales y perfil clinico
     * de un cliente logueado
     * Se envia por POST el json con los datos
     *
     * nombres
     * apellido_paterno
     * apellido_materno
     * correo
     * tipo_documento
     * numero_documento
     * fecha_nacimiento
     * telefono
     * sexo
     * peso
     * estatura
     * imc
     * tipo_sangre
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 16/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->post('/editar_perfil', function(Request $request, Response $response, array $args){
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idcliente   = (int)$user->idcliente;

            $nombres            = $request->getParam('nombres');
            $apellido_paterno   = $request->getParam('apellido_paterno');
            $apellido_materno   = $request->getParam('apellido_materno');
            $correo             = $request->getParam('correo');
            $tipo_documento     = $request->getParam('tipo_documento');
            $numero_documento   = $request->getParam('numero_documento');
            $fecha_nacimiento   = $request->getParam('fecha_nacimiento');
            $telefono           = $request->getParam('telefono');
            $sexo               = $request->getParam('sexo');
            $peso               = $request->getParam('peso');
            $estatura           = $request->getParam('estatura');
            $imc                = $request->getParam('imc');
            $tipo_sangre        = $request->getParam('tipo_sangre');
            $updatedAt          = date('Y-m-d H:i:s');

            $sql = "UPDATE cliente SET
                nombres             = :nombres,
                apellido_paterno    = :apellido_paterno,
                apellido_materno    = :apellido_materno,
                tipo_documento      = :tipo_documento,
                numero_documento    = :numero_documento,
                correo              = :correo,
                fecha_nacimiento    = :fecha_nacimiento,
                telefono            = :telefono,
                sexo                = :sexo,
                peso                = :peso,
                estatura            = :estatura,
                imc                 = :imc,
                tipo_sangre         = :tipo_sangre,
                updatedAt           = :updatedAt
                WHERE idcliente = $idcliente
            ";

            $resultado = $this->db->prepare($sql);
            $resultado->bindParam(':nombres', $nombres);
            $resultado->bindParam(':apellido_paterno', $apellido_paterno);
            $resultado->bindParam(':apellido_materno', $apellido_materno);
            $resultado->bindParam(':tipo_documento', $tipo_documento);
            $resultado->bindParam(':numero_documento', $numero_documento);
            $resultado->bindParam(':correo', $correo);
            $resultado->bindParam(':fecha_nacimiento', $fecha_nacimiento);
            $resultado->bindParam(':telefono',$telefono);
            $resultado->bindParam(':sexo', $sexo);
            $resultado->bindParam(':peso', $peso);
            $resultado->bindParam(':estatura', $estatura);
            $resultado->bindParam(':imc', $imc);
            $resultado->bindParam(':tipo_sangre', $tipo_sangre);
            $resultado->bindParam(':updatedAt', $updatedAt);
            $resultado->execute();

            return $this->response->withJson([
                'flag' => 1,
                'message' => "Se actualizaron los datos correctamente."
            ]);


        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al actualizar los datos.",
                'error' => $th
            ]);
        }
    });

    /**
     * Servicio para subir una foto a la carpeta /uploads
     * El nombre del input tipo file debe ser 'foto'
     * La foto subida se guarda con un nombre aleatorio mas su extensión en la tabla cliente
     *
     * En el header se debe enviar el token con los datos del titular logueado
     * Ademas, Content-Type : multipart/form-data
     *
     * Creado : 16/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    $app->post('/subir_foto', function(Request $request, Response $response) {
        // $directory = $this->get('upload_directory');
        $directory = $this->upload_directory;
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idcliente   = (int)$user->idcliente;

            $uploadedFiles = $request->getUploadedFiles();

            $uploadedFile = $uploadedFiles['foto'];

            if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
                $filename = moveUploadedFile($directory, $uploadedFile);

                $sql = "UPDATE cliente SET foto = '$filename' WHERE idcliente = $idcliente";

                $resultado = $this->db->prepare($sql);
                $resultado->execute();

                return $this->response->withJson([
                    'flag' => 1,
                    'message' => "Se subió la foto: " . $filename . " exitosamente."
                ]);
            }
        } catch (\Exception $th) {
            return $this->response->withJson([
                'flag' => 0,
                'message' => "Error al subir la foto.",
                'error' => $th
            ]);
        }
    });
});