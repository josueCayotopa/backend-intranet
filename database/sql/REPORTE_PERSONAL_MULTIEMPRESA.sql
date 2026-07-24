-- ============================================================================
-- REPORTE_PERSONAL_MULTIEMPRESA.sql
-- BASE DE DATOS: la BD de la intranet (INTRANETCLL)
-- Objetivo: listar el personal que aparece en más de una empresa del
--           corporativo, usando dinámicamente las empresas activas
--           registradas en la tabla EMPRESAS de la intranet (no una lista
--           fija de BDs), incluyendo su fecha de cese (FEC_CESADO) cuando
--           ya no está activo en esa empresa.
--
-- USO: ejecutar en SSMS conectado a la BD de la intranet.
-- Requiere que el login usado tenga permisos de lectura sobre cada BD del
-- ERP listada en EMPRESAS.db_name (todas viven en la misma instancia).
-- ============================================================================

USE INTRANETCLL;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

SELECT @sql = @sql +
    CASE WHEN @sql = N'' THEN N'' ELSE N'
UNION ALL
' END +
    N'SELECT ''' + db_name + N''' AS DB_ERP, ''' + REPLACE(nombre, N'''', N'''''') + N''' AS EMPRESA,
       A.NUM_DOC_IDENTIDAD, P.COD_PERSONAL, P.FEC_INGRESO, P.FEC_CESADO, P.TIP_ESTADO
FROM   [' + db_name + N'].dbo.PLA_PERSONAL P
JOIN   [' + db_name + N'].dbo.MAE_AUXILIAR A ON A.COD_AUXILIAR = P.COD_AUXILIAR
WHERE  P.COD_TIPO_PLANILLA = ''01'''
FROM   dbo.EMPRESAS
WHERE  activo = 1
  AND  db_name IS NOT NULL AND db_name <> N'';

SET @sql = N';WITH TODAS_EMPRESAS AS (' + @sql + N')
SELECT NUM_DOC_IDENTIDAD,
       COUNT(DISTINCT DB_ERP)                                  AS NUM_EMPRESAS,
       STRING_AGG(EMPRESA + '' ('' + DB_ERP + ''): ingreso '' +
                  CONVERT(VARCHAR, FEC_INGRESO, 23) +
                  ISNULL('' / cese '' + CONVERT(VARCHAR, FEC_CESADO, 23), '''') +
                  '' ['' + TIP_ESTADO + '']'', '' | '')          AS DETALLE
FROM   TODAS_EMPRESAS
GROUP BY NUM_DOC_IDENTIDAD
HAVING COUNT(DISTINCT DB_ERP) > 1
ORDER BY NUM_DOC_IDENTIDAD;';

EXEC sp_executesql @sql;
