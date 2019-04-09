<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use Respect\Validation\Validator as V;

class Cita
{
    public function __construct($app)
    {
        $this->app = $app;

    }

    public function registrar_cita(Request $request, Response $response)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idcliente = (int)$user->idcliente;

            // VALIDACIONES
                $validator = $this->app->validator->validate($request, [
                    'idgarante' => V::notBlank()->digit(),
                    'especialidad' => V::notBlank()->alnum(),
                    'medico' => V::notBlank()->alpha(),
                    'fecha_cita' => V::notBlank()->date(),
                ]);

                if ( !$validator->isValid() ) {
                    $errors = $validator->getErrors();
                    return $response->withJson(['error' => true, 'message' => $errors]);
                }

            $idgarante      = $request->getParam('idgarante');
            $fecha_cita     = $request->getParam('fecha_cita');
            $hora_inicio    = $request->getParam('hora_inicio');
            $hora_fin       = $request->getParam('hora_fin');
            $medico         = $request->getParam('medico');
            $especialidad   = $request->getParam('especialidad');
            $monto_cita     = $request->getParam('monto_cita');
            $fecha_registro = date('Y-m-d H:i:s');

            $sql = "INSERT INTO cita (
                idcliente,
                idgarante,
                fecha_registro,
                fecha_cita,
                hora_inicio,
                hora_fin,
                medico,
                especialidad,
                monto_cita
            ) VALUES (
                :idcliente,
                :idgarante,
                :fecha_registro,
                :fecha_cita,
                :hora_inicio,
                :hora_fin,
                :medico,
                :especialidad,
                :monto_cita
            )";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':idcliente',$idcliente);
            $resultado->bindParam(':idgarante',$idgarante);
            $resultado->bindParam(':fecha_registro',$fecha_registro);
            $resultado->bindParam(':fecha_cita',$fecha_cita);
            $resultado->bindParam(':hora_inicio',$hora_inicio);
            $resultado->bindParam(':hora_fin',$hora_fin);
            $resultado->bindParam(':medico',$medico);
            $resultado->bindParam(':especialidad',$especialidad);
            $resultado->bindParam(':monto_cita',$monto_cita);

            $resultado->execute();

            return $response->withJson([
                'flag' => 1,
                'message' => "El registro fue satisfactorio."
            ]);

        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "Error al registrar cita.",
                'error' => $th
            ]);
        }
    }
}