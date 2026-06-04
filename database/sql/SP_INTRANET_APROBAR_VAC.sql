-- ============================================================================
-- SP_INTRANET_APROBAR_VAC  — ESCRITURA
-- Gestiona las transiciones de estado de una solicitud de vacaciones.
--
-- FLUJO:
--   PE  → [Jefe]  aprueba  → AJ
--   PE  → [Jefe]  rechaza  → RJ
--   AJ  → [RRHH]  aprueba  → AR  (rellena periodo_vacacional)
--   AJ  → [RRHH]  rechaza  → RR
--   PE o AJ → [Empleado] cancela → CA  (via SP_INTRANET_CANCELAR_VAC)
--
-- @accion:
--   'APROBAR_JEFE'  — jefe aprueba (PE → AJ)
--   'RECHAZAR_JEFE' — jefe rechaza (PE → RJ)
--   'APROBAR_RRHH'  — RRHH aprueba (AJ → AR)
--   'RECHAZAR_RRHH' — RRHH rechaza (AJ → RR)
-- ============================================================================
USE INTRANETCLL;
GO

IF OBJECT_ID('dbo.SP_INTRANET_APROBAR_VAC','P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_APROBAR_VAC;
GO

CREATE PROCEDURE dbo.SP_INTRANET_APROBAR_VAC
    @db_name          VARCHAR(50),
    @cod_empresa      VARCHAR(4),
    @cod_personal     VARCHAR(20),
    @cod_corr_vac     INT,
    @accion           VARCHAR(20),      -- ver flujo arriba
    @obs              NVARCHAR(500) = NULL,
    @periodo_vac      NVARCHAR(100) = NULL  -- solo para APROBAR_RRHH
AS
BEGIN
    SET NOCOUNT ON;

    IF @db_name LIKE '%[^A-Za-z0-9_]%'
    BEGIN RAISERROR('@db_name inválido.',16,1); RETURN; END

    -- Leer estado actual
    CREATE TABLE #EstadoActual (estado CHAR(2));

    DECLARE @getEstado NVARCHAR(MAX) =
        N'INSERT INTO #EstadoActual
          SELECT ESTADO_APROBACION
          FROM   [' + @db_name + N'].dbo.PLA_VACACIONES_MES
          WHERE  COD_EMPRESA  = @ce
            AND  COD_PERSONAL = @cp
            AND  COD_CORR_VAC = @corr';

    EXEC sp_executesql @getEstado,
        N'@ce VARCHAR(4), @cp VARCHAR(20), @corr INT',
        @ce = @cod_empresa, @cp = @cod_personal, @corr = @cod_corr_vac;

    IF NOT EXISTS (SELECT 1 FROM #EstadoActual)
    BEGIN
        DROP TABLE #EstadoActual;
        RAISERROR('Solicitud no encontrada.',16,1); RETURN;
    END

    DECLARE @estadoActual CHAR(2);
    SELECT @estadoActual = estado FROM #EstadoActual;
    DROP TABLE #EstadoActual;

    -- Validar transición permitida
    IF @accion IN ('APROBAR_JEFE','RECHAZAR_JEFE') AND @estadoActual <> 'PE'
    BEGIN RAISERROR('Solo se puede aprobar/rechazar si la solicitud está Pendiente.',16,1); RETURN; END

    IF @accion IN ('APROBAR_RRHH','RECHAZAR_RRHH') AND @estadoActual <> 'AJ'
    BEGIN RAISERROR('RRHH solo puede actuar sobre solicitudes aprobadas por el Jefe.',16,1); RETURN; END

    -- Nuevo estado según acción
    DECLARE @nuevoEstado CHAR(2) =
        CASE @accion
            WHEN 'APROBAR_JEFE'  THEN 'AJ'
            WHEN 'RECHAZAR_JEFE' THEN 'RJ'
            WHEN 'APROBAR_RRHH'  THEN 'AR'
            WHEN 'RECHAZAR_RRHH' THEN 'RR'
        END;

    IF @nuevoEstado IS NULL
    BEGIN RAISERROR('Acción no reconocida.',16,1); RETURN; END

    -- Actualizar registro
    DECLARE @upd NVARCHAR(MAX);

    IF @accion IN ('APROBAR_JEFE','RECHAZAR_JEFE')
        SET @upd = N'
            UPDATE [' + @db_name + N'].dbo.PLA_VACACIONES_MES
            SET    ESTADO_APROBACION = @ne,
                   FEC_APROBACION_JEFE = GETDATE()
            WHERE  COD_EMPRESA  = @ce
              AND  COD_PERSONAL = @cp
              AND  COD_CORR_VAC = @corr';
    ELSE
        SET @upd = N'
            UPDATE [' + @db_name + N'].dbo.PLA_VACACIONES_MES
            SET    ESTADO_APROBACION   = @ne,
                   FEC_APROBACION_RRHH = GETDATE(),
                   PERIODO_VACACIONAL  = @pv
            WHERE  COD_EMPRESA  = @ce
              AND  COD_PERSONAL = @cp
              AND  COD_CORR_VAC = @corr';

    EXEC sp_executesql @upd,
        N'@ne CHAR(2), @ce VARCHAR(4), @cp VARCHAR(20), @corr INT, @pv NVARCHAR(100)',
        @ne   = @nuevoEstado,
        @ce   = @cod_empresa,
        @cp   = @cod_personal,
        @corr = @cod_corr_vac,
        @pv   = @periodo_vac;

    SELECT @nuevoEstado AS NUEVO_ESTADO, @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO

-- TEST aprobación jefe:
-- EXEC dbo.SP_INTRANET_APROBAR_VAC 'BDV0004','0001','000101',1,'APROBAR_JEFE',NULL,NULL;
-- TEST aprobación RRHH:
-- EXEC dbo.SP_INTRANET_APROBAR_VAC 'BDV0004','0001','000101',1,'APROBAR_RRHH',NULL,'2025/2026';
