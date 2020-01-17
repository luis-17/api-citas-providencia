<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Respect\Validation\Validator as V;
use \Culqi\Culqi;

class Cita
{
    public function __construct($app)
    {
        $this->app = $app;
    }
    /**
        * Registra la transacción como codigo unico, de tal forma que una cita puede tener varias transacciones pero solo una autorizada.
        * idcita: 21
        * Creado: 08-04-2019
        * Modificado: 23-04-2019
        * @author Ing. Ricardo Luna <luisls1717@gmail.com>
        * @param Request $request
        * @param Response $response
        * @return void
    */
    public function registrar_transaccion(Request $request, Response $response)
    {
        try {
            // VALIDACIONES
            $validator = $this->app->validator->validate($request, [
                'idcita'     => V::notBlank()->digit()
            ]);

            if ( !$validator->isValid() ) {
                // var_dump('entroo');
                $errors = $validator->getErrors();
                return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
            }
            $idcita     = $request->getParam('idcita');
            $fecha      = date('Y-m-d H:i:s');

            // insertamos en tabla transaccion 
            $sqlInsertTran = "INSERT INTO transaccion (
                idcita,
                fecha
            ) VALUES (
                :idcita,
                :fecha
            )";
            $resultado = $this->app->db->prepare($sqlInsertTran);
            $resultado->bindParam(':idcita',$idcita);
            $resultado->bindParam(':fecha',$fecha);
            $resultado->execute();
            $tracod = $this->app->db->lastInsertId();

            $purchaseOperationNumber = str_pad($idcita.$tracod,6,"0",STR_PAD_LEFT);
            
            // procesamos info
            $sqlCita = "
                SELECT
                    ci.idcita
                FROM cita AS ci
                WHERE ci.idcita = :idcita
                LIMIT 1
            ";
            $resultado = $this->app->db->prepare($sqlCita);
            $resultado->bindParam(":idcita", $idcita);
            $resultado->execute();
            $fCita = $resultado->fetchObject();

            $monto = 10; // cambiar aqui precio
            $montoPorCien = $monto * 100;
            $configPayme = $this->app->get('settings')['payme'];
            $purchaseVerification = openssl_digest(
                (
                    $configPayme['acquirerId']. 
                    $configPayme['idCommerce']. 
                    $purchaseOperationNumber. 
                    $montoPorCien. 
                    $configPayme['purchaseCurrencyCode']. 
                    $configPayme['keyPasarela']
                ),
                'sha512'
            );
            
            // actualizamos en tabla transaccion
            $sql = "UPDATE transaccion SET
                purchaseVerification  = :purchaseVerification,
                purchaseOperationNumber  = :purchaseOperationNumber
                WHERE idtransaccion = $tracod
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':purchaseVerification', $purchaseVerification);
            $resultado->bindParam(':purchaseOperationNumber', $purchaseOperationNumber);
            $resultado->execute();

