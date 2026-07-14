-- ============================================================================
-- MÓDULO: Boletas — Registro de visualización (un registro por boleta/mes)
-- BASE DE DATOS: Cada BD del ERP (BDV0004, IOLL, etc.) — Patrón B
-- ============================================================================
--
-- COLUMNAS RECOMENDADAS (adicionales a las base que el usuario ya creó)
-- Se aplican via ALTER TABLE idempotente antes de los SPs.
--
--  Snapshot del trabajador (desnormalizado, para reportes sin JOIN):
--   NOM_PERSONAL      VARCHAR(200)   NULL
--   DNI               VARCHAR(15)    NULL
--   CARGO             VARCHAR(150)   NULL
--
--  Snapshot financiero (qué vio el empleado):
--   IMP_INGRESOS      DECIMAL(18,2)  NULL
--   IMP_DESCUENTOS    DECIMAL(18,2)  NULL
--   IMP_NETO          DECIMAL(18,2)  NULL
--
--  Canal y conformidad digital:
--   DES_PLATAFORMA      VARCHAR(50)  NULL         -- 'WEB' | 'MOVIL' | 'API'
--   IND_FIRMA_CONFORME  CHAR(1) NOT NULL DEFAULT 'N'
--   FEC_FIRMA_CONFORME  DATETIME NULL
-- ============================================================================

-- ── Columnas adicionales (idempotente) ────────────────────────────────────────
IF OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA', 'U') IS NULL
BEGIN
    PRINT 'AVISO: La tabla LOG_VISUALIZACION_BOLETA no existe. Ejecuta primero el script de creación de tabla.';
    RETURN;
END
GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='NOM_PERSONAL')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD NOM_PERSONAL VARCHAR(200) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='DNI')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD DNI VARCHAR(15) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='CARGO')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD CARGO VARCHAR(150) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='IMP_INGRESOS')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD IMP_INGRESOS DECIMAL(18,2) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='IMP_DESCUENTOS')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD IMP_DESCUENTOS DECIMAL(18,2) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='IMP_NETO')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD IMP_NETO DECIMAL(18,2) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='DES_PLATAFORMA')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD DES_PLATAFORMA VARCHAR(50) NULL; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='IND_FIRMA_CONFORME')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA
        ADD IND_FIRMA_CONFORME CHAR(1) NOT NULL CONSTRAINT DF_LOG_VIS_BOLETA_FIRMA DEFAULT 'N'; GO
IF NOT EXISTS (SELECT 1 FROM sys.columns WHERE object_id=OBJECT_ID('dbo.LOG_VISUALIZACION_BOLETA') AND name='FEC_FIRMA_CONFORME')
    ALTER TABLE dbo.LOG_VISUALIZACION_BOLETA ADD FEC_FIRMA_CONFORME DATETIME NULL; GO

PRINT '  [OK] LOG_VISUALIZACION_BOLETA: columnas adicionales verificadas.';
GO

-- ── SP_INTRANET_REGISTRAR_VIS_BOLETA ─────────────────────────────────────────
-- GARANTÍA DE UN SOLO REGISTRO por (COD_EMPRESA, COD_PERSONAL, ANO_PROCESO,
-- MES_PROCESO, TIP_BOLETA). Si ya existe devuelve el ID existente y
-- ES_PRIMERA_VEZ = 0 sin insertar nada nuevo.
-- Llamar desde el modal de confirmación del frontend (primera vez) o
-- desde la app móvil al abrir la boleta.

