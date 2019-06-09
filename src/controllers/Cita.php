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
     * "idhorario" : 97030, // id del horario de sql server que debe estar en el turno selecc
	 * "fecha_registro" : "2019-04-08",
	 * "fecha_cita" : "2019-04-15",
	 * "hora_inicio" : "08:00",
	 * "hora_fin" : "08:15",
	 * "duracionCita" : "15",
     * "idmedico" : 1234, // id del medico proveniente del sql server
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
            $idhorario      = $request->getParam('idhorario');
            $fecha_cita     = $request->getParam('fecha_cita');
            $hora_inicio    = $request->getParam('hora_inicio');
            $hora_fin       = $request->getParam('hora_fin');
            $duracionCita   = $request->getParam('duracionCita');
            $idmedico       = $request->getParam('idmedico');
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

            // REGISTRO EN SQL SERVER
            $sql = "
                SELECT
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
                    cl.tipo_sangre,
                    cl.fecha_nacimiento
                FROM cliente cl
                WHERE cl.idcliente = :idcliente
                LIMIT 1
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(":idcliente", $idcliente);
            $resultado->execute();
            $cliente = $resultado->fetchObject();
            $nombreCompleto = $cliente->apellido_paterno . ' ' . $cliente->apellido_materno . ' , ' . $cliente->nombres;

        // REGISTRO EN SQL SERVER
        // Verificacion si existe cliente
            $sql = "SELECT TOP 1 Persona IdPaciente
                    FROM PersonaMast cli
                    WHERE ( cli.TipoDocumentoIdentidad ='D'
                        AND cli.DocumentoIdentidad = '". $cliente->numero_documento ."' )
                        OR ( cli.TipoDocumento ='D' AND cli.Documento = '". $cliente->numero_documento ."' )
            ";
            $resultado = $this->app->db_mssql->prepare($sql);
            $resultado->execute();
            $res = $resultado->fetchAll();
            if( count($res) > 0 ){
                $paciente = $res[0];
                $IdPaciente = $paciente['IdPaciente'];
            }else{
                // Obtener el ultimo registro de PersonaMast
                $sql = "SELECT max ( PersonaMast.Persona ) id FROM PersonaMast ";
                $resultado = $this->app->db_mssql->prepare($sql);
                $resultado->execute();
                $res = $resultado->fetchAll();
                $IdPaciente = (int)$res[0]['id'] + 1;

                // Registro de PersonaMast
                $sql = "INSERT INTO PersonaMast (
                        Persona,
                        Busqueda,
                        TipoDocumentoIdentidad,
                        DocumentoIdentidad,
                        Origen,
                        ApellidoPaterno,
                        ApellidoMaterno,
                        Nombres,
                        NombreCompleto,
                        FechaNacimiento,
                        Sexo,
                        EstadoCivil,
                        EsPaciente,
                        EsEmpresa,
                        Estado,
                        UltimoUsuario,
                        UltimaFechaModif,
                        IndicadorAutogenerado,
                        TipoDocumento,
                        Documento,
                        TipoPersona
                    ) VALUES (
                        $IdPaciente,
                        '".$nombreCompleto."',
                        'D',
                        '".$cliente->numero_documento."',
                        'LIMA',
                        '".$cliente->apellido_paterno."',
                        '".$cliente->apellido_materno."',
                        '".$cliente->nombres."',
                        '".$nombreCompleto."',
                        '".date('d-m-Y', strtotime($cliente->fecha_nacimiento))."',
                        '".$cliente->sexo."',
                        'S',
                        'S',
                        'N',
                        'A',
                        '',
                        '" . date('d-m-Y H:i:s') ."',
                        1,
                        'D',
                        '".$cliente->numero_documento."',
                        'N'
                    );
                ";
                $resultado = $this->app->db_mssql->prepare($sql);
                $resultado->execute();

                // Registro de SS_GE_Paciente
                $sql = " INSERT INTO SS_GE_Paciente (
                        IdPaciente,
                        IndicadorNuevo,
                        TipoAlmacenamiento,
                        FechaIngreso,
                        Estado,
                        UsuarioCreacion,
                        FechaCreacion,
                        UsuarioModificacion,
                        FechaModificacion
                    )
                    VALUES (
                        $IdPaciente,
                        2,
                        'AC',
                        '" . date('d-m-Y H:i:s') ."',
                        2,
                        'RCORTEZ',
                        '" . date('d-m-Y H:i:s') ."',
                        '',
                        '" . date('d-m-Y H:i:s') ."'
                    );
                ";

                $resultado = $this->app->db_mssql->prepare($sql);
                $resultado->execute();
            }

            // REGISTRO DE CITA EN SQL SERVER
            // Obtener el ultimo registro de SS_CC_Cita
            $sql = "SELECT max ( SS_CC_Cita.IdCita ) id FROM SS_CC_Cita";
            $resultado = $this->app->db_mssql->prepare($sql);
            $resultado->execute();
            $res = $resultado->fetchAll();
            $IdCita = (int)$res[0]['id'] + 1;
            // $fecha_cita = $fecha_cita . ' ' . $hora_inicio;
            $fecha_cita = date('d-m-Y', strtotime($fecha_cita)) . ' ' . $hora_inicio;
            // Registro de SS_CC_Cita
            $sql = "INSERT INTO SS_CC_Cita(
                IdCita,
                IdHorario,
                FechaCita,
                FechaLlegada,
                IndicadorExcedente,
                IndicadorHistoriaClinica,
                DuracionPromedio,
                DuracionReal,
                TipoCita,
                IdPaciente,
                IndicadorInasistencia,
                IndicadorReemplazo,
                EstadoDocumento,
                EstadoDocumentoAnterior,
                Estado,
                UsuarioCreacion,
                FechaCreacion,
                UsuarioModificacion,
                FechaModificacion,
                IndicadorRegistroCompartido,
                IdTipoAtencion,
                IdGrupoAtencion,
                IdServicio,
                IdMedico,
                FechaCitaFecha,
                TipoPaciente,
                TipoCoberturaAtencion,
                IndicadorWeb,
                EstadoDocumentoPrograma  )
            VALUES(
                $IdCita,
                $idhorario,
                '$fecha_cita',
                '$fecha_cita',
                1,
                1,
                $duracionCita,
                $duracionCita,
                1,
                $IdPaciente,
                1,
                1,
                2,
                0,
                2,
                '',
                '" . date('d-m-Y H:i:s') ."',
                '',
                '" . date('d-m-Y H:i:s') ."',
                1,
                1,
                1,
                1,
                $idmedico,
                '$fecha_cita',
                NULL,
                NULL,
                1,
                NULL);
            ";
            $resultado = $this->app->db_mssql->prepare($sql);
            $resultado->execute();

            // Registro de Cita Control
            $sql = "INSERT INTO SS_CC_CitaControl
            VALUES(
                $IdCita,
                1,
                '" . date('d-m-Y H:i:s') ."',
                NULL,
                '',
                2,
                0,
                1,
                NULL,
                2,
                '','" . date('d-m-Y H:i:s') ."',
                '','" . date('d-m-Y H:i:s') ."'
                )
            ";
            $resultado = $this->app->db_mssql->prepare($sql);
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
     * Anula la cita seleccionada
     *
     * Creado: 08-05-2019
     * @author Ing. Luis Luna <luisls1717@gmail.com>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function anular_cita(Request $request, Response $response)
    {
        try {
            $idcita  = $request->getParam('idcita');
            $fechaAnulacion  = date('Y-m-d H:i:s');

            $sql = "UPDATE cita SET
                estado_cita = 0,
                fecha_anulacion  = :fechaAnulacion
                WHERE idcita = $idcita
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':fechaAnulacion', $fechaAnulacion);
            $resultado->execute();

            /**Anulacion de cita en SQL Server */

            $ms_sql = "UPDATE SS_CC_Cita
            SET EstadoDocumento = 5,
                EstadoDocumentoAnterior = 2,
                Estado = 1,
                IdCitaRelacionada = null,
                UsuarioModificacion = 'BFERREYROS',
                MotivoAnulacion = 'CANCELACION',
                FechaModificacion = '" . date('d-m-Y H:i:s') ."'
            WHERE SS_CC_Cita.IdCita = $idcita
            ";
            $resultado = $this->app->db_mssql->prepare($ms_sql);
            $resultado->execute();

            /**Registro cita_control */
            $ms_sql = "SELECT MAX ( SS_CC_CitaControl.Secuencial ) AS secuencial
            FROM SS_CC_CitaControl WITH ( NOLOCK )
            WHERE SS_CC_CitaControl.IdDocumento = $idcita";

            $resultado = $this->app->db_mssql->prepare($ms_sql);
            $resultado->execute();
            $res = $resultado->fetchAll();
            $secuencial = (int)$res[0]['secuencial'] + 1;

            $ms_sql = "INSERT INTO SS_CC_CitaControl
            VALUES(
                $idcita,
                $secuencial,
                '" . date('d-m-Y H:i:s') ."',
                NULL,
                'BFERREYROS',
                5,
                2,
                1,
                NULL,
                2,
                'BFERREYROS',
                '" . date('d-m-Y H:i:s') ."',
                'BFERREYROS',
                '" . date('d-m-Y H:i:s') ."'

            )";
            $resultado = $this->app->db_mssql->prepare($ms_sql);
            $resultado->execute();

            return $response->withJson([
                'flag' => 1,
                'message' => "Se anuló la cita correctamente."
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
                ORDER BY esp.Descripcion ASC
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
            // var_dump($th->getMessage(), 'errorrr');
            return $response->withJson([
                'flag' => 0,
                'message' => $th->getMessage()
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
                    'periodo'           => V::notBlank()->digit(), //
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
                    ORDER BY NombreCompleto ASC
            ";

            $resultado = $this->app->db_mssql->prepare($sql);
            // $resultado->bindParam(':periodo', $periodo);
            // $resultado->bindParam(':idespecialidad', $idespecialidad);

            $resultado->execute();
            if ($lista = $resultado->fetchAll()) {
                $message = "Se encontraron médicos para la búsqueda";
                $flag = 1;
            }else{
                $message = "No se encontraron médicos para la busqueda; intente seleccionando otras fechas.";
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
            if($flag === 1){
                return $response->withJson([
                    'datos' => $data,
                    'flag' => $flag,
                    'message' => $message
                ]);
            }

            return $response->withStatus(400)->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);

        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
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
                        'TITULAR' AS parentesco,
                        UPPER(MONTHNAME(cit.fecha_cita)) AS mes,
                        DAY(cit.fecha_cita) AS dia
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
                        par.descripcion_par AS parentesco,
                        UPPER(MONTHNAME(cit.fecha_cita)) AS mes,
                        DAY(cit.fecha_cita) AS dia
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
            // var_dump($th);
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
                        'TITULAR' AS parentesco,
                        UPPER(MONTHNAME(cit.fecha_cita)) AS mes,
                        DAY(cit.fecha_cita) AS dia
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
                        par.descripcion_par AS parentesco,
                        UPPER(MONTHNAME(cit.fecha_cita)) AS mes,
                        DAY(cit.fecha_cita) AS dia
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
    /**
     * Carga las fechas en modo mock
     *
     * JSON
     *
     * Creado: 04-06-2019
     * @author Ing. Ricardo Luna <luisls1717@gmail.com>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_fechas_mock(Request $request, Response $response)
    {
        // generar fechas del mes
        $periodo = empty($request->getParam('periodo')) ? date('Ym') : $request->getParam('periodo');
        // var_dump($periodo, 'asdd'); exit();
        $anio = substr($periodo, 0, 4);
        $mes = substr($periodo, 4, 2);
        $desdeFecha = '01-'.$mes.'-'.$anio;
        $hastaFecha = date("t-m-Y", strtotime($desdeFecha));
        $fechasDelMes = $this->get_rango_fechas($desdeFecha,$hastaFecha,true);
        $time_first_day_of_month = mktime(0, 0, 0, $mes, 1, $anio);
        $week_day_first = date('N', $time_first_day_of_month);
        // generar otros periodos
        $periodoAnterior = date('Ym', strtotime("-1 month", strtotime($desdeFecha)));
        $periodoSiguiente = date('Ym', strtotime("+1 month", strtotime($desdeFecha)));

        $dataFinal = array();
        $numFinal = 42;
        $countExist = 0;
        for ($i=1; $i <= $numFinal; ) { 
            if( $i <= $week_day_first ){
                $dataFinal[] = array(
                    'fecha' => null,
                    'dia' => null,
                    'class' => '',
                    'valid' => '-'
                );
            }
            if( $i > $week_day_first ){
                if(array_key_exists($countExist, $fechasDelMes)){
                    $dataFinal[] = array(
                        'fecha' => $fechasDelMes[$countExist],
                        'dia' => date('d',strtotime($fechasDelMes[$countExist])),
                        'class' => '',
                        'valid' => 'no'
                    );
                }else{
                    $dataFinal[] = array(
                        'fecha' => null,
                        'dia' => null,
                        'class' => '',
                        'valid' => '-'
                    );
                }
                $countExist++;
            }
            
            $i++;
        }
        // agrupar por semana
        $dataGroupFinal = array_chunk($dataFinal, 7);
        $mesSeleccionado = $this->convertir_mes(date('M',strtotime($desdeFecha)));

        return $response->withJson([
            'datos' => array(
                'calendario'=> $dataGroupFinal,
                'mes'=> $mesSeleccionado,
                'periodoAnterior'=> $periodoAnterior,
                'periodoSiguiente'=> $periodoSiguiente
            ),
            'flag' => 1,
            'message' => ''
        ]);
    }
    /**
     * Carga las fechas programadas segun dia, especialidad y medico elegido
     *
     * JSON
     * "periodo" : "201905",
     * "idespecialidad" : 18,
     * "idmedico" : 64584
     *
     * Creado: 19-05-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     * Modificado: 02-06-2019
     * @author Ing. Ricardo Luna <luisls1717@gmail.com>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_fechas_programadas(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;

            // VALIDACIONES
            $validator = $this->app->validator->validate($request, [
                'periodo'           => V::notBlank()->digit(),
                'idespecialidad'    => V::notBlank()->digit(),
                'idmedico'    => V::notBlank()->digit()
            ]);

            if ( !$validator->isValid() ) {
                $errors = $validator->getErrors();
                return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
            }

            $periodo        = $request->getParam('periodo');
            $idespecialidad = $request->getParam('idespecialidad');
            $idmedico       = $request->getParam('idmedico');

            $sql = "SELECT DISTINCT
                    hor.FechaInicio
                FROM SS_CC_Horario hor
                LEFT JOIN SS_GE_Servicio serv ON hor.IdServicio = serv.IdServicio
                LEFT JOIN SS_GE_GrupoConsultorio gc ON gc.IdConsultorio = hor.IdConsultorio
                WHERE hor.Periodo = " . $periodo . "
                AND hor.Medico = " . $idmedico . "

                AND hor.Estado = 2
                AND hor.IdEspecialidad = " . $idespecialidad . "
                AND hor.IdTurno IN (1,2)
                AND (
                        ( hor.IdEspecialidad = " . $idespecialidad . " AND
                        gc.IdGrupoAtencion = 1 AND
                        serv.IdServicio = 1
                        ) OR
                        ( hor.IndicadorCompartido = 2 AND
                        hor.IdGrupoAtencionCompartido = 1 AND
                        hor.IdEspecialidad = " . $idespecialidad . "
                        )
                    )
                ORDER BY hor.FechaInicio

            ";

            $resultado = $this->app->db_mssql->prepare($sql);
            // $resultado->bindParam(':periodo', $periodo);
            // $resultado->bindParam(':idespecialidad', $idespecialidad);

            $resultado->execute();
            if ($lista = $resultado->fetchAll()) {
                $message = "Se encontraron fechas programadas";
                $flag = 1;
            }else{
                $message = "No tiene fechas programadas";
                $flag = 0;
            }
            // generar fechas del mes
            $anio = substr($periodo, 0, 4);
            $mes = substr($periodo, 4, 2);
            $desdeFecha = '01-'.$mes.'-'.$anio;
            $hastaFecha = date("t-m-Y", strtotime($desdeFecha));
            $fechasDelMes = $this->get_rango_fechas($desdeFecha,$hastaFecha,true);
            $time_first_day_of_month = mktime(0, 0, 0, $mes, 1, $anio);
            $week_day_first = date('N', $time_first_day_of_month);
            // generar otros periodos
            $periodoAnterior = date('Ym', strtotime("-1 month", strtotime($desdeFecha)));
            $periodoSiguiente = date('Ym', strtotime("+1 month", strtotime($desdeFecha)));

            $dataFinal = array();
            $numFinal = 42;
            $countExist = 0;
            for ($i=1; $i <= $numFinal; ) { 
                if( $i <= $week_day_first ){
                    $dataFinal[] = array(
                        'fecha' => null,
                        'dia' => null,
                        'class' => '',
                        'valid' => '-'
                    );
                }
                if( $i > $week_day_first ){
                    if(array_key_exists($countExist, $fechasDelMes)){
                        $dataFinal[] = array(
                            'fecha' => $fechasDelMes[$countExist],
                            'dia' => date('d',strtotime($fechasDelMes[$countExist])),
                            'class' => '',
                            'valid' => 'no'
                        );
                    }else{
                        $dataFinal[] = array(
                            'fecha' => null,
                            'dia' => null,
                            'class' => '',
                            'valid' => '-'
                        );
                    }
                    $countExist++;
                }

                $i++;
            }
            // generar fechas validas
            foreach ($dataFinal as $key => $row) {
                foreach ($lista as $rowLista) {
                    if( $row['fecha'] == date('d-m-Y',strtotime($rowLista['FechaInicio'])) ){
                        $dataFinal[$key]['valid'] = 'si';
                        $dataFinal[$key]['class'] = ' active';
                    }
                }
            }
            // agrupar por semana
            $dataGroupFinal = array_chunk($dataFinal, 7);
            $mesSeleccionado = $this->convertir_mes(date('M',strtotime($desdeFecha)));

            return $response->withJson([
                'datos' => array(
                    'calendario'=> $dataGroupFinal,
                    'mes'=> $mesSeleccionado,
                    'periodoAnterior'=> $periodoAnterior,
                    'periodoSiguiente'=> $periodoSiguiente
                ),
                'flag' => $flag,
                'message' => $message
            ]);
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => 'Ocurrió un error al cargar los datos'
            ]);
        }
    }
    /**
     * Carga los cupos de una fecha según médico y especialidad elegida
     *
     * JSON
     * "fecha" : "2019-05-10",
     * "idespecialidad" : 18,
     * "idmedico" : 64584
     *
     * Creado: 20-05-2019
     * @author Ing. Ruben Guevara <rguevarac@hotmail.es>
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public function cargar_horario(Request $request, Response $response, array $args)
    {
        try {
            $user = $request->getAttribute('decoded_token_data');

            $idusuario = $user->idusuario;

            // VALIDACIONES
                $validator = $this->app->validator->validate($request, [
                    'fecha'    => V::notBlank(),
                    'idespecialidad'    => V::notBlank()->digit(),
                    'idmedico'    => V::notBlank()->digit()
                ]);

                if ( !$validator->isValid() ) {
                    $errors = $validator->getErrors();
                    return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
                }

            $fecha          = $request->getParam('fecha');
            $idespecialidad = $request->getParam('idespecialidad');
            $idmedico       = $request->getParam('idmedico');

            $sql = "SELECT
                    hor.IdHorario,
                    CAST(hor.HoraInicio AS TIME) AS InicioHorario,
                    CAST(hor.HoraFin AS TIME) AS FinHorario,
                    hor.TiempoPromedioAtencion AS Intervalo,
                    ci.IdCita,
                    ci.FechaCita,
                    CAST(FechaInicio AS DATE) AS Fecha
                FROM SS_CC_Horario hor
                LEFT JOIN SS_CC_Cita ci ON hor.IdHorario = ci.IdHorario
                WHERE hor.FechaInicio = '" . date('d-m-Y',strtotime($fecha)). "'
                AND hor.Medico = " . $idmedico . "

                AND hor.Estado = 2
                AND hor.IdEspecialidad = " . $idespecialidad . "
                AND hor.IdTurno IN (1,2)
                ORDER BY hor.HoraInicio

            ";

            $resultado = $this->app->db_mssql->prepare($sql);
            $resultado->execute();
            if ($lista = $resultado->fetchAll()) {
                $message = "Se encontraron fechas programadas";
                $flag = 1;
                $arrListado = array();
                foreach ($lista as $key => $row) {
                    $arrListado[$row['IdHorario']] = array(
                        'IdHorario' => $row['IdHorario'],
                        'InicioHorario' => $row['InicioHorario'],
                        'FinHorario' => $row['FinHorario'],
                        'Intervalo' => $row['Intervalo'],
                        'Fecha' => $row['Fecha'],
                        'turnos_ocupados' => array()
                    );
                }

                foreach ($arrListado as $key => $value) {
                    foreach ($lista as $row) {
                        if( $row['IdHorario'] == $key ){
                            $arrAux[] = date('Y-m-d H:i',strtotime($row['FechaCita']));
                        }
                    }
                    $arrListado[$key]['turnos_ocupados'] = $arrAux;
                }

                $arrListado = array_values($arrListado);
            }else{
                $message = "No se encontraron cupos disponibles en esta fecha.";
                $flag = 0;
            }
			$data = array();
            $arrCitas = array();

            foreach ($arrListado as $row) {
                $hora_inicio = date('H:i',strtotime($row['InicioHorario']));
                $i = 1;
                while ( strtotime($row['FinHorario']) > strtotime($hora_inicio) ) {
                    $hora_fin = date('H:i',(strtotime($hora_inicio) + $row['Intervalo']*60) );
                    $fecha_inicio = $row['Fecha'] . ' ' . $hora_inicio;
                    if( !in_array($fecha_inicio, $row['turnos_ocupados']) ){

                        array_push($arrCitas,
                        array(
                            'idhorario' => $row['IdHorario'],
                            'numero_cupo' => $i,
                            'hora_inicio' => $hora_inicio,
                            'hora_fin' => $hora_fin
                            )
                        );
                    }

                    $hora_inicio = $hora_fin;
                    $i++;
                }

            }

            return $response->withJson([
                'datos' => $arrCitas,
                'flag' => $flag,
                'message' => $message
            ]);

        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => 'Ocurrió un error al cargar los datos'
            ]);
        }
    }

    private function get_rango_fechas($start, $end, $onlyDate = FALSE) {
        $range = array();
        if (is_string($start) === true) $start = strtotime($start);
        if (is_string($end) === true ) $end = strtotime($end);
        do {
            if($onlyDate) {
                $range[] = date('d-m-Y', $start);
                $start = strtotime("+ 1 day", $start);
            }else{
                $range[] = date('d-m-Y H:i:s', $start);
                $start = strtotime("+ 1 day", $start);
            }

        } while($start <= $end);

        if(count($range) < 1) {
            if($onlyDate)
                { $range[] = date('d-m-Y'); }
            else
                { $range[] = date('d-m-Y H:i:s'); }
        }
        return $range;
    }
    private function convertir_mes($mes) {
        // if(strlen($nom_mes)==3){
        //     $mes = date('M',strtotime($nom_mes));
        //     $mes_num = NULL;
        // }else{
        //     $mes_num = $nom_mes;
        //     $mes = NULL;
        // }
        if ($mes == 'Jan')
        $resultado = 'ENERO';
        if ($mes == 'Feb')
        $resultado = 'FEBRERO';
        if ($mes == 'Mar')
        $resultado = 'MARZO';
        if ($mes == 'Apr')
        $resultado = 'ABRIL';
        if ($mes == 'May')
        $resultado = 'MAYO';
        if ($mes == 'Jun')
        $resultado = 'JUNIO';
        if ($mes == 'Jul')
        $resultado = 'JULIO';
        if ($mes == 'Aug')
        $resultado = 'AGOSTO';
        if ($mes == 'Sep')
        $resultado = 'SEPTIEMBRE';
        if ($mes == 'Oct')
        $resultado = 'OCTUBRE';
        if ($mes == 'Nov')
        $resultado = 'NOVIEMBRE';
        if ($mes == 'Dec')
        $resultado = 'DICIEMBRE';
        return @$resultado;
    }
}