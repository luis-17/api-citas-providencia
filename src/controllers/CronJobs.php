<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Respect\Validation\Validator as V;

class CronJobs
{
    public function __construct($app)
    {
        $this->app = $app;

    }

    public function envioCorreoPacientesNotifCita(Request $request, Response $response, array $args)
    {
      try {
        $settings = $this->app->get('settings')['sqlsrv'];
        $conn = sqlsrv_connect($settings['host'], array(
          'Database' => $settings['dbname'],
          'Uid' => $settings['user'],
          'PWD' => $settings['pass']
        ));
        if( $conn === false ){
          throw new Exception(sqlsrv_errors()[0]['message']);
        }
        // obtener fecha de filtro
        $fechaFiltro = date('Ymd',strtotime('+2 day'));
        // var_dump($fechaFiltro);
        $sqlListCitas = "
            SELECT
            CONVERT(varchar, dbo.ss_cc_cita.FechaCita, 105) AS fecha_cita,
            CONVERT(varchar, dbo.ss_cc_cita.FechaCita, 108) AS hora_cita,
            (
                SELECT CodigoEstado 
                FROM dbo.GE_EstadoDocumento
                WHERE (IdDocumento = 45) AND (IdEstado = dbo.SS_CC_Cita.EstadoDocumento)
            ) AS cita_estado,
            
            (
                SELECT Nombre 
                FROM dbo.SS_GE_Especialidad
                WHERE (IdEspecialidad = dbo.SS_CC_Horario.IdEspecialidad)
            ) AS Especialidad,
            pm.NombreCompleto AS paciente,
            pm.CorreoElectronico,
            (
                CASE 
                WHEN isnull(dbo.SS_CC_Cita.IdMedicoReemplazo, 0) = 0
                THEN (
                    SELECT nombrecompleto
                    FROM personamast
                    WHERE dbo.personamast.persona = dbo.SS_CC_Cita.IdMedico
                )
                ELSE
                (
                    SELECT nombrecompleto
                    FROM personamast
                    WHERE dbo.personamast.persona = dbo.SS_CC_Cita.IdMedicoReemplazo
                ) 
                END
            ) AS medico
            FROM dbo.SS_CC_Cita
            INNER JOIN personamast pm on dbo.ss_cc_cita.idpaciente = pm.persona
            LEFT OUTER JOIN dbo.SS_CC_Horario ON dbo.SS_CC_Cita.IdHorario = dbo.SS_CC_Horario.IdHorario
            LEFT OUTER JOIN dbo.SS_AD_OrdenAtencionDetalle ON dbo.SS_CC_Cita.IdCita = dbo.SS_AD_OrdenAtencionDetalle.IdCita 
            LEFT OUTER JOIN dbo.SS_AD_OrdenAtencion ON dbo.SS_AD_OrdenAtencionDetalle.IdOrdenAtencion = dbo.SS_AD_OrdenAtencion.IdOrdenAtencion 
            LEFT OUTER JOIN dbo.CM_CO_TablaMaestroDetalle ON dbo.CM_CO_TablaMaestroDetalle.IdTablaMaestro = 101 
                AND dbo.SS_AD_OrdenAtencionDetalle.TipoOrdenAtencion = dbo.CM_CO_TablaMaestroDetalle.IdCodigo
            LEFT OUTER JOIN dbo.SS_GE_Turno ON dbo.SS_GE_Turno.IdTurno = dbo.SS_CC_Horario.IdTurno
            LEFT JOIN SS_GE_CONSULTORIO WITH(NOLOCK) ON SS_CC_HORARIO.IDCONSULTORIO = SS_GE_CONSULTORIO.IDCONSULTORIO
            WHERE CONVERT( varchar, dbo.SS_CC_Cita.FechaCita, 112) = '".$fechaFiltro."'
            AND pm.CorreoElectronico IS NOT NULL
            AND pm.CorreoElectronico <> '' 
            AND dbo.ss_cc_cita.estadodocumento IN (2) -- programado            
        ";
        $resultado = sqlsrv_query($conn, $sqlListCitas);
        // $arrCitas = array(
        //   array('fecha_cita'=> '19-09-2022', 'hora_cita'=> '18:15:00', 'Especialidad'=> 'TERAPIA FISICA', 'paciente'=> 'CORALES SILVA , VICTOR DENIS', 'CorreoElectronico'=> 'luisls1717@gmail.com', 'medico'=> 'ALEX QUISPE'),
        //   array('fecha_cita'=> '19-09-2022', 'hora_cita'=> '18:15:00', 'Especialidad'=> 'TERAPIA FISICA', 'paciente'=> 'CORALES SILVA , VICTOR DENIS', 'CorreoElectronico'=> 'stefanyguissela@gmail.com', 'medico'=> 'ALEX QUISPE')
        // );
        while( $row = sqlsrv_fetch_array( $resultado, SQLSRV_FETCH_ASSOC) ) {
            array_push($arrCitas, $row);
        }
        sqlsrv_free_stmt($resultado);
        if ( empty($arrCitas) ) {
          return $response->withStatus(400)->withJson([
              'error' => true, 
              'message' => 'No hay citas para notificar por correo el dia de hoy: '.date('Y-m-d')
          ]);
        }
        $fromAlias = 'Clínica Providencia';
        $asunto = 'Recordatorio: Tiene una cita próxima - Clínica Providencia';

        $mail = new PHPMailer();
        $mail->IsSMTP(true);
        $mail->SMTPAuth = true;
        $mail->SMTPDebug = true;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->Username =  SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SetFrom(SMTP_USERNAME,$fromAlias);
        $mail->AddReplyTo(SMTP_USERNAME,$fromAlias);
        $mail->Subject = $asunto;
        $mail->IsHTML(true);
        foreach ($arrCitas as $key => $row) {
          $mensaje = '<html lang="es">';
          $mensaje .= '<body style="font-family: sans-serif;" >';
            $mensaje .= '<div style="align-content: center;">';
            $mensaje .= '<div class="header-page" style="background-color:#00386c;padding: 0.75rem;">';
              $mensaje .= '<img style="width: 175px;" src="http://104.131.176.122/mailing-providencia/logo_alt.png" />';
            $mensaje .= '</div>';
            $mensaje .= '<div class="content-page" style="background-color:#e9f1f5;padding:1.5rem 3rem;">';
              $mensaje .= '<div style="font-size:16px;max-width: 600px;display: inline-block;">';
              $mensaje .= '<h2 style="margin: 0;color: #739525;margin-bottom: 1.75rem;">Paciente <strong style="color:#00386c;">'.strtoupper($row['paciente']).',</strong> </h2>';
              $mensaje .= '<div style="font-size:16px;color: #777777;"> Le notificamos que tiene una cita próxima agendada con la siguiente información: <br /> <br /> ';
                $mensaje .= '<table style="width:100%;color: #777777;">';
                $mensaje .= '<tr><td><b>FECHA DE CITA:</b></td><td> '.$row['fecha_cita'].' </td></tr>';
                $mensaje .= '<tr><td><b>HORA:</b></td><td> '.$row['hora_cita'].' </td></tr>';
                $mensaje .= '<tr><td><b>ESPECIALIDAD:</b></td><td> '.$row['Especialidad'].' </td></tr>';
                $mensaje .= '<tr><td><b>MÉDICO:</b></td><td> '.$row['medico'].' </td></tr>';
                $mensaje .= '</table>';
                $mensaje .= '<p>Recuerde llegar 30 minutos antes para realizar su trámite administrativo en Admisión Ambulatoria, ingresando por la puerta principal de la clínica: Calle Carlos Gonzáles 250 San Miguel.</p>';
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

          $mail->AltBody = $mensaje;
          $mail->MsgHTML($mensaje);
          $mail->CharSet = 'UTF-8';
          $mail->AddAddress($row['CorreoElectronico']);

          $estadoAudit = 'ERR';
          if ($mail->Send()) {
            $estadoAudit = 'ENV';
          }
          $arrAuditMail = array(
            'codigoAuditoria'=> 'A001',
            'fechaCitaAudit'=> date('Y-m-d', strtotime($row['fecha_cita'])).' '.$row['hora_cita'],
            'fechaRegAudit'=> date('Y-m-d H:i:s'),
            'pacienteAudit'=> strtoupper($row['paciente']),
            'estadoAudit'=> $estadoAudit,
            'correoElectronico'=> $row['CorreoElectronico']
          );
          
          // registro en tabla de auditoria
          $sqlAudit = "INSERT INTO auditoria (
              codigo_auditoria,
              paciente,
              estado,
              fechaCita,
              fechaRegistro,
              correoEnvio
          ) VALUES (
              :codigo_auditoria,
              :paciente,
              :estado,
              :fechaCita,
              :fechaRegistro,
              :correoEnvio
          )";
          $resultInsAudit = $this->app->db->prepare($sqlAudit);
          $resultInsAudit->bindParam(':codigo_auditoria', $arrAuditMail['codigoAuditoria']); // 19-09-2022 18:15:00
          $resultInsAudit->bindParam(':paciente', $arrAuditMail['pacienteAudit']);
          $resultInsAudit->bindParam(':estado', $arrAuditMail['estadoAudit']);
          $resultInsAudit->bindParam(':fechaCita', $arrAuditMail['fechaCitaAudit']);
          $resultInsAudit->bindParam(':fechaRegistro', $arrAuditMail['fechaRegAudit']);
          $resultInsAudit->bindParam(':correoEnvio', $arrAuditMail['correoElectronico']);
          $resultInsAudit->execute();
        }

        return $response->withJson([
            'count' => count($arrCitas),
            'flag' => 1,
            'message' => "Se ejecutó el lote de correos"
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