IF OBJECT_ID('dbo.SP_INTRANET_REGISTRAR_VIS_BOLETA', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_REGISTRAR_VIS_BOLETA;
GO

CREATE PROCEDURE dbo.SP_INTRANET_REGISTRAR_VIS_BOLETA
    -- Identificación
    @COD_EMPRESA      VARCHAR(10),
    @COD_PERSONAL     VARCHAR(20),
    @COD_USUARIO      VARCHAR(100),
    @ANO_PROCESO      INT,
    @MES_PROCESO      INT,
    @TIP_BOLETA       VARCHAR(20)    = 'REMUNERACION',
    -- Snapshot del trabajador
    @NOM_PERSONAL     VARCHAR(200)   = NULL,
    @DNI              VARCHAR(15)    = NULL,
    @CARGO            VARCHAR(150)   = NULL,
    -- Snapshot financiero de la boleta
    @IMP_INGRESOS     DECIMAL(18,2)  = NULL,
    @IMP_DESCUENTOS   DECIMAL(18,2)  = NULL,
    @IMP_NETO         DECIMAL(18,2)  = NULL,
    -- Datos de sesión
    @DES_IP           VARCHAR(45)    = NULL,
    @DES_DISPOSITIVO  VARCHAR(150)   = NULL,
    @DES_PLATAFORMA   VARCHAR(50)    = 'WEB',
    -- Salida
    @ID_LOG           INT            = NULL OUTPUT,
    @ES_PRIMERA_VEZ   BIT            = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    IF @MES_PROCESO < 1 OR @MES_PROCESO > 12
    BEGIN RAISERROR('MES_PROCESO debe estar entre 1 y 12.', 16, 1); RETURN; END

    DECLARE @tip VARCHAR(20) = ISNULL(@TIP_BOLETA, 'REMUNERACION');

    -- Verificar registro existente (deduplicación)
    SELECT TOP 1 @ID_LOG = ID
    FROM dbo.LOG_VISUALIZACION_BOLETA
    WHERE COD_EMPRESA  = @COD_EMPRESA
      AND COD_PERSONAL = @COD_PERSONAL
      AND ANO_PROCESO  = @ANO_PROCESO
      AND MES_PROCESO  = @MES_PROCESO
      AND TIP_BOLETA   = @tip;

    IF @ID_LOG IS NOT NULL
    BEGIN
        -- Ya existe — no duplicar
        SET @ES_PRIMERA_VEZ = 0;
    END
    ELSE
    BEGIN
        -- Primera visualización — insertar
        INSERT INTO dbo.LOG_VISUALIZACION_BOLETA (
            COD_EMPRESA, COD_PERSONAL, COD_USUARIO, ANO_PROCESO, MES_PROCESO,
            TIP_BOLETA, NOM_PERSONAL, DNI, CARGO,
            IMP_INGRESOS, IMP_DESCUENTOS, IMP_NETO,
            FEC_VISUALIZACION, DES_IP, DES_DISPOSITIVO, DES_PLATAFORMA, IND_FIRMA_CONFORME
        )
        VALUES (
            @COD_EMPRESA, @COD_PERSONAL, @COD_USUARIO, @ANO_PROCESO, @MES_PROCESO,
            @tip, @NOM_PERSONAL, @DNI, @CARGO,
            @IMP_INGRESOS, @IMP_DESCUENTOS, @IMP_NETO,
            GETDATE(), @DES_IP, @DES_DISPOSITIVO, ISNULL(@DES_PLATAFORMA, 'WEB'), 'N'
        );
        SET @ID_LOG         = SCOPE_IDENTITY();
        SET @ES_PRIMERA_VEZ = 1;
    END

    SELECT @ID_LOG AS ID_LOG, @ES_PRIMERA_VEZ AS ES_PRIMERA_VEZ;
END;
GO
PRINT '  [OK] SP_INTRANET_REGISTRAR_VIS_BOLETA creado (con dedup).';
GO

-- ── SP_INTRANET_GET_BOLETAS_VISTAS ────────────────────────────────────────────
-- Devuelve la lista de boletas ya visualizadas por el trabajador.
-- El frontend usa este resultado para saber qué períodos muestran el
-- modal de "primera vez" y cuáles abren directo.

IF OBJECT_ID('dbo.SP_INTRANET_GET_BOLETAS_VISTAS', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_BOLETAS_VISTAS;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_BOLETAS_VISTAS
    @COD_PERSONAL VARCHAR(20),
    @COD_EMPRESA  VARCHAR(10)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        CAST(ANO_PROCESO AS VARCHAR(4))
            + RIGHT('0' + CAST(MES_PROCESO AS VARCHAR(2)), 2) AS PERIODO,
        ANO_PROCESO,
        MES_PROCESO,
        TIP_BOLETA,
        FEC_VISUALIZACION                                      AS FEC_PRIMERA_VIS,
        IND_FIRMA_CONFORME
    FROM dbo.LOG_VISUALIZACION_BOLETA
    WHERE COD_PERSONAL = @COD_PERSONAL
      AND COD_EMPRESA  = @COD_EMPRESA
    ORDER BY ANO_PROCESO DESC, MES_PROCESO DESC;
END;
GO
PRINT '  [OK] SP_INTRANET_GET_BOLETAS_VISTAS creado.';
GO

-- ── SP_INTRANET_CONFIRMAR_BOLETA ──────────────────────────────────────────────
-- Marca la conformidad digital del trabajador (N → S). Idempotente.

IF OBJECT_ID('dbo.SP_INTRANET_CONFIRMAR_BOLETA', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_CONFIRMAR_BOLETA;
GO

CREATE PROCEDURE dbo.SP_INTRANET_CONFIRMAR_BOLETA
    @ID_LOG INT
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.LOG_VISUALIZACION_BOLETA
    SET    IND_FIRMA_CONFORME = 'S',
           FEC_FIRMA_CONFORME = GETDATE()
    WHERE  ID                 = @ID_LOG
      AND  IND_FIRMA_CONFORME = 'N';

    SELECT @@ROWCOUNT AS FILAS_AFECTADAS,
           IND_FIRMA_CONFORME, FEC_FIRMA_CONFORME
    FROM   dbo.LOG_VISUALIZACION_BOLETA WHERE ID = @ID_LOG;
END;
GO
PRINT '  [OK] SP_INTRANET_CONFIRMAR_BOLETA creado.';
GO

-- ── TEST ─────────────────────────────────────────────────────────────────────
/*
-- Primer intento (inserta, ES_PRIMERA_VEZ = 1):
DECLARE @id INT, @pv BIT;
EXEC dbo.SP_INTRANET_REGISTRAR_VIS_BOLETA
    @COD_EMPRESA='0001', @COD_PERSONAL='000101', @COD_USUARIO='jperez',
    @ANO_PROCESO=2026, @MES_PROCESO=7, @IMP_NETO=4550.00, @DES_PLATAFORMA='WEB',
    @ID_LOG=@id OUTPUT, @ES_PRIMERA_VEZ=@pv OUTPUT;
SELECT @id AS id_log, @pv AS es_primera_vez;

-- Segundo intento mismo período (no inserta, ES_PRIMERA_VEZ = 0):
EXEC dbo.SP_INTRANET_REGISTRAR_VIS_BOLETA
    @COD_EMPRESA='0001', @COD_PERSONAL='000101', @COD_USUARIO='jperez',
    @ANO_PROCESO=2026, @MES_PROCESO=7, @DES_PLATAFORMA='WEB',
    @ID_LOG=@id OUTPUT, @ES_PRIMERA_VEZ=@pv OUTPUT;
SELECT @id AS id_log, @pv AS es_primera_vez;

-- Listar boletas vistas:
EXEC dbo.SP_INTRANET_GET_BOLETAS_VISTAS @COD_PERSONAL='000101', @COD_EMPRESA='0001';
*/
