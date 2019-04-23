<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use Respect\Validation\Validator as V;
use \Culqi\Culqi;

class Cita
{
    public function __construct($app)
    {
        $this->app = $app;

    }
    /**
     * Registra una cita mandando por json lo siguiente
     * "idgarante" : 2,
	 * "fecha_registro" : "2019-04-08",
	 * "fecha_cita" : "2019-04-15",
	 * "hora_inicio" : "08:00",
	 * "hora_fin" : "08:10",
	 * "medico" : "LEOPOLDO DANTE",
	 * "especialidad" : "NUTRICION"
     *
     * Creado: 08-04-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
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
            $fecha_registro = date('Y-m-d H:i:s');

            $sql = "INSERT INTO cita (
                idcliente,
                idgarante,
                fecha_registro,
                fecha_cita,
                hora_inicio,
                hora_fin,
                medico,
                especialidad
            ) VALUES (
                :idcliente,
                :idgarante,
                :fecha_registro,
                :fecha_cita,
                :hora_inicio,
                :hora_fin,
                :medico,
                :especialidad
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

    /**
     * ESTO SOLO DEBE USARSE EN DESARROLLO.
     * En Producción hay que generar el token con javascript (https://checkout.culqi.com/js/v3)
     *
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function crear_token_culqi(Request $request, Response $response)
    {
        // require __DIR__.'/../culqi.php';
        try {
            $config = $this->app->get('settings')['culqi'];

            $user = $request->getAttribute('decoded_token_data');

            $culqi = new Culqi(array('api_key' => $config['CULQI_PUBLIC_KEY']));

            // Creando Cargo a una tarjeta
            $token = $culqi->Tokens->create(
                array(
                    "card_number" => "4111111111111111",
                    "cvv" => "123",
                    "email" => "wmuro123@me.com", //email must not repeated
                    "expiration_month" => 9,
                    "expiration_year" => 2020,
                    "fingerprint" => 1231234
                )
            );
            echo json_encode("Token: ".$token->id);
        } catch (Exception $e) {
            echo json_encode($e->getMessage());
        }
           /*  return $response->withJson([
                'flag' => 1,
                'message' => $token
            ]);


        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "Error al registrar los datos.",
                'error' => $th
            ]);
        } */
    }
    public function registrar_pago(Request $request, Response $response)
    {
        // require __DIR__.'/../culqi.php';
        try {
            $config = $this->app->get('settings')['culqi'];
            $culqi = new Culqi(array('api_key' => $config['CULQI_PRIVATE_KEY']));

            $user = $request->getAttribute('decoded_token_data');
            $idcliente   = (int)$user->idcliente;
            $idusuario   = (int)$user->idusuario;

            $monto_cita         = $request->getParam('monto_cita');
            $idcita             = $request->getParam('idcita');
            $token              = $request->getParam('token');
            $fecha_pago         = date('Y-m-d H:i:s');
            $estado_cita        = 2; // pagado

            $charge = $culqi->Charges->create(
                array(
                    "amount" 		=> $monto_cita,
                    "currency_code" => "PEN",
                    "email" 		=> 'rguevara@villasalud.pe',
                    "description" 	=> 'Citas Web',
                    "installments" 	=> 0,
                    "source_id" 	=> $token,
                    "metadata" 		=> array(
                                        "idcita" => $idcita,
                                        "idusuario" => $idusuario
                    )
                )
			);

            $datos_cargo = get_object_vars($charge);
            print($datos_cargo);
            $sql = "UPDATE cita SET
                fecha_pago  = :fecha_pago,
                monto_cita  = :monto_cita,
                estado_cita = :estado_cita
                WHERE idcita = $idcita
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':monto_cita', $monto_cita);
            $resultado->bindParam(':fecha_pago', $fecha_pago);
            $resultado->bindParam(':estado_cita', $estado_cita);
            $resultado->execute();

            return $response->withJson([
                'flag' => 1,
                'message' => "Se registró el pago correctamente."
            ]);


        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "Error al registrar los datos.",
                'error' => $th
            ]);
        }
    }
}