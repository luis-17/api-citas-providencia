<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Respect\Validation\Validator as V;
class Acceso
{
    public function __construct($app)
    {
        $this->app = $app;

    }
    public function login(Request $request, Response $response, array $args)
    {
        $input = $request->getParsedBody();
        // VALIDACIONES
        $validator = $this->app->validator->validate($request,
            [
                'username' =>[
                    'rules' => V::notBlank(),
                    'message' => 'El campo usuario es requerido',
                ],
                'password' => [
                    'rules' => V::notBlank(),
                    'message' => 'El campo password es requerido'
                ]
            ]
        );

        if ( !$validator->isValid() ) {
            $errors = $validator->getErrors();
            return $response->withJson(['error' => true, 'message' => $errors]);
        }
        // QUERY
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
        $resultado = $this->app->db->prepare($sql);
        $resultado->bindParam("username", $input['username']);
        $resultado->execute();
        $user = $resultado->fetchObject();

        // verify email address.
        if(!$user) {
            return $response->withJson(['error' => true, 'message' => 'El usuario no existe o aún no está validado.']);
        }

        // verify password.
        if (!password_verify($input['password'],$user->password)) {
            return $response->withJson(['error' => true, 'message' => 'La contraseña no es válida.']);
        }

        $settings = $this->app->get('settings')['jwt']; // get settings array.
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

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':ult_inicio_sesion', $ult_inicio_sesion);
            $resultado->bindParam(':ult_ip_address', $ult_ip_address);

            $resultado->execute();

