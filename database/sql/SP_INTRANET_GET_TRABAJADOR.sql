-- ============================================================================
-- STORED PROCEDURE: SP_INTRANET_GET_TRABAJADOR
-- BASE DE DATOS:    INTRANETCLL  (aquí viven todos los SPs de la intranet)
-- DESCRIPCIÓN:      Retorna datos completos del trabajador activo.
--                   @db_name determina qué empresa/BD del ERP se consulta.
-- USO DESDE LARAVEL:
--   DB::select('EXEC SP_INTRANET_GET_TRABAJADOR @db_name=?, @cod_personal=?',
--              [$dbName, $codPersonal]);
-- ============================================================================

USE INTRANETCLL;
GO

IF OBJECT_ID('dbo.SP_INTRANET_GET_TRABAJADOR', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_TRABAJADOR;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_TRABAJADOR
    @db_name      VARCHAR(50),   -- Nombre de la BD del ERP (ej: BDV0004)
    @cod_personal VARCHAR(20)    -- Código del trabajador   (ej: 000101)
AS
BEGIN
    SET NOCOUNT ON;

    -- ── Validación básica de @db_name para prevenir inyección SQL ────────
    IF @db_name IS NULL OR LEN(LTRIM(RTRIM(@db_name))) = 0
    BEGIN
        RAISERROR('El parámetro @db_name es requerido.', 16, 1);
        RETURN;
    END

    IF @db_name NOT LIKE '[A-Za-z0-9_]%'
       OR @db_name LIKE '%[^A-Za-z0-9_]%'
    BEGIN
        RAISERROR('El parámetro @db_name contiene caracteres no permitidos.', 16, 1);
        RETURN;
    END

    -- ── Construcción de SQL dinámico ──────────────────────────────────────
    --    @db_name se concatena (validado arriba).
    --    @cod_personal se pasa como parámetro a sp_executesql (sin inyección).
    DECLARE @sql NVARCHAR(MAX);

    SET @sql = N'
        SELECT
            p.COD_PERSONAL,
            p.NOM_TRABAJADOR,
            p.APE_PATERNO,
            p.APE_MATERNO,
            A.NUM_DOC_IDENTIDAD,
            A.TIP_DOC_IDENTIDAD,
            A.NUM_TELEFONO,
            C.DES_CARGO,
            MA.DES_AREAS,
            PC.DES_CATEGORIA,
            TP.DES_TIPO_PLANILLA,
            P.FEC_INGRESO,
            PP.DES_PROFESION,
            PZ.DES_ZONA
        FROM      [' + @db_name + N'].dbo.PLA_PERSONAL      p
        INNER JOIN [' + @db_name + N'].dbo.MAE_AUXILIAR      A   ON  A.COD_AUXILIAR      = p.COD_AUXILIAR
        INNER JOIN [' + @db_name + N'].dbo.MAE_AREAS         MA  ON  MA.COD_AREAS        = p.COD_AREAS
        INNER JOIN [' + @db_name + N'].dbo.PLA_CATEGORIAS    PC  ON  PC.COD_CATEGORIA    = p.COD_CATEGORIA
        INNER JOIN [' + @db_name + N'].dbo.PLA_CARGOS        C   ON  C.COD_CARGO         = p.COD_CATEGORIA
                                                                  AND C.COD_CATEGORIA     = p.COD_CATEGORIA
        INNER JOIN [' + @db_name + N'].dbo.PLA_TIPO_PLANILLA TP  ON  TP.COD_TIPO_PLANILLA = p.COD_TIPO_PLANILLA
        INNER JOIN [' + @db_name + N'].dbo.PLA_PROFESIONES   PP  ON  PP.COD_PROFESION    = p.COD_PROFESION
        INNER JOIN [' + @db_name + N'].dbo.PLA_ZONAS         PZ  ON  PZ.COD_ZONA         = p.COD_ZONA
        WHERE  p.COD_PERSONAL  = @cod_personal
          AND  p.TIP_ESTADO    = ''AC''
    ';
    -- ── Si necesitas filtrar por TIPO_AUXILIAR descomenta: ────────────────
    -- SET @sql += N' AND A.TIPO_AUXILIAR = '''' ';

    -- ── Ejecutar con parámetro seguro ─────────────────────────────────────
    EXEC sp_executesql
        @sql,
        N'@cod_personal VARCHAR(20)',
        @cod_personal = @cod_personal;
END;
GO

-- ── TEST: cámbialo por el DB real antes de ejecutar ───────────────────────
-- EXEC dbo.SP_INTRANET_GET_TRABAJADOR @db_name = 'BDV0004', @cod_personal = '000101';
