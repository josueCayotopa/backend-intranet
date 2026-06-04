-- ============================================================================
-- SP_INTRANET_GET_VACACIONES  — LECTURA
-- Lee de PLA_VACACIONES_MES del ERP via nombre de 3 partes.
-- Retorna: datos del empleado + resumen del año + historial de solicitudes.
-- ============================================================================
USE INTRANETCLL;
GO

IF OBJECT_ID('dbo.SP_INTRANET_GET_VACACIONES','P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_VACACIONES;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_VACACIONES
    @db_name      VARCHAR(50),
    @cod_personal VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;

    IF @db_name LIKE '%[^A-Za-z0-9_]%'
    BEGIN RAISERROR('@db_name contiene caracteres no permitidos.',16,1); RETURN; END

    DECLARE @sql NVARCHAR(MAX);

    SET @sql = N'
        SELECT
            -- Datos del trabajador (se repiten en cada fila)
            P.COD_PERSONAL,
            P.APE_PATERNO + '' '' + P.APE_MATERNO + '', '' + P.NOM_TRABAJADOR  AS NOMBRE_COMPLETO,
            A.NUM_DOC_IDENTIDAD,
            C.DES_CARGO,
            MA.DES_AREAS,
            E.DES_NOMBRE_COMERCIAL   AS EMPRESA,
            P.FEC_INGRESO,

            -- Resumen del año actual
            CAST(YEAR(GETDATE()) AS VARCHAR(4))  AS ANO_ACTUAL,

            ISNULL((
                SELECT SUM(V2.NUM_DIAS)
                FROM   [' + @db_name + N'].dbo.PLA_VACACIONES_MES V2
                WHERE  V2.COD_EMPRESA        = P.COD_EMPRESA
                  AND  V2.COD_PERSONAL       = P.COD_PERSONAL
                  AND  V2.ESTADO_APROBACION  = ''AR''
                  AND  V2.ANO_PROCESO        = CAST(YEAR(GETDATE()) AS VARCHAR(4))
            ), 0)  AS DIAS_GOZADOS_ANIO,

            ISNULL((
                SELECT SUM(V3.NUM_DIAS)
                FROM   [' + @db_name + N'].dbo.PLA_VACACIONES_MES V3
                WHERE  V3.COD_EMPRESA        = P.COD_EMPRESA
                  AND  V3.COD_PERSONAL       = P.COD_PERSONAL
                  AND  V3.ESTADO_APROBACION  IN (''PE'', ''AJ'')
            ), 0)  AS DIAS_PENDIENTES,

            -- Una fila por solicitud (NULL si no hay registros)
            V.COD_CORR_VAC,
            V.FEC_INICIO,
            V.FEC_FINAL,
            V.NUM_DIAS,
            V.TIP_VACACIONES,
            V.ESTADO_APROBACION,
            V.ANO_PROCESO

        FROM       [' + @db_name + N'].dbo.PLA_PERSONAL       P
        INNER JOIN [' + @db_name + N'].dbo.MAE_AUXILIAR       A   ON  A.COD_AUXILIAR   = P.COD_AUXILIAR
        INNER JOIN [' + @db_name + N'].dbo.MAE_AREAS          MA  ON  MA.COD_AREAS     = P.COD_AREAS
                                                                   AND MA.NUM_VER_AREAS = P.NUM_VER_AREAS
        INNER JOIN [' + @db_name + N'].dbo.PLA_CARGOS         C   ON  C.COD_CARGO      = P.COD_CARGO
                                                                   AND C.COD_CATEGORIA  = P.COD_CATEGORIA
        INNER JOIN [' + @db_name + N'].dbo.MAE_EMPRESAS       E   ON  E.COD_EMPRESA    = P.COD_EMPRESA
        LEFT  JOIN [' + @db_name + N'].dbo.PLA_VACACIONES_MES V   ON  V.COD_EMPRESA    = P.COD_EMPRESA
                                                                   AND V.COD_PERSONAL   = P.COD_PERSONAL

        WHERE P.COD_PERSONAL      = @cod_personal
          AND P.TIP_ESTADO        = ''AC''
          AND P.COD_TIPO_PLANILLA = ''01''

        ORDER BY V.FEC_INICIO DESC
    ';

    EXEC sp_executesql
        @sql,
        N'@cod_personal VARCHAR(20)',
        @cod_personal = @cod_personal;
END;
GO
-- EXEC dbo.SP_INTRANET_GET_VACACIONES 'BDV0004', '000101';
