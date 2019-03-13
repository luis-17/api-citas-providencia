<?php

use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;

// Routes

$app->get('/[{name}]', function (Request $request, Response $response, array $args) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/' route");

    // Render index view
    return $this->renderer->render($response, 'index.phtml', $args);
});

$app->post('/login', function (Request $request, Response $response, array $args) {

    $input = $request->getParsedBody();
    $sql = "SELECT * FROM usuario WHERE username= :username";
    $resultado = $this->db->prepare($sql);
    $resultado->bindParam("username", $input['username']);
    $resultado->execute();
    $user = $resultado->fetchObject();

    // verify email address.
    if(!$user) {
        return $this->response->withJson(['error' => true, 'message' => 'El usuario no existe.']);
    }

    // verify password.
    if (!password_verify($input['password'],$user->password)) {
        return $this->response->withJson(['error' => true, 'message' => 'La contraseña no es válida.']);
    }

    $settings = $this->get('settings'); // get settings array.
    $time = time();
    $token = JWT::encode(
        [
            'idusuario' => $user->idusuario,
            'username' => $user->username,
            'ini' => $time,
            'exp' => $time + (60*60)
        ],
        $settings['jwt']['secret'],
        "HS256"
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

    // echo "password " . $password;

    $sql = "INSERT INTO usuario (username, password, ult_ip_address, createdAt, updatedAt) VALUES (:username, :password, :ult_ip_address, :createdAt, :updatedAt)";

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
        return $this->response->withJson([
            'flag' => 1,
            'message' => "Usuario " . $idusuario . " registrado exitosamente.<br>
                          Cliente " . $idcliente . " registrado correctamente."
        ]);

    } catch (PDOException $e) {
        // echo '{ "error" : { "text" : ' . $e->getMessage() . ' } }';
        return $this->response->withJson([
            'flag' => 0,
            'message' => "Ocurrió un error. " . $e->getMessage()
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