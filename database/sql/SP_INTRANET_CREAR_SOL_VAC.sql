-- ============================================================================
-- SP_INTRANET_CREAR_SOL_VAC  — ESCRITURA
-- BASE DE DATOS:  Cada BD del ERP (BDV0004, BDV0004_PRUEBA, IOLL, etc.)
-- PATRÓN:        B — el SP vive en la BD de la empresa; Laravel lo llama
--                como EXEC [db_name].dbo.SP_INTRANET_CREAR_SOL_VAC
-- Inserta una solicitud de vacaciones intranet en PLA_SOL_VACACIONES.
-- Reemplaza a USP_CREAR_SOL_VACACIONES (SP del ERP no siempre disponible).
-- ============================================================================
-- USE BDV0004;  -- cambiar por la BD destino
-- GO

IF OBJECT_ID('dbo.SP_INTRANET_CREAR_SOL_VAC', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_CREAR_SOL_VAC;
GO

CREATE PROCEDURE dbo.SP_INTRANET_CREAR_SOL_VAC
    @COD_PERSONAL       VARCHAR(20),
    @COD_EMPRESA        VARCHAR(10),
    @TIP_VACACIONES     VARCHAR(5),
    @ANO_PROCESO        INT,
    @MES_PROCESO        INT,
    @FEC_INICIO         DATE,
    @FEC_FINAL          DATE,
    @COD_USER_ACTUAL    VARCHAR(20),
    @COD_PERSONAL_JEFE  VARCHAR(100)  = NULL,   -- usuario (login intranet) del jefe
    @IMP_ADELANTO_VACAC DECIMAL(18,2) = NULL,
    @DESCUENTO_AFP      DECIMAL(18,2) = NULL,
    @PERIODO_VAC        VARCHAR(20)   = NULL,
    @COD_CORR_SOL       VARCHAR(5)    OUTPUT,
    @RESULTADO          INT           OUTPUT,
    @MENSAJE            VARCHAR(500)  OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @COD_CORR_SOL = NULL;
    SET @RESULTADO    = -1;
    SET @MENSAJE      = '';

    -- Validaciones básicas
    IF @TIP_VACACIONES NOT IN ('VG', 'VC')
    BEGIN
        SET @RESULTADO = 1;
        SET @MENSAJE   = 'Tipo de vacaciones inválido. Use VG o VC.';
        RETURN;
    END

    IF @FEC_FINAL < @FEC_INICIO
    BEGIN
        SET @RESULTADO = 2;
        SET @MENSAJE   = 'La fecha final no puede ser anterior a la fecha de inicio.';
        RETURN;
    END

    -- Verificar solapamiento con solicitudes activas del mismo trabajador
    IF EXISTS (
        SELECT 1
        FROM   dbo.PLA_SOL_VACACIONES
        WHERE  COD_EMPRESA        = @COD_EMPRESA
          AND  COD_PERSONAL       = @COD_PERSONAL
          AND  ESTADO_APROBACION  NOT IN ('RJ', 'RR', 'CA')
          AND  FEC_INICIO         <= @FEC_FINAL
          AND  FEC_FINAL          >= @FEC_INICIO
    )
    BEGIN
        SET @RESULTADO = 3;
        SET @MENSAJE   = 'Ya existe una solicitud activa que se solapa con las fechas indicadas.';
        RETURN;
    END

    -- Generar correlativo: MAX(COD_CORR_SOL) + 1 para el trabajador
    DECLARE @nuevoCorr INT;

    SELECT @nuevoCorr = ISNULL(MAX(CAST(COD_CORR_SOL AS INT)), 0) + 1
    FROM   dbo.PLA_SOL_VACACIONES
    WHERE  COD_EMPRESA  = @COD_EMPRESA
      AND  COD_PERSONAL = @COD_PERSONAL;

    -- Insertar solicitud
    INSERT INTO dbo.PLA_SOL_VACACIONES (
        COD_CORR_SOL,
        COD_PERSONAL,
        COD_EMPRESA,
        TIP_VACACIONES,
        ANO_PROCESO,
        MES_PROCESO,
        FEC_INICIO,
        FEC_FINAL,
        NUM_DIAS,
        COD_PERSONAL_JEFE,
        IMP_ADELANTO_VACAC,
        DESCUENTO_AFP,
        PERIODO_VAC,
        ESTADO_APROBACION,
        COD_USER_ACTUAL,
        FEC_ACTUALIZA
    )
    VALUES (
        CAST(@nuevoCorr AS VARCHAR(5)),
        @COD_PERSONAL,
        @COD_EMPRESA,
        @TIP_VACACIONES,
        @ANO_PROCESO,
        @MES_PROCESO,
        @FEC_INICIO,
        @FEC_FINAL,
        DATEDIFF(DAY, @FEC_INICIO, @FEC_FINAL) + 1,
        @COD_PERSONAL_JEFE,
        @IMP_ADELANTO_VACAC,
        @DESCUENTO_AFP,
        @PERIODO_VAC,
        'PE',
        @COD_USER_ACTUAL,
        GETDATE()
    );

    SET @COD_CORR_SOL = CAST(@nuevoCorr AS VARCHAR(5));
    SET @RESULTADO    = 0;
    SET @MENSAJE      = 'Solicitud registrada correctamente.';
END;
GO

-- TEST (ejecutar en la misma BD del ERP):
-- DECLARE @sol VARCHAR(5), @res INT, @msg VARCHAR(500);
-- EXEC dbo.SP_INTRANET_CREAR_SOL_VAC
--     @COD_PERSONAL='000101', @COD_EMPRESA='0001', @TIP_VACACIONES='VG',
--     @ANO_PROCESO=2026, @MES_PROCESO=7, @FEC_INICIO='2026-07-14', @FEC_FINAL='2026-07-31',
--     @COD_USER_ACTUAL='admin',
--     @COD_CORR_SOL=@sol OUTPUT, @RESULTADO=@res OUTPUT, @MENSAJE=@msg OUTPUT;
-- SELECT @sol AS cod_corr_sol, @res AS resultado, @msg AS mensaje;
