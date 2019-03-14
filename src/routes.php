<?php

use Slim\Http\Request;
use Slim\Http\Response;
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
    $sql = "SELECT * FROM usuario WHERE username = :username AND flag_mail_confirm = 2";
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
            'ini' => $time,
            'exp' => $time + (60*60)
        ],
        $settings['secret'],
        $settings['encrypt']
    );

    return $this->response->withJson(['token' => $token]);

});
/**
 * Servicio que realiza el registro de un nuevo usuario
 * Envia un  correo de confirmación
 *
 * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
 * Creado: 12/03/2019
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
            // $cabeceras = 'From: Providencia' . "\r\n" . 'X-Mailer: PHP/' . phpversion();
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

$app->group('/api', function(\Slim\App $app) {

    $app->get('/user',function(Request $request, Response $response, array $args) {
        // print_r($request->getAttribute('decoded_token_data'));
        $user = $request->getAttribute('decoded_token_data');
        return $this->response->withJson($user);
    });

});