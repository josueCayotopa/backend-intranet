-- ============================================================================
-- SP_INTRANET_GET_VACACIONES  — LECTURA
-- BASE DE DATOS:  Cada BD del ERP (BDV0004, BDV0004_PRUEBA, etc.)
-- PATRÓN:        B — el SP vive en la BD de la empresa; Laravel lo llama
--                como EXEC [db_name].dbo.SP_INTRANET_GET_VACACIONES
-- Tabla principal : PLA_VACACIONES_MES_CAB  (sesiones procesadas en planilla)
-- Tabla detalle   : PLA_VACACIONES_MES      (para COD_PERIODO y TIP_VACACIONES)
-- Retorna: datos del empleado + resumen anual + historial de vacaciones.
-- ============================================================================
-- Ejecutar en cada BD del ERP donde existan empleados.
-- USE BDV0004;  -- cambiar por la BD destino
-- GO

IF OBJECT_ID('dbo.SP_INTRANET_GET_VACACIONES', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_VACACIONES;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_VACACIONES
    @cod_personal VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        -- Datos del trabajador (se repiten en cada fila del historial)
        P.COD_PERSONAL,
        P.APE_PATERNO + ' ' + P.APE_MATERNO + ', ' + P.NOM_TRABAJADOR  AS NOMBRE_COMPLETO,
        A.NUM_DOC_IDENTIDAD,
        C.DES_CARGO,
        MA.DES_AREAS,
        E.DES_NOMBRE_COMERCIAL   AS EMPRESA,
        P.FEC_INGRESO,

        -- Año actual
        CAST(YEAR(GETDATE()) AS VARCHAR(4))  AS ANO_ACTUAL,

        -- Días gozados en el año actual (procesados en planilla)
        ISNULL((
            SELECT SUM(C2.NUM_TOT_DIAS)
            FROM   dbo.PLA_VACACIONES_MES_CAB C2
            WHERE  C2.COD_EMPRESA  = P.COD_EMPRESA
              AND  C2.COD_PERSONAL = P.COD_PERSONAL
              AND  C2.ANO_PROCESO  = CAST(YEAR(GETDATE()) AS VARCHAR(4))
        ), 0)  AS DIAS_GOZADOS_ANIO,

        -- Días pendientes (solicitudes aún no procesadas en planilla)
        ISNULL((
            SELECT SUM(V3.NUM_DIAS)
            FROM   dbo.PLA_VACACIONES_MES V3
            WHERE  V3.COD_EMPRESA       = P.COD_EMPRESA
              AND  V3.COD_PERSONAL      = P.COD_PERSONAL
              AND  V3.ESTADO_APROBACION IN ('PE', 'AJ')
        ), 0)  AS DIAS_PENDIENTES,

        -- Una fila por sesión de vacaciones procesada en planilla
        CAB.COD_CORR_VAC,
        CAB.ANO_PROCESO,
        CAB.MES_PROCESO,
        CAB.FEC_INICIO,
        CAB.FEC_FIN,
        CAB.NUM_TOT_DIAS,
        CAB.IMP_TOT_VACACION,
        CAB.IND_TRANSF_PLAN,

        -- Período y tipo desde la tabla detalle
        ISNULL(D.COD_PERIODO,    '')    AS COD_PERIODO,
        ISNULL(D.TIP_VACACIONES, 'VN')  AS TIP_VACACIONES

    FROM       dbo.PLA_PERSONAL       P
    INNER JOIN dbo.MAE_AUXILIAR       A   ON  A.COD_AUXILIAR   = P.COD_AUXILIAR
    INNER JOIN dbo.MAE_AREAS          MA  ON  MA.COD_AREAS     = P.COD_AREAS
                                          AND MA.NUM_VER_AREAS = P.NUM_VER_AREAS
    INNER JOIN dbo.PLA_CARGOS         C   ON  C.COD_CARGO      = P.COD_CARGO
                                          AND C.COD_CATEGORIA  = P.COD_CATEGORIA
    INNER JOIN dbo.MAE_EMPRESAS       E   ON  E.COD_EMPRESA    = P.COD_EMPRESA

    LEFT JOIN  dbo.PLA_VACACIONES_MES_CAB  CAB
           ON  CAB.COD_EMPRESA  = P.COD_EMPRESA
          AND  CAB.COD_PERSONAL = P.COD_PERSONAL

    LEFT JOIN (
        SELECT COD_EMPRESA, COD_PERSONAL, ANO_PROCESO, MES_PROCESO, COD_CORR_VAC,
               MIN(COD_PERIODO)    AS COD_PERIODO,
               MIN(TIP_VACACIONES) AS TIP_VACACIONES
        FROM   dbo.PLA_VACACIONES_MES
        GROUP BY COD_EMPRESA, COD_PERSONAL, ANO_PROCESO, MES_PROCESO, COD_CORR_VAC
    ) D ON  D.COD_EMPRESA  = CAB.COD_EMPRESA
        AND D.COD_PERSONAL = CAB.COD_PERSONAL
        AND D.ANO_PROCESO  = CAB.ANO_PROCESO
        AND D.MES_PROCESO  = CAB.MES_PROCESO
        AND D.COD_CORR_VAC = CAB.COD_CORR_VAC

    WHERE P.COD_PERSONAL      = @cod_personal
      AND P.TIP_ESTADO        = 'AC'
      AND P.COD_TIPO_PLANILLA = '01'

    ORDER BY CAB.ANO_PROCESO DESC, CAB.MES_PROCESO DESC, CAB.COD_CORR_VAC DESC;
END;
GO

-- TEST (ejecutar en la misma BD del ERP):
-- EXEC dbo.SP_INTRANET_GET_VACACIONES @cod_personal = '000101';
