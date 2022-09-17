<?php
namespace App\Routes;
use Slim\Http\Request;
use Slim\Http\Response;
use \Slim\Middleware\JwtAuthentication;
use \Firebase\JWT\JWT;
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
        var_dump($fechaFiltro);
        $arrListado = array();
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
        $arrCitas = array();
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

        

        // $resultado->execute();

        // $arrListado = $resultado->fetchAll();

        return $response->withJson([
            'datos' => $arrCitas,
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
