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
     * "idcliente" : 1, // id del cliente que se va a atender
     * "idgarante" : 2,
	 * "fecha_registro" : "2019-04-08",
	 * "fecha_cita" : "2019-04-15",
	 * "hora_inicio" : "08:00",
	 * "hora_fin" : "08:10",
	 * "medico" : "LEOPOLDO DANTE",
	 * "especialidad" : "NUTRICION"
     *
     * Creado: 08-04-2019
     * Modificado: 23-04-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function registrar_cita(Request $request, Response $response)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            // $idcliente = (int)$user->idcliente;

            // VALIDACIONES
                $validator = $this->app->validator->validate($request, [
                    'idcliente'     => V::notBlank()->digit(),
                    'idgarante'     => V::notBlank()->digit(),
                    'especialidad'  => V::notBlank()->alnum(),
                    'medico'        => V::notBlank()->alpha(),
                    'fecha_cita'    => V::notBlank()->date(),
                ]);

                if ( !$validator->isValid() ) {
                    $errors = $validator->getErrors();
                    return $response->withJson(['error' => true, 'message' => $errors]);
                }

            $idcliente      = $request->getParam('idcliente');
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
    /**
     * Carga las especialidades para seleccionar
     *
     * Creado: 08-05-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_especialidades(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;

            $sql = "
                SELECT
                    esp.IdEspecialidad,
                    esp.Codigo,
                    esp.Descripcion,
                    esp.CantidadCitasAdicional
                FROM
                    SS_GE_Especialidad esp
                WHERE
                    esp.Estado = 2
            ";

            $resultado = $this->app->db_mssql->prepare($sql);
            $resultado->execute();
            if ($lista = $resultado->fetchAll()) {
                $message = "Se encontraron especialidades";
                $flag = 1;
            }else{
                $message = "No se encontraron especialidades";
                $flag = 0;
            }
            $data = array();
            foreach ($lista as $row) {
                array_push($data,
                    array(
                        'idespecialidad'    => $row['IdEspecialidad'],
                        'codigo'            => $row['Codigo'],
                        'descripcion'       => iconv("windows-1252", "utf-8", $row['Descripcion']),
                        'adicionales'       => $row['CantidadCitasAdicional'],
                    )
                );
            }

            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);

        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => $th
            ]);
        }

    }
    /**
     * Carga medicos segun la especialidad elegida
     *
     * Creado: 23-04-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_medicos_por_especialidad(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;

            // VALIDACIONES
                $validator = $this->app->validator->validate($request, [
                    'periodo'           => V::notBlank()->digit(),
                    'idespecialidad'    => V::notBlank()->digit()
                ]);

                if ( !$validator->isValid() ) {
                    $errors = $validator->getErrors();
                    return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
                }

            $periodo        = $request->getParam('periodo');
            $idespecialidad = $request->getParam('idespecialidad');

            $sql = "
                SELECT DISTINCT
                    hor.Medico,
                    hor.IdEspecialidad,
                    hor.Estado,
                    empl.CMP,
                    empl.Foto,
                    per.NombreCompleto
                FROM SS_CC_Horario hor
                LEFT JOIN SS_GE_Servicio serv ON hor.IdServicio = serv.IdServicio
                LEFT JOIN PersonaMast per ON hor.Medico = per.Persona
                LEFT JOIN EmpleadoMast empl ON hor.Medico = empl.Empleado
                LEFT JOIN SS_GE_GrupoConsultorio cons ON hor.IdConsultorio = cons.IdConsultorio
                WHERE hor.Periodo = " . $periodo . "
					AND hor.Estado = 2
					AND empl.Estado = 'A'
					AND per.Estado = 'A'
					AND (
							( hor.IdEspecialidad = " . $idespecialidad . "
								AND  cons.IdGrupoAtencion = 1 AND
								serv.IdServicio = 1
							) OR
							( hor.IndicadorCompartido = 2 AND
								hor.IdGrupoAtencionCompartido = 1 AND
								hor.IdEspecialidad = " . $idespecialidad . "
							)
						)

            ";

            $resultado = $this->app->db_mssql->prepare($sql);
            // $resultado->bindParam(':periodo', $periodo);
            // $resultado->bindParam(':idespecialidad', $idespecialidad);

            $resultado->execute();
            if ($lista = $resultado->fetchAll()) {
                $message = "Se encontraron citas realizadas";
                $flag = 1;
            }else{
                $message = "No tiene citas realizadas";
                $flag = 0;
            }
			$data = array();
            foreach ($lista as $row) {
                array_push($data,
                    array(
                        'idmedico'    		=> $row['Medico'],
                        'descripcion'       => iconv("windows-1252", "utf-8", $row['NombreCompleto']),
                        'idespecialidad'    => $row['IdEspecialidad']
                    )
                );
            }
            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);

        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => 'Ocurrió un error al cargar los medicos'
            ]);
        }

    }
        /**
     * Carga las citas pendientes de pago tanto del titular como de los familiares del usuario logueado
     *
     * Creado: 23-04-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_citas_pendientes(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idusuario = $user->idusuario;
            $sql = "
                SELECT c.*
                FROM(
                    SELECT
                        cit.idcita,
                        cit.idcliente,
                        cit.fecha_cita,
                        cit.hora_inicio,
                        cit.medico,
                        cit.especialidad,
                        CONCAT (CONCAT_WS(' ',cl.nombres, cl.apellido_paterno, cl.apellido_materno), ' | TITULAR') AS paciente,
                        cl.nombres,
                        cl.apellido_paterno,
                        cl.apellido_materno,
                        'TITULAR' AS parentesco
                    FROM usuario AS us
                    JOIN cliente cl ON us.idusuario = cl.idusuario
                    JOIN cita cit ON cl.idcliente = cit.idcliente
                    WHERE us.idusuario = :idusuario
                    AND cit.estado_cita IN (1,2)
                    AND cit.fecha_cita >= NOW() 
                    UNION ALL
                    SELECT
                        cit.idcita,
                        cit.idcliente,
                        cit.fecha_cita,
                        cit.hora_inicio,
                        cit.medico,
                        cit.especialidad,
                        CONCAT (CONCAT_WS(' ',fam.nombres, fam.apellido_paterno, fam.apellido_materno), ' | ', par.descripcion_par) AS paciente,
                        fam.nombres,
                        fam.apellido_paterno,
                        fam.apellido_materno,
                        par.descripcion_par AS parentesco
                    FROM usuario AS us
                    JOIN cliente cl ON us.idusuario = cl.idusuario
                    JOIN cliente fam ON cl.idcliente = fam.idtitularcliente
                    JOIN parentesco par ON fam.idparentesco = par.idparentesco
                    JOIN cita cit ON fam.idcliente = cit.idcliente
                    WHERE us.idusuario = :idusuario
                    AND cit.estado_cita IN (1,2)
                    AND cit.fecha_cita >= NOW() 
                ) AS c
                ORDER BY c.fecha_cita DESC
                LIMIT 10
            ";
            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();
            if ($data = $resultado->fetchAll()) {
                $message = "Se encontraron citas pendientes";
                $flag = 1;
            }else{
                $message = "No tiene citas pendientes";
                $flag = 0;
            }
            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "El token no es válido o ya no está disponible."
            ]);
        }
    }
    /**
     * Carga las citas pagadas tanto del titular como de los familiares del usuario logueado
     *
     * Creado: 23-04-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_citas_realizadas(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');
            $idusuario = $user->idusuario;
            $sql = "
                SELECT c.*
                FROM(
                    SELECT
                        cit.idcita,
                        cit.idcliente,
                        cit.fecha_cita,
                        cit.hora_inicio,
                        cit.medico,
                        cit.especialidad,
                        CONCAT (CONCAT_WS(' ',cl.nombres, cl.apellido_paterno, cl.apellido_materno), ' | TITULAR') AS paciente,
                        cl.nombres,
                        cl.apellido_paterno,
                        cl.apellido_materno,
                        'TITULAR' AS parentesco
                    FROM usuario AS us
                    JOIN cliente cl ON us.idusuario = cl.idusuario
                    JOIN cita cit ON cl.idcliente = cit.idcliente
                    WHERE us.idusuario = :idusuario
                    AND cit.estado_cita IN (1,2)
                    AND cit.fecha_cita < NOW() 
                    UNION ALL
                    SELECT
                        cit.idcita,
                        cit.idcliente,
                        cit.fecha_cita,
                        cit.hora_inicio,
                        cit.medico,
                        cit.especialidad,
                        CONCAT (CONCAT_WS(' ',fam.nombres, fam.apellido_paterno, fam.apellido_materno), ' | ', par.descripcion_par) AS paciente,
                        fam.nombres,
                        fam.apellido_paterno,
                        fam.apellido_materno,
                        par.descripcion_par AS parentesco
                    FROM usuario AS us
                    JOIN cliente cl ON us.idusuario = cl.idusuario
                    JOIN cliente fam ON cl.idcliente = fam.idtitularcliente
                    JOIN parentesco par ON fam.idparentesco = par.idparentesco
                    JOIN cita cit ON fam.idcliente = cit.idcliente
                    WHERE us.idusuario = :idusuario
                    AND cit.estado_cita IN (1,2) 
                    AND cit.fecha_cita < NOW() 
                ) AS c
                ORDER BY c.fecha_cita DESC
                LIMIT 10
            ";
            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idusuario", $idusuario);
            $resultado->execute();
            if ($data = $resultado->fetchAll()) {
                $message = "Se encontraron citas realizadas";
                $flag = 1;
            }else{
                $message = "No tiene citas realizadas";
                $flag = 0;
            }
            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "El token no es válido o ya no está disponible."
            ]);
        }
    }
}