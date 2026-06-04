-- ============================================================================
-- SP_INTRANET_CREAR_SOLICITUD_VAC  — ESCRITURA
-- Inserta una nueva solicitud de vacaciones en PLA_VACACIONES_MES del ERP.
-- ESTADO_APROBACION inicial = 'PE' (Pendiente).
-- ============================================================================
USE INTRANETCLL;
GO

IF OBJECT_ID('dbo.SP_INTRANET_CREAR_SOLICITUD_VAC','P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_CREAR_SOLICITUD_VAC;
GO

CREATE PROCEDURE dbo.SP_INTRANET_CREAR_SOLICITUD_VAC
    @db_name      VARCHAR(50),
    @cod_empresa  VARCHAR(4),    -- COD_EMPRESA del ERP  (siempre '0001')
    @cod_personal VARCHAR(20),
    @tip_vac      CHAR(2),       -- 'VG' = Gozadas | 'VC' = Compra
    @fec_inicio   DATE,
    @fec_final    DATE,
    @num_dias     SMALLINT,
    @ano_proceso  CHAR(4),
    @obs          NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    IF @db_name LIKE '%[^A-Za-z0-9_]%'
    BEGIN RAISERROR('@db_name inválido.',16,1); RETURN; END

    IF @num_dias <= 0
    BEGIN RAISERROR('El número de días debe ser mayor a cero.',16,1); RETURN; END

    IF @fec_final < @fec_inicio
    BEGIN RAISERROR('La fecha final no puede ser anterior a la fecha de inicio.',16,1); RETURN; END

    -- Verificar que el trabajador no tenga solicitud pendiente solapada
    DECLARE @chk NVARCHAR(MAX) =
        N'SELECT TOP 1 1 FROM [' + @db_name + N'].dbo.PLA_VACACIONES_MES
          WHERE COD_EMPRESA = @ce AND COD_PERSONAL = @cp
            AND ESTADO_APROBACION NOT IN (''RJ'',''RR'',''CA'')
            AND FEC_INICIO <= @ff AND FEC_FINAL >= @fi';

    DECLARE @existe BIT = 0;
    DECLARE @t TABLE (r INT);

    INSERT INTO @t
    EXEC sp_executesql @chk,
        N'@ce VARCHAR(4), @cp VARCHAR(20), @fi DATE, @ff DATE',
        @ce = @cod_empresa, @cp = @cod_personal,
        @fi = @fec_inicio,  @ff = @fec_final;

    IF EXISTS (SELECT 1 FROM @t)
    BEGIN
        RAISERROR('Ya existe una solicitud activa que se solapa con las fechas indicadas.',16,1);
        RETURN;
    END

    -- Generar correlativo: MAX(COD_CORR_VAC) + 1 para el trabajador
    DECLARE @getMax NVARCHAR(MAX) =
        N'SELECT ISNULL(MAX(COD_CORR_VAC), 0) + 1
          FROM [' + @db_name + N'].dbo.PLA_VACACIONES_MES
          WHERE COD_EMPRESA = @ce AND COD_PERSONAL = @cp';

    DECLARE @nuevo_corr INT;
    EXEC sp_executesql @getMax,
        N'@ce VARCHAR(4), @cp VARCHAR(20)',
        @ce = @cod_empresa, @cp = @cod_personal;

    -- ⚠️  sp_executesql no retorna escalares directamente.
    --     Usar tabla temporal para capturar el resultado:
    CREATE TABLE #MaxCorr (val INT);

    DECLARE @getMax2 NVARCHAR(MAX) =
        N'INSERT INTO #MaxCorr
          SELECT ISNULL(MAX(COD_CORR_VAC), 0) + 1
          FROM [' + @db_name + N'].dbo.PLA_VACACIONES_MES
          WHERE COD_EMPRESA = @ce AND COD_PERSONAL = @cp';

    EXEC sp_executesql @getMax2,
        N'@ce VARCHAR(4), @cp VARCHAR(20)',
        @ce = @cod_empresa, @cp = @cod_personal;

    SELECT @nuevo_corr = val FROM #MaxCorr;
    DROP TABLE #MaxCorr;

    -- Insertar la solicitud
    DECLARE @ins NVARCHAR(MAX) =
        N'INSERT INTO [' + @db_name + N'].dbo.PLA_VACACIONES_MES
            (COD_EMPRESA, COD_PERSONAL, COD_CORR_VAC,
             TIP_VACACIONES, FEC_INICIO, FEC_FINAL, NUM_DIAS,
             ANO_PROCESO, ESTADO_APROBACION)
          VALUES
            (@ce, @cp, @corr,
             @tv, @fi, @ff, @nd,
             @ap, ''PE'')';

    EXEC sp_executesql @ins,
        N'@ce VARCHAR(4), @cp VARCHAR(20), @corr INT,
          @tv CHAR(2), @fi DATE, @ff DATE, @nd SMALLINT, @ap CHAR(4)',
        @ce   = @cod_empresa,
        @cp   = @cod_personal,
        @corr = @nuevo_corr,
        @tv   = @tip_vac,
        @fi   = @fec_inicio,
        @ff   = @fec_final,
        @nd   = @num_dias,
        @ap   = @ano_proceso;

    -- Retornar el correlativo generado para confirmación
    SELECT @nuevo_corr AS COD_CORR_VAC;
END;
GO

-- TEST:
-- EXEC dbo.SP_INTRANET_CREAR_SOLICITUD_VAC
--     'BDV0004','0001','000101','VG','2026-07-01','2026-07-07',7,'2026',NULL;
