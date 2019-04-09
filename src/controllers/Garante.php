<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use Respect\Validation\Validator as V;

class Garante
{
    public function __construct($app)
    {
        $this->app = $app;

    }

    public function cargar_garante(Request $request, Response $response, array $args)
    {
        try {

            $arrListado = array();
            $sql = "
                SELECT
                    gar.idgarante,
                    gar.descripcion_gar
                FROM garante AS gar
                WHERE gar.estado_gar = 1
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->execute();

            $arrListado = $resultado->fetchAll();



            return $response->withJson([
                'datos' => $arrListado,
                'flag' => 1,
                'message' => ""
            ]);



        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "Error al cargar los datos.",
                'error' => $th
            ]);
        }
    }
}