        return $response->withJson(['token' => $token]);

    }

    /**
     * Servicio que realiza el registro de un nuevo usuario
     * Envia un  correo de confirmación
     *
     * Creado: 12/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function registro(Request $request, Response $response)
    {
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
        $validator = $this->app->validator->validate($request, [
            'username' => V::length(3)->alnum('_')->noWhitespace(),
            'password' => V::length(6),
            // 'confirm_password' => V::equals($request->getParam('password'))
            'nombres' => V::notBlank()->alnum(),
            'apellido_paterno' => V::notBlank()->alnum(),
            'apellido_materno' => V::notBlank()->alnum(),
            'correo' => V::notBlank()->email(),
            'tipo_documento' => V::notBlank()->alpha(),
            'numero_documento' => V::notBlank()->digit(),
            'fecha_nacimiento' => V::date(),
            'sexo' => V::length(null,1)->regex('/[FM]/'),
        ]);

        if ( !$validator->isValid() ) {
            $errors = $validator->getErrors();
            return $response->withJson(['error' => true, 'message' => $errors]);
        }

        // QUERY
        $sql = "
            SELECT
                us.idusuario,
                us.username,
                us.password
            FROM usuario AS us
            WHERE us.username = :username
            LIMIT 1
        ";

        $resultado = $this->app->db->prepare($sql);
        $resultado->bindParam(":username", $username);
        $resultado->execute();
        $usuario = $resultado->fetchObject();

        if($usuario){
            return $response->withJson([
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
            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':username', $username);
            $resultado->bindParam(':password', $password);
            $resultado->bindParam(':ult_ip_address', $ult_ip_address);
            $resultado->bindParam(':createdAt', $createdAt);
            $resultado->bindParam(':updatedAt', $updatedAt);

            $resultado->execute();
            $idusuario =  $this->app->db->lastInsertId();

            // REGISTRO DE CLIENTE
            $resultado = $this->app->db->prepare($sql2);
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
            $idcliente =  $this->app->db->lastInsertId();


            // ENVIAR CORREO PARA VERIFICAR
                $settings = $this->app->get('settings'); // get settings array.
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



            return $response->withJson([
                'flag' => 1,
                'message' => "El registro fue satisfactorio. Recibirás un mensaje en el correo para verificar la cuenta. En caso de no verlo en tu bandeja de entrada, no olvides revisar la bandeja de spam."
            ]);

        } catch (PDOException $e) {
            // echo '{ "error" : { "text" : ' . $e->getMessage() . ' } }';
            return $response->withJson([
                'flag' => 0,
                'message' => "Ocurrió un error. " . $e->getMessage()
            ]);
        }
    }
    /**
     * Servicio para validar el registro de un usuario
     * Actualiza la tabla usuario para habilitarlo
     *
     * Creado: 13/03/19
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function validaRegistro(Request $request, Response $response)
    {
        $token = $request->getParam('token');
        $settings = $this->app->get('settings')['jwt'];
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
                $resultado = $this->app->db->prepare($sql);
                $resultado->execute();

                return $response->withJson([
                    'flag' => 1,
                    'message' => "Tu cuenta ha sido verificada exitosamente... Ya puedes iniciar sesión para comenzar a disfrutar los beneficios de ser un paciente de Clínica Providencia!."
                ]);
            } catch (PDOException $e) {
                return $response->withJson([
                    'flag' => 0,
                    'message' => "Ocurrió un error. Inténtelo nuevamente."
                ]);
            }
            return $response->withJson($decode);
            //code...
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "El enlace no es válido o ya no está disponible."
            ]);
        }
    }

    /**
     * Servicio para recuperar una contraseña atraves del numero de DNI
     *
     * Creado: 14/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function recuperaPassword(Request $request, Response $response)
    {
        $validator = $this->app->validator->validate($request, [
            'numero_documento' => V::notBlank()->digit(),
        ]);

        if ( !$validator->isValid() ) {
            $errors = $validator->getErrors();
            return $response->withJson(['error' => true, 'message' => $errors]);
        }

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

        $resultado = $this->app->db->prepare($sql);
        $resultado->bindParam(":numero_documento", $numero_documento);
        $resultado->execute();
        $user = $resultado->fetchObject();

        if( empty($user) ){
            return $response->withJson([
                'flag' => 0,
                'message' => "El número de documento no se encuentra registrado en el sistema."
            ]);
        }

        // ENVIAR CORREO PARA VERIFICAR
            $settings = $this->app->get('settings'); // get settings array.
            $time = time();
            $token = JWT::encode(
                [
                    'idusuario' => $user->idusuario,
                    'numero_documento' => $user->numero_documento,
                    'iat' => $time,
                    'exp' => $time + (24*60*60)
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
                return $response->withJson([
                    'flag' => 1,
                    'message' => "Recibirás un mensaje en el correo para cambiar su contraseña. En caso de no verlo en tu bandeja de entrada, no olvides revisar la bandeja de spam."
                ]);

            }else{
                return $response->withJson([
                    'flag' => 0,
                    'message' => "Ocurrió un error en el envio de correo. Inténtelo nuevamente"
                ]);
            }

    }

    /**
     * Servicio para validar el correo de cambio de contraseña
     * Retorna un OK si el token es válido
     *
     * Creado: 14/03/19
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function validaPassword(Request $request, Response $response)
    {
        $token = $request->getParam('token');

        $settings = $this->app->get('settings')['jwt'];
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


            return $response->withJson([
                'flag' => 1,
                'message' => "El token es válido."
            ]);
            //code...
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "El token no es válido o ya no está disponible."
            ]);
        }
    }

    public function actualizaPassword(Request $request, Response $response)
    {
        $password  = password_hash($request->getParam('password_new'),PASSWORD_DEFAULT);
        $token   = $request->getParam('token');
        $settings = $this->app->get('settings')['jwt'];
        try {
            $decode = JWT::decode(
                $token,
                $settings['secret'],
                array($settings['encrypt'])
            );
            $idusuario = $decode->idusuario;

            $sql = "UPDATE usuario SET password = :password, flag_mail_confirm = 2 WHERE idusuario = $idusuario";
            try {
                $resultado = $this->app->db->prepare($sql);
                $resultado->bindParam(":password", $password);
                $resultado->execute();

                return $response->withJson([
                    'flag' => 1,
                    'message' => "Tu contraseña se actualizó exitosamente."
                ]);
            } catch (PDOException $e) {
                return $response->withJson([
                    'flag' => 0,
                    'message' => "Ocurrió un error. Inténtelo nuevamente."
                ]);
            }
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "El enlace no es válido o ya no está disponible."
            ]);
        }

    }
}
