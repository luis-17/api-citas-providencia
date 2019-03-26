<?php
use Slim\Http\Request;
use Slim\Http\Response;
// use Slim\Http\UploadedFile;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;

$app->group('/api', function(\Slim\App $app) {
    $app->get('/cargar_paciente', function(Request $request, Response $response, array $args){
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

            $resultado = $this->db->prepare($sql);
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

            $resultado = $this->db->prepare($sql);
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

            return $this->response->withJson([
                'datos' => $arrListado,
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
});