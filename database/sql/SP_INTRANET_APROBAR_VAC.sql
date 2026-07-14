-- ============================================================================
-- SP_INTRANET_APROBAR_VAC  — ESCRITURA
-- BASE DE DATOS:  Cada BD del ERP (BDV0004, BDV0004_PRUEBA, etc.)
-- PATRÓN:        B — el SP vive en la BD de la empresa; Laravel lo llama
--                como EXEC [db_name].dbo.SP_INTRANET_APROBAR_VAC
--
-- Gestiona las transiciones de estado en PLA_VACACIONES_MES:
--   PE  → [Jefe]  aprueba  → AJ
--   PE  → [Jefe]  rechaza  → RJ
--   AJ  → [RRHH]  aprueba  → AR  (registra PERIODO_VACACIONAL)
--   AJ  → [RRHH]  rechaza  → RR
--   PE o AJ → [Empleado] cancela → CA  (via SP_INTRANET_CANCELAR_VAC)
--
-- @accion:
--   'APROBAR_JEFE'  — jefe aprueba (PE → AJ)
--   'RECHAZAR_JEFE' — jefe rechaza (PE → RJ)
--   'APROBAR_RRHH'  — RRHH aprueba (AJ → AR)
--   'RECHAZAR_RRHH' — RRHH rechaza (AJ → RR)
-- ============================================================================
-- USE BDV0004;  -- cambiar por la BD destino
-- GO

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

    -- Leer estado actual
    DECLARE @estadoActual CHAR(2);

    SELECT @estadoActual = ESTADO_APROBACION
    FROM   dbo.PLA_VACACIONES_MES
    WHERE  COD_EMPRESA  = @cod_empresa
      AND  COD_PERSONAL = @cod_personal
      AND  COD_CORR_VAC = @cod_corr_vac;

    IF @estadoActual IS NULL
    BEGIN
        RAISERROR('Solicitud no encontrada.', 16, 1);
        RETURN;
    END

    -- Validar transición permitida
    IF @accion IN ('APROBAR_JEFE', 'RECHAZAR_JEFE') AND @estadoActual <> 'PE'
    BEGIN
        RAISERROR('Solo se puede aprobar/rechazar si la solicitud está Pendiente.', 16, 1);
        RETURN;
    END

    IF @accion IN ('APROBAR_RRHH', 'RECHAZAR_RRHH') AND @estadoActual <> 'AJ'
    BEGIN
        RAISERROR('RRHH solo puede actuar sobre solicitudes aprobadas por el Jefe.', 16, 1);
        RETURN;
    END

    -- Calcular nuevo estado
    DECLARE @nuevoEstado CHAR(2) =
        CASE @accion
            WHEN 'APROBAR_JEFE'  THEN 'AJ'
            WHEN 'RECHAZAR_JEFE' THEN 'RJ'
            WHEN 'APROBAR_RRHH'  THEN 'AR'
            WHEN 'RECHAZAR_RRHH' THEN 'RR'
        END;

    IF @nuevoEstado IS NULL
    BEGIN
        RAISERROR('Acción no reconocida.', 16, 1);
        RETURN;
    END

    -- Actualizar según tipo de aprobación
    IF @accion IN ('APROBAR_JEFE', 'RECHAZAR_JEFE')
    BEGIN
        UPDATE dbo.PLA_VACACIONES_MES
        SET    ESTADO_APROBACION    = @nuevoEstado,
               FEC_APROBACION_JEFE = GETDATE()
        WHERE  COD_EMPRESA  = @cod_empresa
          AND  COD_PERSONAL = @cod_personal
          AND  COD_CORR_VAC = @cod_corr_vac;
    END
    ELSE
    BEGIN
        UPDATE dbo.PLA_VACACIONES_MES
        SET    ESTADO_APROBACION   = @nuevoEstado,
               FEC_APROBACION_RRHH = GETDATE(),
               PERIODO_VACACIONAL  = @periodo_vac
        WHERE  COD_EMPRESA  = @cod_empresa
          AND  COD_PERSONAL = @cod_personal
          AND  COD_CORR_VAC = @cod_corr_vac;
    END

    SELECT @nuevoEstado AS NUEVO_ESTADO, @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO

-- TEST (ejecutar en la misma BD del ERP):
-- EXEC dbo.SP_INTRANET_APROBAR_VAC @cod_empresa='0001', @cod_personal='000101', @cod_corr_vac=1, @accion='APROBAR_JEFE';
-- EXEC dbo.SP_INTRANET_APROBAR_VAC @cod_empresa='0001', @cod_personal='000101', @cod_corr_vac=1, @accion='APROBAR_RRHH', @periodo_vac='2025/2026';
