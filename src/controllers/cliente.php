<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Respect\Validation\Validator as V;
class Cliente
{
    public function __construct($app)
    {
        $this->app = $app;

    }

    public function cargar_perfil_general(Request $request, Response $response, array $args)
    {
        try {
            // var_dump( baseFile(), 'baseFile' );
            // var_dump($request->getUri()->getBasePath()); exit();
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
                    cl.imc,
                    cl.tipo_sangre,
                    cl.foto,
                    DATE_FORMAT(cl.fecha_nacimiento, '%d-%m-%Y') as fecha_nacimiento,
                    YEAR(CURDATE()) - YEAR(cl.fecha_nacimiento) AS edad 
                FROM usuario AS us
                JOIN cliente cl ON us.idusuario = cl.idusuario
                WHERE us.idusuario = :idusuario
                LIMIT 1
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();
            $cliente = $resultado->fetchObject();
            // var_dump($cliente->imc, 'fd'); exit();
            $cliente->imc = $cliente->imc ?? '[ - ]';
            $cliente->peso = $cliente->peso ?? '[ - ]';
            $cliente->estatura = $cliente->estatura ?? '[ - ]';
            $cliente->tipo_sangre = $cliente->tipo_sangre ?? '[ - ]';
            return $response->withJson([
                'datos' => $cliente,
                'flag' => 1,
                'message' => "Se encontró el cliente."
            ]);

        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "El token no es válido o ya no está disponible."
            ]);
        }

    }
    /**
     * Carga los familiares del paciente logueado
     *
     * Creado : 15/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function cargar_familiares(Request $request, Response $response, array $args)
    {
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
                    CONCAT_WS(' ',fam.nombres,fam.apellido_paterno,fam.apellido_materno) AS nombre_completo,
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

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();

            if ($data = $resultado->fetchAll()) {
                $message = "Se encontraron familiares";
                $flag = 1;
            }else{
                $message = "No tiene familiares registrados";
                $flag = 0;
            }

            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);



        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    }

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
    public function agregar_familiar(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idcliente = (int)$user->idcliente;

            // VALIDACIONES
                $validator = $this->app->validator->validate($request, [
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
                    return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
                }

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

            $resultado = $this->app->db->prepare($sql);
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

            return $response->withJson([
                'flag' => 1,
                'message' => "El registro fue satisfactorio."
            ]);


        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    }

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
    public function editar_familiar(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idtitularcliente   = (int)$user->idcliente;

            // VALIDACIONES
            $validator = $this->app->validator->validate($request, [
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
                return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
            }

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
            $updatedAt          = date('Y-m-d H:i:s');


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

            $resultado = $this->app->db->prepare($sql);
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

            return $response->withJson([
                'flag' => 1,
                'message' => "Se actualizaron los datos correctamente."
            ]);


        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al actualizar los datos.",
                'error' => $th
            ]);
        }
    }

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
    public function anular_familiar(Request $request, Response $response, array $args)
    {
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

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':updatedAt', $updatedAt);
            $resultado->execute();

            return $response->withJson([
                'flag' => 1,
                'message' => "Se anuló el famiiar correctamente."
            ]);


        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al anular.",
                'error' => $th
            ]);
        }
    }

     /**
     * Maestro de la tabla PARENTESCO
     * se emplea para el combo de parentesco al egregar o editar un familiar
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 15/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function cargar_parentesco(Request $request, Response $response, array $args)
    {
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

            $resultado = $this->app->db->prepare($sql);
            $resultado->execute();

            if ($data = $resultado->fetchAll()) {
                $message = "Se encontraron datos";
                $flag = 1;
            }else{
                $message = "No hay datos registrados";
                $flag = 0;
            }

            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);



        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    }

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
    public function editar_perfil(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idcliente   = (int)$user->idcliente;

            // VALIDACIONES
            $validator = $this->app->validator->validate($request, [
                // 'nombres' => V::notBlank()->alnum(),
                // 'apellido_paterno' => V::notBlank()->alnum(),
                // 'apellido_materno' => V::notBlank()->alnum(),
                'correo' => V::notBlank()->email(),
                // 'tipo_documento' => V::notBlank()->alpha(),
                // 'numero_documento' => V::notBlank()->digit(),
                // 'fecha_nacimiento' => V::date(),
                'telefono' => V::digit(),
                'tipo_sangre' => V::length(null,3),
                'peso' => V::length(null,3),
                'estatura' => V::length(null,3),
            ]);

            if ( !$validator->isValid() ) {
                $errors = $validator->getErrors();
                return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
            }

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
            $imc                = null;
            if( !empty($request->getParam('peso')) && !empty($request->getParam('estatura')) ){
                $imc = $request->getParam('peso') / (($request->getParam('estatura')/100) * ($request->getParam('estatura')/100));
            }
            
            $tipo_sangre        = $request->getParam('tipo_sangre');
            $updatedAt          = date('Y-m-d H:i:s');

            // $sql = "UPDATE cliente SET
            //     nombres             = :nombres,
            //     apellido_paterno    = :apellido_paterno,
            //     apellido_materno    = :apellido_materno,
            //     tipo_documento      = :tipo_documento,
            //     numero_documento    = :numero_documento,
            //     correo              = :correo,
            //     fecha_nacimiento    = :fecha_nacimiento,
            //     telefono            = :telefono,
            //     sexo                = :sexo,
            //     peso                = :peso,
            //     estatura            = :estatura,
            //     imc                 = :imc,
            //     tipo_sangre         = :tipo_sangre,
            //     updatedAt           = :updatedAt
            //     WHERE idcliente = $idcliente
            // ";
            $sql = "UPDATE cliente SET 
                correo              = :correo,
                telefono            = :telefono,
                peso                = :peso,
                estatura            = :estatura,
                imc                 = :imc,
                tipo_sangre         = :tipo_sangre,
                updatedAt           = :updatedAt
                WHERE idcliente = $idcliente
            ";

            $resultado = $this->app->db->prepare($sql);
            // $resultado->bindParam(':nombres', $nombres);
            // $resultado->bindParam(':apellido_paterno', $apellido_paterno);
            // $resultado->bindParam(':apellido_materno', $apellido_materno);
            // $resultado->bindParam(':tipo_documento', $tipo_documento);
            // $resultado->bindParam(':numero_documento', $numero_documento);
            $resultado->bindParam(':correo', $correo);
            // $resultado->bindParam(':fecha_nacimiento', $fecha_nacimiento);
            $resultado->bindParam(':telefono',$telefono);
            // $resultado->bindParam(':sexo', $sexo);
            $resultado->bindParam(':peso', $peso);
            $resultado->bindParam(':estatura', $estatura);
            $resultado->bindParam(':imc', $imc);
            $resultado->bindParam(':tipo_sangre', $tipo_sangre);
            $resultado->bindParam(':updatedAt', $updatedAt);
            $resultado->execute();

            return $response->withJson([
                'flag' => 1,
                'message' => "Se actualizaron los datos correctamente."
            ]);


        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al actualizar los datos.",
                'error' => $th
            ]);
        }
    }

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
    public function subir_foto(Request $request, Response $response)
    {
        // $directory = $this->get('upload_directory');
        $directory = $this->app->upload_directory;
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idcliente   = (int)$user->idcliente;

            $uploadedFiles = $request->getUploadedFiles();

            $uploadedFile = $uploadedFiles['foto'];

            if ($uploadedFile->getError() === UPLOAD_ERR_OK) {
                $filename = moveUploadedFile($directory, $uploadedFile);

                $sql = "UPDATE cliente SET foto = '$filename' WHERE idcliente = $idcliente";

                $resultado = $this->app->db->prepare($sql);
                $resultado->execute();

                return $response->withJson([
                    'flag' => 1,
                    'message' => "Se subió la foto: " . $filename . " exitosamente."
                ]);
            }
        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al subir la foto.",
                'error' => $th
            ]);
        }
    }

    /**
     * Servicio para editar datos personales y perfil clinico
     * de un cliente logueado
     * Se envia por POST el json con los datos
     *
     * password_new
     *
     * En el header se debe enviar el token con los datos del titular logueado
     *
     * Creado : 16/03/2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     */
    public function cambiar_password(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario   = (int)$user->idusuario;

            // VALIDACIONES
                $validator = $this->app->validator->validate($request, [
                    'password' => V::notBlank()->length(6),

                ]);

                if ( !$validator->isValid() ) {
                    $errors = $validator->getErrors();
                    return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
                }

            $password  = password_hash($request->getParam('password_new'),PASSWORD_DEFAULT);

            $updatedAt          = date('Y-m-d H:i:s');

            $sql = "UPDATE usuario SET
                password   = :password,
                updatedAt  = :updatedAt
                WHERE idusuario = $idusuario
            ";
            try {
                $resultado = $this->app->db->prepare($sql);
                $resultado->bindParam(":password", $password);
                $resultado->bindParam(':updatedAt', $updatedAt);
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
                'message' => "Error al actualizar los datos.",
                'error' => $th
            ]);
        }
    }

    public function cargar_paciente(Request $request, Response $response, array $args)
    {
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
                    par.descripcion_par AS parentesco
                FROM usuario AS us
                JOIN cliente cl ON us.idusuario = cl.idusuario
                JOIN cliente fam ON cl.idcliente = fam.idtitularcliente
                JOIN parentesco par ON fam.idparentesco = par.idparentesco
                WHERE us.idusuario = :idusuario
                AND fam.estado_pac = 1
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();

            $arrFamiliares = $resultado->fetchAll();

            // DATOS DEL TITULAR
            $sql = "
                SELECT
                    cl.idcliente,
                    cl.nombres,
                    cl.apellido_paterno,
                    cl.apellido_materno,
                    'TITULAR' AS parentesco
                FROM usuario AS us
                JOIN cliente cl ON us.idusuario = cl.idusuario
                WHERE us.idusuario = :idusuario
                LIMIT 1
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();
            $titular = $resultado->fetchObject();

            $arrListado = array();
            array_push($arrListado,
                array(
                    'id' => $titular->idcliente,
                    'paciente' => $titular->nombres . ' [' . $titular->parentesco . ']',
                    'habilitado' => true
                ),
                array(
                    'id' => '0',
                    'paciente' => '----',
                    'habilitado' => false
                )
            );
            foreach ($arrFamiliares as $row) {
                array_push($arrListado,
                    array(
                        'id' => $row['idcliente'],
                        'paciente' => $row['nombres'] . ' [' . $row['parentesco'] . ']',
                        'habilitado' => true
                    )
                );
            }
            $flag = 1;
            $message = "";

            return $response->withJson([
                'datos' => $arrListado,
                'flag' => $flag,
                'message' => $message
            ]);



        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    }
}