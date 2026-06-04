-- ============================================================================
-- STORED PROCEDURE: SP_INTRANET_GET_BOLETA
-- BASE DE DATOS:    INTRANETCLL
-- DESCRIPCIÓN:      Retorna líneas de boleta de pago de un trabajador
--                   por período (formato AAAAMM, ej: 202501).
-- ============================================================================

USE INTRANETCLL;
GO

IF OBJECT_ID('dbo.SP_INTRANET_GET_BOLETA', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_BOLETA;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_BOLETA
    @db_name      VARCHAR(50),
    @cod_personal VARCHAR(20),
    @periodo      VARCHAR(6)     -- AAAAMM, ej: 202501
AS
BEGIN
    SET NOCOUNT ON;

    IF @db_name IS NULL OR LEN(LTRIM(RTRIM(@db_name))) = 0
    BEGIN
        RAISERROR('El parámetro @db_name es requerido.', 16, 1); RETURN;
    END

    IF @db_name LIKE '%[^A-Za-z0-9_]%'
    BEGIN
        RAISERROR('El parámetro @db_name contiene caracteres no permitidos.', 16, 1); RETURN;
    END

    DECLARE @sql NVARCHAR(MAX);

    -- ── ADAPTA este SELECT a las tablas reales de boleta de tu ERP ────────
    SET @sql = N'
        SELECT
            -- TODO: ajustar columnas según la tabla real del ERP
            b.COD_PERSONAL,
            b.PERIODO,
            b.COD_CONCEPTO,
            bc.DES_CONCEPTO,
            b.IMPORTE,
            b.TIP_MOVIMIENTO    -- I = Ingreso, D = Descuento
        FROM [' + @db_name + N'].dbo.PLA_BOLETA         b
        INNER JOIN [' + @db_name + N'].dbo.PLA_CONCEPTOS bc
               ON  bc.COD_CONCEPTO = b.COD_CONCEPTO
        WHERE  b.COD_PERSONAL = @cod_personal
          AND  b.PERIODO       = @periodo
        ORDER  BY b.TIP_MOVIMIENTO, b.COD_CONCEPTO
    ';

    EXEC sp_executesql
        @sql,
        N'@cod_personal VARCHAR(20), @periodo VARCHAR(6)',
        @cod_personal = @cod_personal,
        @periodo      = @periodo;
END;
GO
    
-- EXEC dbo.SP_INTRANET_GET_BOLETA 'BDV0004', '000101', '202501';
