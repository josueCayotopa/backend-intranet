-- ============================================================================
-- SETUP_INTRANET_ERP_DB.sql
-- Instala TODOS los objetos de la intranet en una BD del ERP.
-- Ejecutar COMPLETO en cada nueva BD de empresa que se incorpore.
--
-- USO:
--   1. Cambiar 'BDV0004' por el nombre de la BD destino en la línea USE
--   2. Ejecutar todo el script en SSMS contra esa BD
-- ============================================================================

USE BDV0004;   -- ← CAMBIAR POR LA BD DESTINO
GO

PRINT '======================================================';
PRINT ' INTRANET — Setup en BD: ' + DB_NAME();
PRINT '======================================================';
GO

-- ============================================================================
-- 1. TABLA PLA_SOL_VACACIONES (solicitudes de vacaciones intranet)
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.objects
    WHERE object_id = OBJECT_ID(N'dbo.PLA_SOL_VACACIONES') AND type = 'U'
)
BEGIN
        CREATE TABLE dbo.PLA_SOL_VACACIONES (
            COD_CORR_SOL        VARCHAR(5)      NOT NULL,
            COD_PERSONAL        VARCHAR(20)     NOT NULL,
            COD_EMPRESA         VARCHAR(10)     NOT NULL,
            TIP_VACACIONES      VARCHAR(5)      NOT NULL,   -- 'VG' | 'VC'
            ANO_PROCESO         INT             NOT NULL,
            MES_PROCESO         INT             NOT NULL,
            FEC_INICIO          DATE            NOT NULL,
            FEC_FINAL           DATE            NOT NULL,
            NUM_DIAS            INT             NOT NULL,
            COD_PERSONAL_JEFE   VARCHAR(20)     NULL,
            IMP_ADELANTO_VACAC  DECIMAL(18,2)   NULL,
            DESCUENTO_AFP       DECIMAL(18,2)   NULL,
            PERIODO_VAC         VARCHAR(20)     NULL,
            -- PE=Pendiente | AJ=Aprobado Jefe | RJ=Rechazado Jefe
            -- AR=Aprobado RRHH | RR=Rechazado RRHH | CA=Cancelado
            ESTADO_APROBACION   CHAR(2)         NOT NULL    DEFAULT 'PE',
            COD_USER_ACTUAL     VARCHAR(20)     NOT NULL,
            FEC_ACTUALIZA       DATETIME        NOT NULL    DEFAULT GETDATE(),
            CONSTRAINT PK_PLA_SOL_VACACIONES
                PRIMARY KEY (COD_CORR_SOL, COD_PERSONAL, COD_EMPRESA)
        );

    CREATE INDEX IX_SOL_VAC_ESTADO
        ON dbo.PLA_SOL_VACACIONES (COD_EMPRESA, ESTADO_APROBACION);

    CREATE INDEX IX_SOL_VAC_PERSONAL
        ON dbo.PLA_SOL_VACACIONES (COD_PERSONAL, ANO_PROCESO);

    PRINT '  [OK] Tabla PLA_SOL_VACACIONES creada.';
END
ELSE
    PRINT '  [--] Tabla PLA_SOL_VACACIONES ya existe.';
GO

