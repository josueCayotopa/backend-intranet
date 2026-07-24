-- ============================================================================
-- SP_INTRANET_GET_VACACIONES_GRUPO  — LECTURA
-- BASE DE DATOS:  Cada BD del ERP (BDV0004, IOLL, etc.)
-- PATRÓN:        B — el SP vive en la BD de la empresa; Laravel lo llama
--                como EXEC [db_name].dbo.SP_INTRANET_GET_VACACIONES_GRUPO
-- Tabla principal : PLA_PERSONAL (búsqueda por DNI, SIN filtrar TIP_ESTADO)
-- Objetivo: soporte para personal que trabajó en más de una empresa del
--           corporativo. A diferencia de SP_INTRANET_GET_VACACIONES:
--             1) Busca por @num_doc_identidad en vez de @cod_personal, para
--                poder ubicar al trabajador aunque su código cambie entre
--                empresas.
--             2) NO filtra P.TIP_ESTADO = 'AC' — así también se recupera el
--                registro de una empresa donde el trabajador ya cesó, para
--                poder sumar el bloque de vacaciones que generó ahí.
--             3) Devuelve FEC_CESADO para que el consolidado en Laravel
--                pueda topar el cálculo de "años completos" en esa empresa
--                a la fecha de cese (en vez de a la fecha de hoy).
-- ============================================================================
-- Ejecutar en cada BD del ERP donde existan empleados.
USE SELUCE;  -- cambiar por la BD destino
-- GO

IF OBJECT_ID('dbo.SP_INTRANET_GET_VACACIONES_GRUPO', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_VACACIONES_GRUPO;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_VACACIONES_GRUPO
    @num_doc_identidad VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        P.COD_PERSONAL,
        P.APE_PATERNO + ' ' + P.APE_MATERNO + ', ' + P.NOM_TRABAJADOR  AS NOMBRE_COMPLETO,
        A.NUM_DOC_IDENTIDAD,
        E.DES_NOMBRE_COMERCIAL   AS EMPRESA,
        P.TIP_ESTADO,
        P.FEC_INGRESO,
        P.FEC_CESADO,

        -- Total histórico de días gozados en ESTA empresa (todas las gestiones, no solo el año actual)
        ISNULL((
            SELECT SUM(C2.NUM_TOT_DIAS)
            FROM   dbo.PLA_VACACIONES_MES_CAB C2
            WHERE  C2.COD_EMPRESA  = P.COD_EMPRESA
              AND  C2.COD_PERSONAL = P.COD_PERSONAL
        ), 0)  AS DIAS_GOZADOS_TOTAL,

        -- Días pendientes (solicitudes intranet aún no procesadas en planilla)
        ISNULL((
            SELECT SUM(V3.NUM_DIAS)
            FROM   dbo.PLA_VACACIONES_MES V3
            WHERE  V3.COD_EMPRESA       = P.COD_EMPRESA
              AND  V3.COD_PERSONAL      = P.COD_PERSONAL
              AND  V3.ESTADO_APROBACION IN ('PE', 'AJ')
        ), 0)  AS DIAS_PENDIENTES

    FROM       dbo.PLA_PERSONAL   P
    INNER JOIN dbo.MAE_AUXILIAR   A   ON  A.COD_AUXILIAR = P.COD_AUXILIAR
    INNER JOIN dbo.MAE_EMPRESAS   E   ON  E.COD_EMPRESA  = P.COD_EMPRESA

    WHERE  A.NUM_DOC_IDENTIDAD  = @num_doc_identidad
      AND  P.COD_TIPO_PLANILLA  = '01';
END;
GO

-- TEST (ejecutar en la misma BD del ERP):
-- EXEC dbo.SP_INTRANET_GET_VACACIONES_GRUPO @num_doc_identidad = '73111252';
