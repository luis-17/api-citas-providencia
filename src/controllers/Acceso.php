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
        // VALIDAR TÉRMINOS Y CONDICIONES
        if (
            !isset($input['aceptaTerminos']) ||
            filter_var($input['aceptaTerminos'], FILTER_VALIDATE_BOOLEAN) !== true
        ) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => 'Debe aceptar los términos y condiciones para continuar.'
            ]);
        }
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
            return $response->withStatus(400)->withJson(['flag' => 0, 'message' => $errors]);
        }
        // QUERY
        $sql = "
            SELECT
                us.idusuario,
                us.username,
                us.password,
                cl.idcliente,
                cl.nombres,
                cl.apellido_paterno,
                cl.apellido_materno
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
            return $response->withStatus(400)->withJson(['flag' => 0, 'message' => 'El usuario no existe o aún no está validado.']);
        }

        // verify password.
        if (!password_verify($input['password'],$user->password)) {
            return $response->withStatus(400)->withJson(['flag' => 0, 'message' => 'La contraseña no es válida.']);
        }

        $settings = $this->app->get('settings')['jwt']; // get settings array.
        $time = time();
        $token = JWT::encode(
            [
                'idusuario' => $user->idusuario,
                'username' => $user->username,
                'idcliente' => $user->idcliente,
                'nombres' => $user->nombres,
                'apellido_paterno' => $user->apellido_paterno,
                'apellido_materno' => $user->apellido_materno,
                'ini' => $time,
                'exp' => $time + (24*60*60)
            ],
            $settings['secret'],
            $settings['encrypt']
        );
        //Actualizar el ultimo inicio de sesion y de IP
        $ult_inicio_sesion = date('Y-m-d H:i:s');
        $ult_ip_address = $request->getAttribute('ip_address');

        $sql = "UPDATE usuario SET
                    ult_inicio_sesion   = :ult_inicio_sesion,
                    ult_ip_address      = :ult_ip_address,
                    flag_terms          = 2,
                    fecha_flag_terms    = NOW()
                WHERE idusuario = $user->idusuario
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':ult_inicio_sesion', $ult_inicio_sesion);
            $resultado->bindParam(':ult_ip_address', $ult_ip_address);

            $resultado->execute();

        return $response->withJson(['flag' => 1, 'message' => 'Ok.', 'token' => $token]);

    }

    /**
     * Servicio que realiza el registro de un nuevo usuario
     * Envia un  correo de confirmación
     *
     * Creado: 12/03/2019
     * Modificado: 22/04/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function registro(Request $request, Response $response)
    {
        // $input = $request->getParsedBody();
        $flagTerms          = $request->getParam('flag_terms');
        $username           = $request->getParam('username');
        $nombres            = $request->getParam('nombres');
        $apellido_paterno   = $request->getParam('apellido_paterno');
        $apellido_materno   = $request->getParam('apellido_materno');
        $tipo_documento     = $request->getParam('tipo_documento');
        $numero_documento   = $request->getParam('numero_documento');
        $correo             = $request->getParam('correo');
        $celular            = $request->getParam('celular');
        $fecha_nacimiento   = $request->getParam('fecha_nacimiento');
        $sexo               = $request->getParam('sexo');

        $password  = password_hash($request->getParam('password'),PASSWORD_DEFAULT);
        $pv = $request->getParam('password');
        $ult_ip_address = $request->getAttribute('ip_address');
        $createdAt = date('Y-m-d H:i:s');
        $updatedAt = date('Y-m-d H:i:s');

        if (
            !isset($flagTerms) ||
            filter_var($flagTerms, FILTER_VALIDATE_BOOLEAN) !== true
        ) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => 'Debe aceptar los términos y condiciones para registrarse.'
            ]);
        }

        // VALIDACIONES
        $validator = $this->app->validator->validate($request, [
            'username' => V::length(3)->alnum('_')->noWhitespace(),
            'password' => V::length(6),
            // 'confirm_password' => V::equals($request->getParam('password'))
            'nombres' => V::notBlank()->alnum(),
            'apellido_paterno' => V::notBlank()->alnum(),
            'apellido_materno' => V::notBlank()->alnum(),
            'correo' => V::notBlank()->email(),
            'celular' => V::notBlank()->digit(),
            'tipo_documento' => V::notBlank()->alpha(),
            'numero_documento' => V::notBlank()->alnum(),
            'fecha_nacimiento' => V::date(),
            'sexo' => V::length(null,1)->regex('/[FM]/'),
        ]);

        if ( !$validator->isValid() ) {
            $errors = $validator->getErrors();
            return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
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
            return $response->withStatus(400)->withJson([
                'error' => true,
                'flag' => 0,
                'message' => "Ya tienes una cuenta con nosotros. Inicia sesión con tus credenciales."
            ]);
        }

        $sql = "INSERT INTO usuario (
            username,
            password,
            ult_ip_address,
            pv,
            flag_terms,
            fecha_flag_terms,
            createdAt,
            updatedAt
        ) VALUES (
            :username,
            :password,
            :ult_ip_address,
            :pv,
            :flag_terms,
            :fecha_flag_terms,
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
            telefono,
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
            :telefono,
            :fecha_nacimiento,
            :sexo,
            :createdAt,
            :updatedAt
        )";

        try {
            $error = false;
            // REGISTRO DE USUARIO
            $this->app->db->beginTransaction();
            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':username', $username);
            $resultado->bindParam(':password', $password);
            $resultado->bindParam(':flag_terms', 2);
            $resultado->bindParam(':fecha_flag_terms', $createdAt);
            $resultado->bindParam(':pv', $pv);
            $resultado->bindParam(':ult_ip_address', $ult_ip_address);
            $resultado->bindParam(':createdAt', $createdAt);
            $resultado->bindParam(':updatedAt', $updatedAt);

            $resultado->execute();
            $idusuario =  $this->app->db->lastInsertId();

            if( $idusuario > 0 ){
                // REGISTRO DE CLIENTE
                $resultado = $this->app->db->prepare($sql2);
                $resultado->bindParam(':idusuario', $idusuario);
                $resultado->bindParam(':nombres', $nombres);
                $resultado->bindParam(':apellido_paterno', $apellido_paterno);
                $resultado->bindParam(':apellido_materno', $apellido_materno);
                $resultado->bindParam(':tipo_documento', $tipo_documento);
                $resultado->bindParam(':numero_documento', $numero_documento);
                $resultado->bindParam(':correo', $correo);
                $resultado->bindParam(':telefono', $celular);
                $resultado->bindParam(':fecha_nacimiento', $fecha_nacimiento);
                $resultado->bindParam(':sexo', $sexo);
                $resultado->bindParam(':createdAt', $createdAt);
                $resultado->bindParam(':updatedAt', $updatedAt);

                $resultado->execute();
                $idcliente =  $this->app->db->lastInsertId();

                if( empty($idcliente) ){
                    $error = true;
                }
            }else{
                $error = true;
            }

            if( $error === false){
                $this->app->db->commit();
                // ENVIAR CORREO PARA VERIFICAR
                $settings = $this->app->get('settings'); 
                $time = time();
                $token = JWT::encode(
                    [
                        'idusuario' => $idusuario,
                        'correo'=> $correo,
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
                $mensaje .= '<body style="font-family: sans-serif;" >';
                  $mensaje .= '<div style="align-content: center;">';
                    $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
                      $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
                    $mensaje .= '</div>';
                    $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
                      $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
                        $mensaje .= '<h2 style="margin: 0;color: #00386c;margin-bottom: 1.75rem;">Ya falta poco...</h2>';
                        $mensaje .= '<div style="font-size:16px;">Estimado(a) paciente: '.$paciente .', <br /> <br /> ';
                          $mensaje .= '<a style="color: #056990;display: block;padding-bottom: 0.5rem;" href="' . FRONT_URL . 'validar-cuenta/'. $token .'">Haz clic aquí para continuar con el proceso de registro.</a>';
                          $mensaje .= '<small>Si no has solicitado la suscripción a este correo electrónico, ignóralo y la suscripción no se activará.</small>';
                        $mensaje .= '</div>';
                      $mensaje .= '</div>';
                      $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor.png" /></div>';
                    $mensaje .= '</div>';
                  $mensaje .=  '</div>';
                $mensaje .= '</body>';
                $mensaje .= '</html>';

                $mail = new PHPMailer();
                $mail->IsSMTP(true);
                $mail->SMTPAuth = true;
                $mail->SMTPDebug = false;
                $mail->SMTPSecure = SMTP_SECURE;
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
                $msgCorreo = NULL;
                if($mail->Send()){

                }else{
                    $msgCorreo = 'No se envio correo. ';
                    print_r("No se envio correo");
                }
                return $response->withJson([
                    'flag' => 1,
                    'message' => $msgCorreo."El registro fue satisfactorio. Recibirás un mensaje en el correo para verificar la cuenta. En caso de no verlo en tu bandeja de entrada, no olvides revisar la bandeja de spam."
                ]);
            }else{
               $this->app->db->rollback();
            }
        } catch (PDOException $e) {
            $this->app->db->rollback();
            return $response->withStatus(400)->withJson([
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
            // var_dump($decode); exit();
            $idusuario = $decode->idusuario;
            $correo = $decode->correo;

            $sql = "UPDATE usuario SET flag_mail_confirm = 2 WHERE idusuario = $idusuario";
            try {
                $resultado = $this->app->db->prepare($sql);
                $resultado->execute();
                
                // ENVIO DE CORREO
                $fromAlias = 'Clínica Providencia';

                $asunto = 'Bienvenido a la Clínica Providencia';

                $mensaje = '<html lang="es">';
                $mensaje .= '<body style="font-family: sans-serif;" >';
                  $mensaje .= '<div style="align-content: center;">';
                    $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
                      $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
                    $mensaje .= '</div>';
                    $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
                      $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
                        $mensaje .= '<h2 style="margin: 0;color: #00386c;margin-bottom: 1.75rem;">Bienvenido, estamos muy felices de que se haya unido a nosotros.</h2>';
                        $mensaje .= '<div style="font-size:16px;"> Gracias por su confianza y preferencia. <b>Clínica Providencia</b> es una empresa peruana vinculada al rubro de salud con mas de 7 años de experiencia. Contamos con mas de 40 especialidades médicas, la mas moderna tecnología y profesionales altamente calificados. <br /> <br /> ';
                          // $mensaje .= '<a style="color: #056990;display: block;padding-bottom: 0.5rem;" href="' . FRONT_URL . 'validar-cuenta/'. $token .'">Haz clic aquí para continuar con el proceso de registro.</a>';
                          $mensaje .= '<p>Inicie sesión y realice su primera cita:</p>';
                          $mensaje .= '<a style="padding: 0.5rem;display: inline-block;background-color: #00386c;color: white;text-decoration: none;border-radius: 5px;" href="'.FRONT_URL.'">Iniciar sesión</a>';
                        $mensaje .= '</div>';
                      $mensaje .= '</div>';
                      $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor_edificio.png" /></div>';
                    $mensaje .= '</div>';
                  $mensaje .=  '</div>';
                $mensaje .= '</body>';
                $mensaje .= '</html>';

                $mail = new PHPMailer();
                $mail->IsSMTP(true);
                $mail->SMTPAuth = true;
                $mail->SMTPDebug = false;
                $mail->SMTPSecure = SMTP_SECURE;
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
                $msgCorreo = NULL;
                if($mail->Send()){

                }else{
                    $msgCorreo = 'No se envio correo. ';
                    print_r("No se envio correo");
                }
                return $response->withJson([
                    'flag' => 1,
                    'message' => "Tu cuenta ha sido verificada exitosamente... Ya puedes iniciar sesión para comenzar a disfrutar los beneficios de ser un paciente de Clínica Providencia!."
                ]);
            } catch (PDOException $e) {
                return $response->withStatus(400)->withJson([
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

    /**
     * Servicio para recuperar una contraseña atraves del numero de DNI
     *
     * Creado: 14/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function recuperaPassword(Request $request, Response $response)
    {
        $validator = $this->app->validator->validate($request, [
            'numeroDocumento' => V::notBlank()->digit(),
        ]);

        if ( !$validator->isValid() ) {
            $errors = $validator->getErrors();
            return $response->withStatus(400)->withJson(['error' => true, 'message' => $this->app->get('settings')['message']['400'], 'messageTec' => $errors]);
        }

        $numero_documento = $request->getParam('numeroDocumento');

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
            return $response->withStatus(400)->withJson([
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
              $mensaje .= '<body style="font-family: sans-serif;" >';
                $mensaje .= '<div style="align-content: center;">';
                  $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
                    $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
                  $mensaje .= '</div>';
                  $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
                    $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
                      $mensaje .= '<h2 style="margin: 0;color: #00386c;margin-bottom: 1.75rem;">Tranquilo, nosotros te ayudamos.</h2>';
                      $mensaje .= '<div style="font-size:16px;">Estimado(a) paciente: '.$paciente .', <br /> <br /> ';
                        $mensaje .= '<a style="color: #056990;display: block;padding-bottom: 0.5rem;" href="' . FRONT_URL . 'recuperar-cuenta/'. $token .'">Haz clic aquí para continuar con el proceso de recuperación de contraseña.</a>';
                        $mensaje .= '<small>Si no has solicitado la suscripción a este correo electrónico, ignóralo y la suscripción no se activará.</small>';
                      $mensaje .= '</div>';
                    $mensaje .= '</div>';
                    $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor.png" /></div>';
                  $mensaje .= '</div>';
                $mensaje .=  '</div>';
              $mensaje .= '</body>';
              $mensaje .= '</html>';
            // $mensaje = '<html lang="es">';
            // $mensaje .= '<body style="font-family: sans-serif;padding: 10px 40px;" >';
            // $mensaje .= '<div style="max-width: 700px;align-content: center;margin-left: auto; margin-right: auto;padding-left: 5%; padding-right: 5%;">';
            // $mensaje .= '	<div style="font-size:16px;">
            //                     Estimado(a) paciente: '.$paciente .', <br /> <br /> ';

            // $mensaje .= '     <a href="' . FRONT_URL . 'recuperar-cuenta/'. $token .'">Haz clic aquí para continuar con el proceso de recuperación de contraseña.</a>';
            // $mensaje .= '    </div>';
            // $mensaje .= '    <div>
            //                     <p>Si no has solicitado el cambio de contraseña a este correo electrónico, ignóralo y el cambio no se realizará..</p>
            //                 </div>';
            // $mensaje .=  '</div>';
            // $mensaje .= '</body>';
            // $mensaje .= '</html>';

            $mail = new PHPMailer();
            $mail->IsSMTP(true);
            $mail->SMTPAuth = true;
            // $mail->SMTPDebug = true;
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
                return $response->withStatus(400)->withJson([
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
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "El token no es válido o ya no está disponible."
            ]);
        }
    }

    public function actualizaPassword(Request $request, Response $response)
    {
        $password  = password_hash($request->getParam('password'),PASSWORD_DEFAULT);
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
                return $response->withStatus(400)->withJson([
                    'flag' => 0,
                    'message' => "Ocurrió un error. Inténtelo nuevamente."
                ]);
            }
        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "El enlace no es válido o ya no está disponible."
            ]);
        }

    }
    public function verPlantillaHTML()
    {
      // $mensaje = '<html lang="es">';
      // $mensaje .= '<body style="font-family: sans-serif;" >';
      //   $mensaje .= '<div style="align-content: center;">';
      //     $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
      //       $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
      //     $mensaje .= '</div>';
      //     $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
      //       $mensaje .= '<div style="font-size:16px;">';
      //         $mensaje .= '<h2 style="margin: 0;color: #00386c;margin-bottom: 1.75rem;">Ya falta poco...</h2>';
      //         $mensaje .= '<div style="font-size:16px;">Estimado(a) paciente: LUIS RICARDO LUNA SOTO, <br /> <br /> ';
      //           $mensaje .= '<a style="color: #056990;display: block;padding-bottom: 0.5rem;" href="' . FRONT_URL . 'validar-cuenta/askjnfskfjksgjknsjdkgnjksdk">Haz clic aquí para continuar con el proceso de registro.</a>';
      //           $mensaje .= '<small>Si no has solicitado la suscripción a este correo electrónico, ignóralo y la suscripción no se activará.</small>';
      //         $mensaje .= '</div>';
      //       $mensaje .= '</div>';
      //       $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor.png" /></div>';
      //     $mensaje .= '</div>';
      //   $mensaje .=  '</div>';
      // $mensaje .= '</body>';
      // $mensaje .= '</html>';

        // $mensaje = '<html lang="es">';
        // $mensaje .= '<body style="font-family: sans-serif;" >';
        //   $mensaje .= '<div style="align-content: center;">';
        //     $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
        //       $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
        //     $mensaje .= '</div>';
        //     $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
        //       $mensaje .= '<div style="font-size:16px;max-width: 600px;">';
        //         $mensaje .= '<h2 style="margin: 0;color: #00386c;margin-bottom: 1.75rem;">Bienvenido, estamos muy felices de que se haya unido a nosotros.</h2>';
        //         $mensaje .= '<div style="font-size:16px;"> Gracias por su confianza y preferencia. <b>Clínica Providencia</b> es una empresa peruana vinculada al rubro de salud con mas de 7 años de experiencia. Contamos con mas de 40 especialidades médicas, la mas moderna tecnología y profesionales altamente calificados. <br /> <br /> ';
        //           // $mensaje .= '<a style="color: #056990;display: block;padding-bottom: 0.5rem;" href="' . FRONT_URL . 'validar-cuenta/'. $token .'">Haz clic aquí para continuar con el proceso de registro.</a>';
        //           $mensaje .= '<p>Inicie sesión y realice su primera cita:</p>';
        //           $mensaje .= '<a style="padding: 0.5rem;display: inline-block;background-color: #00386c;color: white;text-decoration: none;border-radius: 5px;" href="'.FRONT_URL.'">Iniciar sesión</a>';
        //         $mensaje .= '</div>';
        //       $mensaje .= '</div>';
        //       $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor_edificio.png" /></div>';
        //     $mensaje .= '</div>';
        //   $mensaje .=  '</div>';
        // $mensaje .= '</body>';
        // $mensaje .= '</html>';

        // $mensaje = '<html lang="es">';
        //   $mensaje .= '<body style="font-family: sans-serif;" >';
        //     $mensaje .= '<div style="align-content: center;">';
        //       $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
        //         $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
        //       $mensaje .= '</div>';
        //       $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
        //         $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
        //           $mensaje .= '<h2 style="margin: 0;color: #00386c;margin-bottom: 1.75rem;">Tranquilo, nosotros te ayudamos.</h2>';
        //           $mensaje .= '<div style="font-size:16px;">Estimado(a) paciente: JUAN PAREDES CASTRO, <br /> <br /> ';
        //             $mensaje .= '<a style="color: #056990;display: block;padding-bottom: 0.5rem;" href="' . FRONT_URL . 'recuperar-cuenta/ASDFASDFFDS">Haz clic aquí para continuar con el proceso de recuperación de contraseña.</a>';
        //             $mensaje .= '<small>Si no has solicitado la suscripción a este correo electrónico, ignóralo y la suscripción no se activará.</small>';
        //           $mensaje .= '</div>';
        //         $mensaje .= '</div>';
        //         $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor.png" /></div>';
        //       $mensaje .= '</div>';
        //     $mensaje .=  '</div>';
        //   $mensaje .= '</body>';
        //   $mensaje .= '</html>';

        $mensaje = '<html lang="es">';
        $mensaje .= '<body style="font-family: sans-serif;" >';
          $mensaje .= '<div style="align-content: center;">';
            $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
              $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
            $mensaje .= '</div>';
            $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
              $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
                $mensaje .= '<h2 style="margin: 0;color: #739525;margin-bottom: 1.75rem;"> <strong style="color:#00386c;">LUIS RICARDO,</strong> <br> ha reservado su cita en línea de manera correcta.</h2>';
                $mensaje .= '<div style="font-size:16px;color: #777777;"> Gracias por su confianza y preferencia. A continuación le brindamos los datos de su cita médica: <br /> <br /> ';
                  $mensaje .= '<table style="width:100%;color: #777777;">';
                    $mensaje .= '<tr><td><b>FECHA DE CITA:</b></td><td>20/12/2019</td></tr>';
                    $mensaje .= '<tr><td><b>HORA:</b></td><td>17:30</td></tr>';
                    $mensaje .= '<tr><td><b>ESPECIALIDAD:</b></td><td>UROLOGÍA</td></tr>';
                    $mensaje .= '<tr><td><b>MÉDICO:</b></td><td>JUAN PAREDES CASTRO</td></tr>';
                    // $mensaje .= '<tr><td>CMP</td><td>17:30</td></tr>';
                  $mensaje .= '</table>';
                  $mensaje .= '<p>No olvides seguir usando nuestro canal online desde el siguiente link:</p>';
                  $mensaje .= '<a style="padding: 0.5rem;display:inline-block;background-color:#00386c;color:white;text-decoration: none;border-radius: 5px;" href="'.FRONT_URL.'">ACCEDER</a>';
                  $mensaje .= '<p style="font-style: italic;font-size: 13px;"><strong>IMPORTANTE:</strong>Si deseas cancelar la cita, puedes hacerlo desde nuestro canal online accediendo a: <a target="_blank" href="http://citasenlinea.clinicaprovidencia.pe/#/">http://citasenlinea.clinicaprovidencia.pe/#/</a></p>';
                $mensaje .= '</div>';
              $mensaje .= '</div>';
              $mensaje .= '<div style="display: inline-block;"><img style="width:260px;" src="http://104.131.176.122/mailing-providencia/doctor_edificio.png" /></div>';
            $mensaje .= '</div>';
          $mensaje .=  '</div>';
        $mensaje .= '</body>';
        $mensaje .= '</html>';

      return $mensaje;
    }
}