-- ============================================================================
-- 2. SP_INTRANET_GET_TRABAJADOR
-- ============================================================================
IF OBJECT_ID('dbo.SP_INTRANET_GET_TRABAJADOR', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_TRABAJADOR;
GO
CREATE PROCEDURE dbo.SP_INTRANET_GET_TRABAJADOR
    @cod_personal VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;
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
    FROM      dbo.PLA_PERSONAL      p
    LEFT JOIN dbo.MAE_AUXILIAR      A   ON  A.COD_AUXILIAR       = p.COD_AUXILIAR
    LEFT JOIN dbo.MAE_AREAS         MA  ON  MA.COD_AREAS         = p.COD_AREAS
    LEFT JOIN dbo.PLA_CATEGORIAS    PC  ON  PC.COD_CATEGORIA     = p.COD_CATEGORIA
    LEFT JOIN dbo.PLA_CARGOS        C   ON  C.COD_CARGO          = p.COD_CARGO
                                        AND C.COD_CATEGORIA      = p.COD_CATEGORIA
    LEFT JOIN dbo.PLA_TIPO_PLANILLA TP  ON  TP.COD_TIPO_PLANILLA = p.COD_TIPO_PLANILLA
    LEFT JOIN dbo.PLA_PROFESIONES   PP  ON  PP.COD_PROFESION     = p.COD_PROFESION
    LEFT JOIN dbo.PLA_ZONAS         PZ  ON  PZ.COD_ZONA          = p.COD_ZONA
    WHERE  p.COD_PERSONAL = @cod_personal
      AND  p.TIP_ESTADO   = 'AC';
END;
GO
PRINT '  [OK] SP_INTRANET_GET_TRABAJADOR creado.';
GO

-- ============================================================================
-- 3. SP_INTRANET_GET_VACACIONES
-- ============================================================================
IF OBJECT_ID('dbo.SP_INTRANET_GET_VACACIONES', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_VACACIONES;
GO
CREATE PROCEDURE dbo.SP_INTRANET_GET_VACACIONES
    @cod_personal VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        P.COD_PERSONAL,
        P.APE_PATERNO + ' ' + P.APE_MATERNO + ', ' + P.NOM_TRABAJADOR AS NOMBRE_COMPLETO,
        A.NUM_DOC_IDENTIDAD,
        C.DES_CARGO,
        MA.DES_AREAS,
        E.DES_NOMBRE_COMERCIAL   AS EMPRESA,
        P.FEC_INGRESO,
        CAST(YEAR(GETDATE()) AS VARCHAR(4)) AS ANO_ACTUAL,
        ISNULL((
            SELECT SUM(C2.NUM_TOT_DIAS)
            FROM   dbo.PLA_VACACIONES_MES_CAB C2
            WHERE  C2.COD_EMPRESA  = P.COD_EMPRESA
              AND  C2.COD_PERSONAL = P.COD_PERSONAL
              AND  C2.ANO_PROCESO  = CAST(YEAR(GETDATE()) AS VARCHAR(4))
        ), 0) AS DIAS_GOZADOS_ANIO,
        ISNULL((
            SELECT SUM(V3.NUM_DIAS)
            FROM   dbo.PLA_VACACIONES_MES V3
            WHERE  V3.COD_EMPRESA       = P.COD_EMPRESA
              AND  V3.COD_PERSONAL      = P.COD_PERSONAL
              AND  V3.ESTADO_APROBACION IN ('PE', 'AJ')
        ), 0) AS DIAS_PENDIENTES,
        CAB.COD_CORR_VAC,
        CAB.ANO_PROCESO,
        CAB.MES_PROCESO,
        CAB.FEC_INICIO,
        CAB.FEC_FIN,
        CAB.NUM_TOT_DIAS,
        CAB.IMP_TOT_VACACION,
        CAB.IND_TRANSF_PLAN,
        ISNULL(D.COD_PERIODO,    '')    AS COD_PERIODO,
        ISNULL(D.TIP_VACACIONES, 'VN')  AS TIP_VACACIONES
    FROM       dbo.PLA_PERSONAL       P
    INNER JOIN dbo.MAE_AUXILIAR       A   ON  A.COD_AUXILIAR   = P.COD_AUXILIAR
    INNER JOIN dbo.MAE_AREAS          MA  ON  MA.COD_AREAS     = P.COD_AREAS
                                          AND MA.NUM_VER_AREAS = P.NUM_VER_AREAS
    INNER JOIN dbo.PLA_CARGOS         C   ON  C.COD_CARGO      = P.COD_CARGO
                                          AND C.COD_CATEGORIA  = P.COD_CATEGORIA
    INNER JOIN dbo.MAE_EMPRESAS       E   ON  E.COD_EMPRESA    = P.COD_EMPRESA
    LEFT JOIN  dbo.PLA_VACACIONES_MES_CAB CAB
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
PRINT '  [OK] SP_INTRANET_GET_VACACIONES creado.';
GO

-- ============================================================================
-- 4. SP_INTRANET_GET_SOLICITUDES_VAC
-- ============================================================================
IF OBJECT_ID('dbo.SP_INTRANET_GET_SOLICITUDES_VAC', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_SOLICITUDES_VAC;
GO
CREATE PROCEDURE dbo.SP_INTRANET_GET_SOLICITUDES_VAC
    @cod_personal VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT
        S.COD_CORR_SOL,
        S.COD_EMPRESA,
        S.TIP_VACACIONES,
        S.ANO_PROCESO,
        S.MES_PROCESO,
        S.FEC_INICIO,
        S.FEC_FINAL,
        S.NUM_DIAS,
        S.ESTADO_APROBACION,
        S.COD_USER_ACTUAL,
        S.FEC_ACTUALIZA,
        S.IMP_ADELANTO_VACAC,
        S.DESCUENTO_AFP,
        S.PERIODO_VAC
    FROM   dbo.PLA_SOL_VACACIONES S
    WHERE  S.COD_PERSONAL = @cod_personal
    ORDER BY S.FEC_ACTUALIZA DESC, S.COD_CORR_SOL DESC;
END;
GO
PRINT '  [OK] SP_INTRANET_GET_SOLICITUDES_VAC creado.';
GO

-- ============================================================================
-- 5. SP_INTRANET_CREAR_SOL_VAC
-- ============================================================================
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
    @COD_PERSONAL_JEFE  VARCHAR(20)   = NULL,
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

    IF @TIP_VACACIONES NOT IN ('VG', 'VC')
    BEGIN
        SET @RESULTADO = 1; SET @MENSAJE = 'Tipo de vacaciones inválido. Use VG o VC.'; RETURN;
    END
    IF @FEC_FINAL < @FEC_INICIO
    BEGIN
        SET @RESULTADO = 2; SET @MENSAJE = 'La fecha final no puede ser anterior a la fecha de inicio.'; RETURN;
    END
    IF EXISTS (
        SELECT 1 FROM dbo.PLA_SOL_VACACIONES
        WHERE  COD_EMPRESA       = @COD_EMPRESA
          AND  COD_PERSONAL      = @COD_PERSONAL
          AND  ESTADO_APROBACION NOT IN ('RJ', 'RR', 'CA')
          AND  FEC_INICIO        <= @FEC_FINAL
          AND  FEC_FINAL         >= @FEC_INICIO
    )
    BEGIN
        SET @RESULTADO = 3;
        SET @MENSAJE   = 'Ya existe una solicitud activa que se solapa con las fechas indicadas.';
        RETURN;
    END

    DECLARE @nuevoCorr INT;
    SELECT @nuevoCorr = ISNULL(MAX(CAST(COD_CORR_SOL AS INT)), 0) + 1
    FROM   dbo.PLA_SOL_VACACIONES
    WHERE  COD_EMPRESA = @COD_EMPRESA AND COD_PERSONAL = @COD_PERSONAL;

    INSERT INTO dbo.PLA_SOL_VACACIONES (
        COD_CORR_SOL, COD_PERSONAL, COD_EMPRESA,
        TIP_VACACIONES, ANO_PROCESO, MES_PROCESO,
        FEC_INICIO, FEC_FINAL, NUM_DIAS,
        COD_PERSONAL_JEFE, IMP_ADELANTO_VACAC, DESCUENTO_AFP,
        PERIODO_VAC, ESTADO_APROBACION, COD_USER_ACTUAL, FEC_ACTUALIZA
    ) VALUES (
        CAST(@nuevoCorr AS VARCHAR(5)), @COD_PERSONAL, @COD_EMPRESA,
        @TIP_VACACIONES, @ANO_PROCESO, @MES_PROCESO,
        @FEC_INICIO, @FEC_FINAL,
        DATEDIFF(DAY, @FEC_INICIO, @FEC_FINAL) + 1,
        @COD_PERSONAL_JEFE, @IMP_ADELANTO_VACAC, @DESCUENTO_AFP,
        @PERIODO_VAC, 'PE', @COD_USER_ACTUAL, GETDATE()
    );

    SET @COD_CORR_SOL = CAST(@nuevoCorr AS VARCHAR(5));
    SET @RESULTADO    = 0;
    SET @MENSAJE      = 'Solicitud registrada correctamente.';
END;
GO
PRINT '  [OK] SP_INTRANET_CREAR_SOL_VAC creado.';
GO

-- ============================================================================
-- 6. SP_INTRANET_CANCELAR_SOL_VAC
-- ============================================================================
IF OBJECT_ID('dbo.SP_INTRANET_CANCELAR_SOL_VAC', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_CANCELAR_SOL_VAC;
GO
CREATE PROCEDURE dbo.SP_INTRANET_CANCELAR_SOL_VAC
    @cod_empresa  VARCHAR(10),
    @cod_personal VARCHAR(20),
    @cod_corr_sol VARCHAR(5)
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (
        SELECT 1 FROM dbo.PLA_SOL_VACACIONES
        WHERE  COD_EMPRESA       = @cod_empresa
          AND  COD_PERSONAL      = @cod_personal
          AND  COD_CORR_SOL      = @cod_corr_sol
          AND  ESTADO_APROBACION IN ('PE', 'AJ')
    )
    BEGIN
        RAISERROR('La solicitud no se puede cancelar en su estado actual.', 16, 1); RETURN;
    END
    UPDATE dbo.PLA_SOL_VACACIONES
    SET    ESTADO_APROBACION = 'CA', FEC_ACTUALIZA = GETDATE()
    WHERE  COD_EMPRESA  = @cod_empresa
      AND  COD_PERSONAL = @cod_personal
      AND  COD_CORR_SOL = @cod_corr_sol;
    SELECT @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO
PRINT '  [OK] SP_INTRANET_CANCELAR_SOL_VAC creado.';
GO

-- ============================================================================
-- 7. COLUMNAS ADICIONALES EN PLA_VACACIONES_MES
--    Agrega solo las que faltan; no toca las que ya existen.
-- ============================================================================
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'ESTADO_APROBACION'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD ESTADO_APROBACION CHAR(2) NOT NULL DEFAULT 'PE';
    PRINT '  [OK] PLA_VACACIONES_MES: columna ESTADO_APROBACION agregada.';
END
ELSE
    PRINT '  [--] PLA_VACACIONES_MES: ESTADO_APROBACION ya existe.';
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'FEC_APROBACION_JEFE'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD FEC_APROBACION_JEFE DATETIME NULL;
    PRINT '  [OK] PLA_VACACIONES_MES: columna FEC_APROBACION_JEFE agregada.';
END
ELSE
    PRINT '  [--] PLA_VACACIONES_MES: FEC_APROBACION_JEFE ya existe.';
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'FEC_APROBACION_RRHH'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD FEC_APROBACION_RRHH DATETIME NULL;
    PRINT '  [OK] PLA_VACACIONES_MES: columna FEC_APROBACION_RRHH agregada.';
END
ELSE
    PRINT '  [--] PLA_VACACIONES_MES: FEC_APROBACION_RRHH ya existe.';
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'PERIODO_VACACIONAL'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD PERIODO_VACACIONAL NVARCHAR(100) NULL;
    PRINT '  [OK] PLA_VACACIONES_MES: columna PERIODO_VACACIONAL agregada.';
END
ELSE
    PRINT '  [--] PLA_VACACIONES_MES: PERIODO_VACACIONAL ya existe.';
GO

-- ============================================================================
-- 9. SP_INTRANET_CANCELAR_VAC
-- ============================================================================
IF OBJECT_ID('dbo.SP_INTRANET_CANCELAR_VAC', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_CANCELAR_VAC;
GO
CREATE PROCEDURE dbo.SP_INTRANET_CANCELAR_VAC
    @cod_empresa  VARCHAR(4),
    @cod_personal VARCHAR(20),
    @cod_corr_vac INT
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT EXISTS (
        SELECT 1 FROM dbo.PLA_VACACIONES_MES
        WHERE  COD_EMPRESA       = @cod_empresa
          AND  COD_PERSONAL      = @cod_personal
          AND  COD_CORR_VAC      = @cod_corr_vac
          AND  ESTADO_APROBACION IN ('PE', 'AJ')
    )
    BEGIN
        RAISERROR('La solicitud no se puede cancelar en su estado actual.', 16, 1); RETURN;
    END
    UPDATE dbo.PLA_VACACIONES_MES
    SET    ESTADO_APROBACION = 'CA'
    WHERE  COD_EMPRESA  = @cod_empresa
      AND  COD_PERSONAL = @cod_personal
      AND  COD_CORR_VAC = @cod_corr_vac;
    SELECT @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO
PRINT '  [OK] SP_INTRANET_CANCELAR_VAC creado.';
GO

-- ============================================================================
-- 10. SP_INTRANET_APROBAR_VAC
-- ============================================================================
IF OBJECT_ID('dbo.SP_INTRANET_APROBAR_VAC', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_APROBAR_VAC;
GO
CREATE PROCEDURE dbo.SP_INTRANET_APROBAR_VAC
    @cod_empresa  VARCHAR(4),
    @cod_personal VARCHAR(20),
    @cod_corr_vac INT,
    @accion       VARCHAR(20),
    @obs          NVARCHAR(500) = NULL,
    @periodo_vac  NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @estadoActual CHAR(2);
    SELECT @estadoActual = ESTADO_APROBACION
    FROM   dbo.PLA_VACACIONES_MES
    WHERE  COD_EMPRESA = @cod_empresa AND COD_PERSONAL = @cod_personal AND COD_CORR_VAC = @cod_corr_vac;

    IF @estadoActual IS NULL
    BEGIN RAISERROR('Solicitud no encontrada.', 16, 1); RETURN; END

    IF @accion IN ('APROBAR_JEFE','RECHAZAR_JEFE') AND @estadoActual <> 'PE'
    BEGIN RAISERROR('Solo se puede aprobar/rechazar si la solicitud está Pendiente.', 16, 1); RETURN; END

    IF @accion IN ('APROBAR_RRHH','RECHAZAR_RRHH') AND @estadoActual <> 'AJ'
    BEGIN RAISERROR('RRHH solo puede actuar sobre solicitudes aprobadas por el Jefe.', 16, 1); RETURN; END

    DECLARE @nuevoEstado CHAR(2) =
        CASE @accion
            WHEN 'APROBAR_JEFE'  THEN 'AJ'
            WHEN 'RECHAZAR_JEFE' THEN 'RJ'
            WHEN 'APROBAR_RRHH'  THEN 'AR'
            WHEN 'RECHAZAR_RRHH' THEN 'RR'
        END;

    IF @nuevoEstado IS NULL
    BEGIN RAISERROR('Acción no reconocida.', 16, 1); RETURN; END

    IF @accion IN ('APROBAR_JEFE','RECHAZAR_JEFE')
        UPDATE dbo.PLA_VACACIONES_MES
        SET    ESTADO_APROBACION = @nuevoEstado, FEC_APROBACION_JEFE = GETDATE()
        WHERE  COD_EMPRESA = @cod_empresa AND COD_PERSONAL = @cod_personal AND COD_CORR_VAC = @cod_corr_vac;
    ELSE
        UPDATE dbo.PLA_VACACIONES_MES
        SET    ESTADO_APROBACION = @nuevoEstado, FEC_APROBACION_RRHH = GETDATE(), PERIODO_VACACIONAL = @periodo_vac
        WHERE  COD_EMPRESA = @cod_empresa AND COD_PERSONAL = @cod_personal AND COD_CORR_VAC = @cod_corr_vac;

    SELECT @nuevoEstado AS NUEVO_ESTADO, @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO
PRINT '  [OK] SP_INTRANET_APROBAR_VAC creado.';
GO

-- ============================================================================
-- RESUMEN FINAL
-- ============================================================================
PRINT '';
PRINT '======================================================';
PRINT ' Setup completado en: ' + DB_NAME();
PRINT ' Objetos instalados:';
PRINT '   - PLA_SOL_VACACIONES (tabla)';
PRINT '   - SP_INTRANET_GET_TRABAJADOR';
PRINT '   - SP_INTRANET_GET_VACACIONES';
PRINT '   - SP_INTRANET_GET_SOLICITUDES_VAC';
PRINT '   - SP_INTRANET_CREAR_SOL_VAC';
PRINT '   - SP_INTRANET_CANCELAR_SOL_VAC';
PRINT '   - SP_INTRANET_CANCELAR_VAC';
PRINT '   - SP_INTRANET_APROBAR_VAC';
PRINT '======================================================';
GO