            return $response->withJson([
                'flag' => 1,
                'message' => "El registro fue satisfactorio.",
                'purchaseVerification' => $purchaseVerification,
                'purchaseOperationNumber'=> $purchaseOperationNumber
            ]);
        } catch (\Exception $th) {
            return $response->withJson([
                'flag' => 0,
                'message' => "Error al registrar transacción.",
                'error' => $th
            ]);
        }
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

            // VALIDACIONES
            $validator = $this->app->validator->validate($request, [
                'idcliente'     => V::notBlank()->digit(),
                'idgarante'     => V::optional(V::digit()),
                'especialidad'  => V::notBlank()->alnum(),
                'medico'        => V::notBlank()->stringType(),
                'fecha_cita'    => V::notBlank()->date(),
            ]);

            if ( !$validator->isValid() ) {
                $errors = $validator->getErrors();
                return $response->withStatus(400)->withJson(['error' => true, 'message' => $errors]);
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
            $idespecialidad   = $request->getParam('idespecialidad');
            $fecha_registro = date('Y-m-d H:i:s');

            // VALIDAR QUE NO SE PUEDA REGISTRAR UNA CITA EN UN TURNO OCUPADO(importante) 
            $fechaCitaParaValidar =date('Y-m-d', strtotime($fecha_cita)) . ' ' . $hora_inicio;
            $sqlValidMultiple = "SELECT TOP 1 ci.IdCita 
                FROM SS_CC_Cita ci
                WHERE ci.IdHorario = ".$idhorario." 
                AND ci.FechaCita = '".$fechaCitaParaValidar."'
                AND ci.Estado = 2 
            ";
            

            $settings = $this->app->get('settings')['sqlsrv'];
            $conn = sqlsrv_connect($settings['host'], array(
                'Database' => $settings['dbname'],
                'Uid' => $settings['user'],
                'PWD' => $settings['pass']
            ));
            if( $conn === false ){
                throw new Exception(sqlsrv_errors()[0]['message']);
            }
            $resultado = sqlsrv_query($conn, $sqlValidMultiple);
            $arrExistCita = array();
            while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
                array_push($arrExistCita, $row);
            }
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($sqlValidMultiple);
            // $resultado->execute();
            // $existCita = $resultado->fetchObject();
            if ( !empty($arrExistCita) ) {
                return $response->withStatus(400)->withJson([
                    'error' => true, 
                    'message' => 'El cupo: '.$hora_inicio.' ya ha sido tomado por un paciente. Intente con otro horario.'
                ]);
            }

            // VALIDAR QUE EL USUARIO NO PUEDA REGISTRAR MULTIPLES CITAS A SU NOMBRE
            $sqlInCita = "
                SELECT ci.idcita, ci.idcitaspring
                FROM cita AS ci
                JOIN cliente cl ON ci.idcliente = cl.idcliente
                WHERE cl.idcliente = :idcliente 
                AND ci.idcitaspring IS NOT NULL 
            ";

            $resultado = $this->app->db->prepare($sqlInCita);
            $resultado->bindParam(":idcliente", $idcliente);
            $resultado->execute();
            $dataInCitas = $resultado->fetchAll();
            $arrInCitas = array();
            foreach ($dataInCitas as $key => $row) {
                array_push($arrInCitas, $row['idcitaspring']);
            }
            if(!empty($dataInCitas)){
                $condition = implode(', ', $arrInCitas);
                // $condition = implode(', ', array_map('mysql_real_escape_string', $arr)); 
                $sqlValid = "SELECT TOP 1 ci.IdCita 
                    FROM SS_CC_Cita ci 
                    INNER JOIN SS_CC_Horario hor ON ci.IdHorario = hor.IdHorario 
                    WHERE ci.IdCita IN (".$condition.") 
                    AND CAST(ci.FechaCita AS DATE) = '".$fecha_cita."' 
                    AND ( ci.IdHorario = '".$idhorario."' OR hor.IdEspecialidad = '".$idespecialidad."' )
                    AND ci.Estado = 2 
                ";
                $resultado = sqlsrv_query($conn, $sqlValid);
                $arrExistCita = array();
                while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
                    array_push($arrExistCita, $row);
                }
                sqlsrv_free_stmt($resultado);
                // $resultado = $this->app->dblib->prepare($sqlValid);
                // $resultado->execute();
                // $existCita = $resultado->fetchObject();

                if ( !empty($arrExistCita) ) {
                    return $response->withStatus(400)->withJson([
                        'error' => true, 
                        'message' => 'No puedes reservar otra cita en el mismo día y para la misma especialidad.'
                    ]);
                }
            }
            

            // Obtener el ultimo registro de SS_CC_Cita
            $sql = "SELECT max ( SS_CC_Cita.IdCita ) id FROM SS_CC_Cita";
            $resultado = sqlsrv_query($conn, $sql);
            $fCita = array();
            while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
                $fCita = array(
                    'id' => $row['id']
                );
            }
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($sql);
            // $resultado->execute();
            // $res = $resultado->fetchAll();
            $IdCita = (int)$fCita['id'] + 1;

            $sql = "INSERT INTO cita (
                idcliente,
                idgarante,
                fecha_registro,
                fecha_cita,
                hora_inicio,
                hora_fin,
                medico,
                especialidad,
                idcitaspring
            ) VALUES (
                :idcliente,
                :idgarante,
                :fecha_registro,
                :fecha_cita,
                :hora_inicio,
                :hora_fin,
                :medico,
                :especialidad,
                :idcitaspring
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
            $resultado->bindParam(':idcitaspring',$IdCita);

            $resultado->execute();

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
            $resultado = sqlsrv_query($conn, $sql);
            $fCliente = array();
            while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
                $fCliente = array(
                    'IdPaciente' => $row['IdPaciente']
                );
            }
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($sql);
            // $resultado->execute();
            // $res = $resultado->fetchAll();
            $IdPaciente = null;
            if( !empty($fCliente) ){
                // $paciente = $res[0];
                $IdPaciente = $fCliente['IdPaciente'];
            }else{
                // Obtener el ultimo registro de PersonaMast
                $sql = "SELECT max ( PersonaMast.Persona ) id FROM PersonaMast ";
                $resultado = sqlsrv_query($conn, $sql);
                $fCliente = array();
                while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
                    $fCliente = array(
                        'id' => $row['id']
                    );
                }
                sqlsrv_free_stmt($resultado);
                // $resultado = $this->app->dblib->prepare($sql);
                // $resultado->execute();
                // $res = $resultado->fetchAll();
                $IdPaciente = (int)$fCliente['id'] + 1;

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
                        TipoPersona,
                        Celular,
                        CorreoElectronico
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
                        '".date('Y-m-d', strtotime($cliente->fecha_nacimiento))."',
                        '".$cliente->sexo."',
                        'S',
                        'S',
                        'N',
                        'A',
                        '',
                        '" . date('Y-m-d H:i:s') ."',
                        1,
                        'D',
                        '".$cliente->numero_documento."',
                        'N',
                        '".$cliente->telefono."',
                        '".$cliente->correo."'
                    );
                ";
                $resultado = sqlsrv_query($conn, $sql);
                sqlsrv_free_stmt($resultado);
                // $resultado = $this->app->dblib->prepare($sql);
                // $resultado->execute();

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
                        '" . date('Y-m-d H:i:s') ."',
                        2,
                        'RCORTEZ',
                        '" . date('Y-m-d H:i:s') ."',
                        '',
                        '" . date('Y-m-d H:i:s') ."'
                    );
                ";
                $resultado = sqlsrv_query($conn, $sql);
                sqlsrv_free_stmt($resultado);
                // $resultado = $this->app->dblib->prepare($sql);
                // $resultado->execute();
            }

            // REGISTRO DE CITA EN SQL SERVER
            $fecha_cita = date('Y-m-d', strtotime($fecha_cita)) . ' ' . $hora_inicio;
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
                '" . date('Y-m-d H:i:s') ."',
                '',
                '" . date('Y-m-d H:i:s') ."',
                1,
                1,
                1,
                1,
                $idmedico,
                '$fecha_cita',
                NULL,
                NULL,
                2,
                NULL);
            ";
            $resultado = sqlsrv_query($conn, $sql);
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($sql);
            // $resultado->execute();

            // Registro de Cita Control
            $sql = "INSERT INTO SS_CC_CitaControl
            VALUES(
                $IdCita,
                1,
                '" . date('Y-m-d H:i:s') ."',
                NULL,
                '',
                2,
                0,
                1,
                NULL,
                2,
                '','" . date('Y-m-d H:i:s') ."',
                '','" . date('Y-m-d H:i:s') ."'
                )
            ";
            $resultado = sqlsrv_query($conn, $sql);
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($sql);
            // $resultado->execute();

            $fromAlias = 'Clínica Providencia';
            $asunto = '¡Ha reservado su cita correctamente! - Clínica Providencia';
            $mensaje = '<html lang="es">';
            $mensaje .= '<body style="font-family: sans-serif;" >';
              $mensaje .= '<div style="align-content: center;">';
                $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
                  $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
                $mensaje .= '</div>';
                $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
                  $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
                    $mensaje .= '<h2 style="margin: 0;color: #739525;margin-bottom: 1.75rem;"> <strong style="color:#00386c;">'.strtoupper($cliente->nombres).',</strong> <br> ha reservado su cita en línea de manera correcta.</h2>';
                    $mensaje .= '<div style="font-size:16px;color: #777777;"> Gracias por su confianza y preferencia. A continuación le brindamos los datos de su cita médica: <br /> <br /> ';
                      $mensaje .= '<table style="width:100%;color: #777777;">';
                        $mensaje .= '<tr><td><b>FECHA DE CITA:</b></td><td>'.date('d-m-Y', strtotime($fecha_cita)).'</td></tr>';
                        $mensaje .= '<tr><td><b>HORA:</b></td><td>'.$hora_inicio.'</td></tr>';
                        $mensaje .= '<tr><td><b>ESPECIALIDAD:</b></td><td>'.$especialidad.'</td></tr>';
                        $mensaje .= '<tr><td><b>MÉDICO:</b></td><td>'.$medico.'</td></tr>';
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
            $mail->AddAddress($cliente->correo);
            $mail->Send();
            return $response->withJson([
                'flag' => 1,
                'message' => "El registro fue satisfactorio."
            ]);
        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
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
                    "amount"        => $monto_cita,
                    "currency_code" => "PEN",
                    "email"         => 'rguevara@villasalud.pe',
                    "description"   => 'Citas Web',
                    "installments"  => 0,
                    "source_id"     => $token,
                    "metadata"      => array(
                                        "idcita" => $idcita,
                                        "idusuario" => $idusuario
                    )
                )
            );

            $datos_cargo = get_object_vars($charge);
            // print($datos_cargo);
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
            // obtenemos cita
            $sqlCita = "
                SELECT
                    ci.idcita, ci.idcitaspring
                FROM cita AS ci
                WHERE ci.idcita = :idcita
                LIMIT 1
            ";
            $resultado = $this->app->db->prepare($sqlCita);
            $resultado->bindParam(":idcita", $idcita);
            $resultado->execute();
            $fCita = $resultado->fetchObject();

            $sql = "UPDATE cita SET
                estado_cita = 0,
                fecha_anulacion  = :fechaAnulacion
                WHERE idcita = $idcita
            ";

            $resultado = $this->app->db->prepare($sql);
            $resultado->bindParam(':fechaAnulacion', $fechaAnulacion);
            $resultado->execute();
            $settings = $this->app->get('settings')['sqlsrv'];
            $conn = sqlsrv_connect($settings['host'], array(
                'Database' => $settings['dbname'],
                'Uid' => $settings['user'],
                'PWD' => $settings['pass']
            ));
            if( $conn === false ){
                throw new Exception(sqlsrv_errors()[0]['message']);
            }
            
            // $arrExistCita = array();
            // while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
            //     array_push($arrExistCita, $row);
            // }
            
            /**Anulacion de cita en SQL Server */
            $ms_sql = "UPDATE SS_CC_Cita
            SET EstadoDocumento = 5,
                EstadoDocumentoAnterior = 2,
                Estado = 1,
                IdCitaRelacionada = null,
                UsuarioModificacion = 'BFERREYROS',
                MotivoAnulacion = 'CANCELADO POR EL USUARIO(WEB)',
                FechaModificacion = '" . date('Y-m-d H:i:s') ."'
            WHERE SS_CC_Cita.IdCita = $fCita->idcitaspring
            ";
            $resultado = sqlsrv_query($conn, $ms_sql);
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($ms_sql);
            // $resultado->execute();

            /**Registro cita_control */
            $ms_sql = "SELECT MAX ( SS_CC_CitaControl.Secuencial ) AS secuencial
            FROM SS_CC_CitaControl WITH ( NOLOCK )
            WHERE SS_CC_CitaControl.IdDocumento = $fCita->idcitaspring";

            $resultado = sqlsrv_query($conn, $ms_sql);
            $fCitaCC = array();
            while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
                $fCitaCC = array(
                    'secuencial' => $row['secuencial']
                );
            }
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($ms_sql);
            // $resultado->execute();
            // $res = $resultado->fetchAll();
            $secuencial = (int)$fCitaCC[0]['secuencial'] + 1;

            $ms_sql = "INSERT INTO SS_CC_CitaControl
            VALUES(
                $fCita->idcitaspring,
                $secuencial,
                '" . date('Y-m-d H:i:s') ."',
                NULL,
                'BFERREYROS',
                5,
                2,
                1,
                NULL,
                2,
                'BFERREYROS',
                '" . date('Y-m-d H:i:s') ."',
                'BFERREYROS',
                '" . date('Y-m-d H:i:s') ."'

            )";
            $resultado = sqlsrv_query($conn, $ms_sql);
            sqlsrv_free_stmt($resultado);
            // $resultado = $this->app->dblib->prepare($ms_sql);
            // $resultado->execute();
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
                    AND esp.IndicadorWeb = 2 
                ORDER BY esp.Descripcion ASC
            ";
            $settings = $this->app->get('settings')['sqlsrv'];
            $conn = sqlsrv_connect($settings['host'], array(
                'Database' => $settings['dbname'],
                'Uid' => $settings['user'],
                'PWD' => $settings['pass']
            ));
            if( $conn === false ){
                throw new Exception(sqlsrv_errors()[0]['message']);
            }
            $resultado = sqlsrv_query($conn, $sql);
            // if ($resultado == FALSE){
            //     $message = "No se encontraron especialidades";
            //     $flag = 0;
            // }else{
            $message = "Se encontraron especialidades"; 
            $flag = 1;

            $data = array();
            while ($row = sqlsrv_fetch_array($resultado, SQLSRV_FETCH_ASSOC)) {
                array_push($data,
                    array(
                        'idespecialidad'    => $row['IdEspecialidad'],
                        'codigo'            => $row['Codigo'], 
                        'descripcion'       => $row['Descripcion'], /* iconv("windows-1252", "utf-8", $row['Descripcion']), */
                        'adicionales'       => $row['CantidadCitasAdicional'],
                    )
                );
            }
            sqlsrv_free_stmt($resultado); 
            // sqlsrv_close($conn);
            if (empty($data)){
                $message = "No se encontraron especialidades";
                $flag = 0;
                return $response->withStatus(400)->withJson([
                    'flag' => 0,
                    'message' => $message
                ]);
            }
            return $response->withJson([
                'datos' => $data,
                'flag' => $flag,
                'message' => $message
            ]);
        } catch (\Exception $th) {
            // var_dump($th->getMessage(), 'errorrr');
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                // 'message' => 'Ocurrió un error al cargar las especialidades. Inténtelo nuevamente.'
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

            $settings = $this->app->get('settings')['sqlsrv'];
            $conn = sqlsrv_connect($settings['host'], array(
                'Database' => $settings['dbname'],
                'Uid' => $settings['user'],
                'PWD' => $settings['pass']
            ));
            if( $conn === false ){
                throw new Exception(sqlsrv_errors()[0]['message']);
            }
            $resultado = sqlsrv_query($conn, $sql);
            // if ($resultado == FALSE){
            //     $message = "No se encontraron médicos para la busqueda; intente seleccionando otras fechas.";
            //     $flag = 0;
            // }else{
            $message = "Se encontraron médicos para la búsqueda"; 
            $flag = 1;
            // }
            $data = array();
            while ($row = sqlsrv_fetch_array($resultado, SQLSRV_FETCH_ASSOC)) {
                array_push($data,
                    array(
                        'idmedico'          => $row['Medico'],
                        // 'descripcion'       => iconv("windows-1252", "utf-8", $row['NombreCompleto']),
                        'descripcion'       => $row['NombreCompleto'],
                        'idespecialidad'    => $row['IdEspecialidad']
                    )
                );
            }
            sqlsrv_free_stmt($resultado);
            // sqlsrv_close($conn);
            if (empty($data)) {
                $message = "No se encontraron médicos para la busqueda; intente seleccionando otras fechas.";
                $flag = 0;
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
                // 'message' => 'Ocurrió un error al cargar los medicos. Inténtelo nuevamente.'
                'message' => $th->getMessage()
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
            $configPayme = $this->app->get('settings')['payme'];
            $user = $request->getAttribute('decoded_token_data');
            $idusuario = $user->idusuario;
            $sql = "
                SELECT c.*
                FROM(
                    SELECT
                        LPAD(cit.idcita,6,0) AS identificador, 
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
                        cl.correo,
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
                        LPAD(cit.idcita,6,0) AS identificador,
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
                        fam.correo,
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
            $arrData = array();
            foreach ($data as $key => $row) {
                $arrData[$key] = $row;
                $monto = 10; // cambiar aqui precio
                $montoPorCien = $monto * 100;
                // $arrData[$key]['purchaseVerification'] = openssl_digest(
                //     (
                //         $configPayme['acquirerId']. 
                //         $configPayme['idCommerce']. 
                //         $row['identificador']. 
                //         $montoPorCien. 
                //         $configPayme['purchaseCurrencyCode']. 
                //         $configPayme['keyPasarela']
                //     ),
                //     'sha512'
                // );
                $arrData[$key]['mes'] = $this->convertir_mes($arrData[$key]['mes']);
                $arrData[$key]['acquirerId'] = $configPayme['acquirerId'];
                $arrData[$key]['idCommerce'] = $configPayme['idCommerce'];
                $arrData[$key]['purchaseOperationNumber'] = $row['identificador'];
                $arrData[$key]['purchaseAmount'] = $montoPorCien;
                $arrData[$key]['purchaseCurrencyCode'] = $configPayme['purchaseCurrencyCode'];
                $arrData[$key]['language'] = $configPayme['language'];
                $arrData[$key]['shippingFirstName'] = $row['nombres'];
                $arrData[$key]['shippingLastName'] = $row['apellido_paterno'].' '.$row['apellido_materno'];
                $arrData[$key]['shippingEmail'] = $row['correo'];
                $arrData[$key]['shippingAddress'] = 'NO ESPECIFICADO';
                $arrData[$key]['shippingZIP'] = $configPayme['shippingZIP'];
                $arrData[$key]['userCommerce'] = $row['idcliente'];
                $arrData[$key]['descriptionProducts'] = 'CONSULTA DE '.strtoupper($row['especialidad']);
                $arrData[$key]['programmingLanguage'] = $configPayme['programmingLanguage'];
                // $arrData[$key]['language'] = $configPayme['language'];
                // $arrData[$key]['language'] = $configPayme['language'];
            }
            return $response->withJson([
                'datos' => $arrData,
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

            $sql = "SELECT 
                    SS_CC_Horario.IdHorario,
                    SS_CC_Horario.FechaInicio,  
                    SS_CC_Horario.FechaFin,  
                    SS_CC_Horario.TipoRegistroHorario,  
                    SS_CC_Horario.IndicadorLunes,  
                    SS_CC_Horario.IndicadorMartes,  
                    SS_CC_Horario.IndicadorMiercoles,  
                    SS_CC_Horario.IndicadorJueves,  
                    SS_CC_Horario.IndicadorViernes,  
                    SS_CC_Horario.IndicadorSabado,  
                    SS_CC_Horario.IndicadorDomingo
                 FROM SS_CC_Horario WITH(NOLOCK)  
                 WHERE SS_CC_Horario.Medico = " . $idmedico . " 
                 AND SS_CC_Horario.Periodo = " . $periodo . " 
                 AND SS_CC_Horario.IdServicio = 1 
                 AND SS_CC_Horario.IdEspecialidad = " . $idespecialidad . " 
                 AND SS_CC_Horario.Estado = 2";

            $settings = $this->app->get('settings')['sqlsrv'];
            $conn = sqlsrv_connect($settings['host'], array(
                'Database' => $settings['dbname'],
                'Uid' => $settings['user'],
                'PWD' => $settings['pass']
            ));
            if( $conn === false ){
                throw new Exception(sqlsrv_errors()[0]['message']);
            }
            $resultado = sqlsrv_query($conn, $sql);
            if ($resultado == FALSE){
                $message = "No tiene fechas programadas";
                $flag = 0;
            }else{
                $message = "Se encontraron fechas programadas"; 
                $flag = 1;
            }
            $lista = array();
            while ($row = sqlsrv_fetch_array($resultado, SQLSRV_FETCH_ASSOC)) {
                array_push($lista, $row);
            }
            sqlsrv_free_stmt($resultado); 
            // sqlsrv_close($conn);
            // preparar data de fechas programadas
            $arrListaPreparada = array();
            foreach ($lista as $key => $row) {
                // $desdeAux = date("d-m-Y", strtotime($row['FechaInicio']));
                $desdeAux = $row['FechaInicio']->format('d-m-Y');
                $hastaAux = $row['FechaFin']->format('d-m-Y');
                // $hastaAux = date("d-m-Y", strtotime($row['FechaFin']));
                $arrRangoAux = $this->get_rango_fechas($desdeAux,$hastaAux,true);
                $lista[$key]['fechas'] = $arrRangoAux;
            }
            foreach ($lista as $key => $row) {
                foreach ($row['fechas'] as $key => $value) {
                    $diaSemana = date("w", strtotime($value));
                    if($row['IndicadorLunes'] == 2 && $diaSemana == 1){
                        array_push($arrListaPreparada, array(
                            'IdHorario'=> $row['IdHorario'],
                            'fecha'=> $value,
                            'indicador'=> NULL
                        ));
                    }
                    if($row['IndicadorMartes'] == 2 && $diaSemana == 2){
                        array_push($arrListaPreparada, array(
                            'IdHorario'=> $row['IdHorario'],
                            'fecha'=> $value,
                            'indicador'=> NULL
                        ));
                    }
                    if($row['IndicadorMiercoles'] == 2 && $diaSemana == 3){
                        array_push($arrListaPreparada, array(
                            'IdHorario'=> $row['IdHorario'],
                            'fecha'=> $value,
                            'indicador'=> NULL
                        ));
                    }
                    if($row['IndicadorJueves'] == 2 && $diaSemana == 4){
                        array_push($arrListaPreparada, array(
                            'IdHorario'=> $row['IdHorario'],
                            'fecha'=> $value,
                            'indicador'=> NULL
                        ));
                    }
                    if($row['IndicadorViernes'] == 2 && $diaSemana == 5){
                        array_push($arrListaPreparada, array(
                            'IdHorario'=> $row['IdHorario'],
                            'fecha'=> $value,
                            'indicador'=> NULL
                        ));
                    }
                    if($row['IndicadorSabado'] == 2 && $diaSemana == 6){
                        array_push($arrListaPreparada, array(
                            'IdHorario'=> $row['IdHorario'],
                            'fecha'=> $value,
                            'indicador'=> NULL
                        ));
                    }
                }
            }
            // var_dump('<pre>',$arrListaPreparada);
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
            // print_r($dataFinal); exit();
            // generar fechas validas
            foreach ($dataFinal as $key => $row) {
                $fechaStr = $row['fecha'];
                $fechaMasDosDias = date('d-m-Y', strtotime('+2 days'));
                $fechaMasDosDiasTS = strtotime($fechaMasDosDias);
                $fechaStrTS = strtotime($fechaStr);
                foreach ($arrListaPreparada as $rowLista) {
                    // $str
                    if( $fechaStr == $rowLista['fecha'] && $fechaStrTS >= $fechaMasDosDiasTS ){
                        // var_dump($fechaStr, $fechaMasDosDias);
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
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                // 'message' => 'Ocurrió un error al cargar las fechas programadas. Inténtelo nuevamente.'
                'message' => $th->getMessage()
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
            $idhorario       = $request->getParam('idhorario');
            $sql = "SELECT
                    hor.IdHorario,
                    CAST(hor.HoraInicio AS TIME) AS InicioHorario,
                    CAST(hor.HoraFin AS TIME) AS FinHorario,
                    hor.TiempoPromedioAtencion AS Intervalo,
                    ci.IdCita,
                    ci.FechaCita,
                    CAST(FechaInicio AS DATE) AS FechaInicio,
                    CAST(FechaFin AS DATE) AS FechaFin,
                    IndicadorLunes,
                    IndicadorMartes,
                    IndicadorMiercoles,
                    IndicadorJueves,
                    IndicadorViernes,
                    IndicadorSabado
                FROM SS_CC_Horario hor
                LEFT JOIN SS_CC_Cita ci ON hor.IdHorario = ci.IdHorario AND ci.Estado IN (2)
                WHERE hor.FechaInicio <= '" . date('Y-m-d',strtotime($fecha)). "' AND hor.FechaFin >= '" . date('Y-m-d',strtotime($fecha)). "'
                AND hor.Medico = " . $idmedico . "
                AND hor.Estado = 2
                AND hor.IdEspecialidad = " . $idespecialidad . "
                AND hor.IdTurno IN (1,2)
                ORDER BY IdTurno ASC
            ";

            $settings = $this->app->get('settings')['sqlsrv'];
            $conn = sqlsrv_connect($settings['host'], array(
                'Database' => $settings['dbname'],
                'Uid' => $settings['user'],
                'PWD' => $settings['pass']
            ));
            if( $conn === false ){
                throw new Exception(sqlsrv_errors()[0]['message']);
            }
            $resultado = sqlsrv_query($conn, $sql);
            if ($resultado == FALSE){
                $message = "No se encontraron cupos disponibles en esta fecha rr.";
                $flag = 0;
            }else{
                $message = "Se encontraron turnos programados"; 
                $flag = 1;
            }
            $lista = array();
            while ($row = sqlsrv_fetch_array($resultado, SQLSRV_FETCH_ASSOC)) {
                // print_r($row);
                array_push($lista, $row);
            }

            // exit();
            sqlsrv_free_stmt($resultado); 
            // sqlsrv_close($conn);

            // $resultado = $this->app->dblib->prepare($sql);
            // $resultado->execute();
            $arrCitas = array();

            if (!empty($lista)) {
                // $message = "Se encontraron turnos programados";
                // $flag = 1;
                // print_r($lista);
                /* AGREGAR HORARIOS RESTANTES AL ARRAY */
                $arrListaTotal = array();
                foreach($lista as $key => $row) {
                    // $desdeAux = date("d-m-Y", strtotime($row['FechaInicio']));
                    // $hastaAux = date("d-m-Y", strtotime($row['FechaFin']));
                    $desdeAux = $row['FechaInicio']->format('d-m-Y');
                    $hastaAux = $row['FechaFin']->format('d-m-Y');
                    // print_r($row['FechaInicio']);
                    // print_r($row['InicioHorario']);
                    $arrRangoAux = $this->get_rango_fechas($desdeAux,$hastaAux,true);
                    $arrDiasValidos = array();
                    foreach ($arrRangoAux as $key => $value) {
                        $diaSemana = date("w", strtotime($value));
                        if($row['IndicadorLunes'] == 2 && $diaSemana == 1){
                            array_push($arrListaTotal, array(
                                'IdHorario' => $row['IdHorario'],
                                'InicioHorario' => $row['InicioHorario'],
                                'FinHorario' => $row['FinHorario'],
                                'Intervalo' => $row['Intervalo'],
                                'IdCita' => $row['IdCita'],
                                'FechaCita' => $row['FechaCita'],
                                'FechaInicio' => $row['FechaInicio'],
                                'FechaFin' => $row['FechaFin'],
                                'IdHorario' => $row['IdHorario'],
                                'FechaActual' => $value
                            ));
                        }
                        if($row['IndicadorMartes'] == 2 && $diaSemana == 2){
                            array_push($arrListaTotal, array(
                                'IdHorario' => $row['IdHorario'],
                                'InicioHorario' => $row['InicioHorario'],
                                'FinHorario' => $row['FinHorario'],
                                'Intervalo' => $row['Intervalo'],
                                'IdCita' => $row['IdCita'],
                                'FechaCita' => $row['FechaCita'],
                                'FechaInicio' => $row['FechaInicio'],
                                'FechaFin' => $row['FechaFin'],
                                'IdHorario' => $row['IdHorario'],
                                'FechaActual' => $value
                            ));
                        }
                        if($row['IndicadorMiercoles'] == 2 && $diaSemana == 3){
                            array_push($arrListaTotal, array(
                                'IdHorario' => $row['IdHorario'],
                                'InicioHorario' => $row['InicioHorario'],
                                'FinHorario' => $row['FinHorario'],
                                'Intervalo' => $row['Intervalo'],
                                'IdCita' => $row['IdCita'],
                                'FechaCita' => $row['FechaCita'], 
                                'FechaInicio' => $row['FechaInicio'],
                                'FechaFin' => $row['FechaFin'],
                                'IdHorario' => $row['IdHorario'],
                                'FechaActual' => $value
                            ));
                        } 
                        if($row['IndicadorJueves'] == 2 && $diaSemana == 4){
                            array_push($arrListaTotal, array(
                                'IdHorario' => $row['IdHorario'],
                                'InicioHorario' => $row['InicioHorario'],
                                'FinHorario' => $row['FinHorario'],
                                'Intervalo' => $row['Intervalo'],
                                'IdCita' => $row['IdCita'],
                                'FechaCita' => $row['FechaCita'],
                                'FechaInicio' => $row['FechaInicio'],
                                'FechaFin' => $row['FechaFin'],
                                'IdHorario' => $row['IdHorario'],
                                'FechaActual' => $value
                            ));
                        }
                        if($row['IndicadorViernes'] == 2 && $diaSemana == 5){
                            array_push($arrListaTotal, array(
                                'IdHorario' => $row['IdHorario'],
                                'InicioHorario' => $row['InicioHorario'],
                                'FinHorario' => $row['FinHorario'],
                                'Intervalo' => $row['Intervalo'],
                                'IdCita' => $row['IdCita'],
                                'FechaCita' => $row['FechaCita'],
                                'FechaInicio' => $row['FechaInicio'],
                                'FechaFin' => $row['FechaFin'],
                                'IdHorario' => $row['IdHorario'],
                                'FechaActual' => $value
                            ));
                        }
                        if($row['IndicadorSabado'] == 2 && $diaSemana == 6){
                            array_push($arrListaTotal, array(
                                'IdHorario' => $row['IdHorario'],
                                'InicioHorario' => $row['InicioHorario'],
                                'FinHorario' => $row['FinHorario'],
                                'Intervalo' => $row['Intervalo'],
                                'IdCita' => $row['IdCita'],
                                'FechaCita' => $row['FechaCita'],
                                'FechaInicio' => $row['FechaInicio'],
                                'FechaFin' => $row['FechaFin'],
                                'IdHorario' => $row['IdHorario'],
                                'FechaActual' => $value
                            ));
                        }
                        // array_push($arrListaTotal, array(
                        //     'IdHorario' => $row['IdHorario'],
                        //     'InicioHorario' => $row['InicioHorario'],
                        //     'FinHorario' => $row['FinHorario'],
                        //     'Intervalo' => $row['Intervalo'],
                        //     'IdCita' => $row['IdCita'],
                        //     'FechaCita' => $row['FechaCita'],
                        //     'FechaInicio' => $row['FechaInicio'],
                        //     'FechaFin' => $row['FechaFin'],
                        //     'IdHorario' => $row['IdHorario'],
                        //     'FechaActual' => $value
                        // ));
                    }
                    // var_dump($arrListaTotal); exit();
                }
                $arrListado = array();
                foreach ($arrListaTotal as $key => $row) {
                    // var_dump($row['FechaActual']);
                    if( $row['FechaActual'] === $fecha ){ // d-m-Y
                        $arrListado[$row['IdHorario']] = array(
                            'IdHorario' => $row['IdHorario'],
                            'InicioHorario' => $row['InicioHorario'],
                            'FinHorario' => $row['FinHorario'],
                            'Intervalo' => $row['Intervalo'],
                            'FechaActual' => $row['FechaActual'],
                            'turnos_ocupados' => array()
                        );
                    }
                }
                // print_r($arrListaTotal); 
                foreach ($arrListado as $key => $value) {
                    $arrAux = array();
                    foreach ($arrListaTotal as $row) {
                        if( $row['IdHorario'] == $key && !empty($row['FechaCita']) ){
                            // $arrAux[] = date('Y-m-d H:i',strtotime($row['FechaCita']));
                            $arrAux[] = $row['FechaCita']->format('Y-m-d H:i');
                        }
                    }
                    if(!empty($arrAux)){
                        $arrListado[$key]['turnos_ocupados'] = $arrAux;
                    }
                }
                
                $arrListado = array_values($arrListado);
            }else{
                // $message = "No se encontraron cupos disponibles en esta fecha.";
                // $flag = 0;
                return $response->withStatus(400)->withJson([
                    'datos' => $arrCitas,
                    'flag' => $flag,
                    'message' => $message
                ]);
            }
            $data = array();
            // print_r($arrListado);
            foreach ($arrListado as $row) {
                $rowFechaActual = $row['FechaActual'];
                // $hora_inicio = date('H:i',strtotime($row['InicioHorario']));
                $hora_inicio = $row['InicioHorario']->format('H:i');
                // $hora_fin = $row['FinHorario']->format('H:i');
                $i = 1;
                while ( strtotime($row['FinHorario']->format('Y-m-d H:i:s')) > strtotime($hora_inicio) ) {
                    $hora_fin = date('H:i',(strtotime($hora_inicio) + $row['Intervalo']*60) );
                    $fecha_inicio = date('Y-m-d',strtotime($rowFechaActual)) . ' ' . $hora_inicio;
                    // print_r($fecha_inicio);
                    // print_r($row['turnos_ocupados']);
                    if( !in_array($fecha_inicio, $row['turnos_ocupados']) ){

                        array_push($arrCitas,
                        array(
                            'fecha_actual'=> $row['FechaActual'],
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
            if( empty($arrCitas) ){
                $message = "No se encontraron cupos disponibles en esta fecha.";
                $flag = 0;
                return $response->withStatus(400)->withJson([
                    'datos' => $arrCitas,
                    'flag' => $flag,
                    'message' => $message
                ]);
            }
            return $response->withJson([
                'datos' => $arrCitas,
                'flag' => $flag,
                'message' => $message
            ]);
        } catch (\Exception $th) {
            return $response->withStatus(400)->withJson([
                'flag' => 0,
                // 'message' => 'Ocurrió un error al cargar los horarios. Inténtelo nuevamente.'
                'message' => $th->getMessage()
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
                { $range[] = date('Y-m-d H:i:s'); }
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
        if ($mes == 'Jan' || $mes == 'JANUARY')
        $resultado = 'ENERO';
        if ($mes == 'Feb' || $mes == 'FEBRUARY')
        $resultado = 'FEBRERO';
        if ($mes == 'Mar' || $mes == 'MARCH')
        $resultado = 'MARZO';
        if ($mes == 'Apr' || $mes == 'APRIL')
        $resultado = 'ABRIL';
        if ($mes == 'May' || $mes == 'MAY')
        $resultado = 'MAYO';
        if ($mes == 'Jun' || $mes == 'JUNE')
        $resultado = 'JUNIO';
        if ($mes == 'Jul' || $mes == 'JULY')
        $resultado = 'JULIO';
        if ($mes == 'Aug' || $mes == 'AUGUST')
        $resultado = 'AGOSTO';
        if ($mes == 'Sep' || $mes == 'SEPTEMBER')
        $resultado = 'SEPTIEMBRE';
        if ($mes == 'Oct' || $mes == 'OCTOBER')
        $resultado = 'OCTUBRE';
        if ($mes == 'Nov' || $mes == 'NOVEMBER')
        $resultado = 'NOVIEMBRE';
        if ($mes == 'Dec' || $mes == 'DECEMBER')
        $resultado = 'DICIEMBRE';
        return @$resultado;
    }
    private function dbConnection()
    {
        $settings = $this->app->get('settings')['sqlsrv'];


        // $settings = $c->get('settings')['sqlsrv'];
        $conn = sqlsrv_connect($settings['host'], array(
            'Database' => $settings['dbname'],
            'Uid' => $settings['user'],
            'PWD' => $settings['pass']
        ));
        // return $conn;
        // $servername = $config['host'];
        // $connectionInfo = array("Database" => "dashboard_das", "UID" => "test", "PWD" => "test",'ReturnDatesAsStrings'=>true);
        // $GLOBALS['conn'] = sqlsrv_connect($servername, $connectionInfo);
        // if (!$GLOBALS['conn']) {
        //     echo "Error connecting to database.";
        //     die(print_r(sqlsrv_errors(), true));
        // }
    }
}