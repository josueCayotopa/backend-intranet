-- ============================================================================
-- SP_INTRANET_CANCELAR_VAC  — ESCRITURA
-- BASE DE DATOS:  Cada BD del ERP (BDV0004, BDV0004_PRUEBA, etc.)
-- PATRÓN:        B — el SP vive en la BD de la empresa; Laravel lo llama
--                como EXEC [db_name].dbo.SP_INTRANET_CANCELAR_VAC
-- Cancela un registro en PLA_VACACIONES_MES (solo si ESTADO_APROBACION = PE o AJ).
-- ============================================================================
-- USE BDV0004;  -- cambiar por la BD destino
-- GO

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

    -- Verificar que sea cancelable
    IF NOT EXISTS (
        SELECT 1
        FROM   dbo.PLA_VACACIONES_MES
        WHERE  COD_EMPRESA       = @cod_empresa
          AND  COD_PERSONAL      = @cod_personal
          AND  COD_CORR_VAC      = @cod_corr_vac
          AND  ESTADO_APROBACION IN ('PE', 'AJ')
    )
    BEGIN
        RAISERROR('La solicitud no se puede cancelar en su estado actual.', 16, 1);
        RETURN;
    END

    UPDATE dbo.PLA_VACACIONES_MES
    SET    ESTADO_APROBACION = 'CA'
    WHERE  COD_EMPRESA       = @cod_empresa
      AND  COD_PERSONAL      = @cod_personal
      AND  COD_CORR_VAC      = @cod_corr_vac;

    SELECT @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO

-- TEST (ejecutar en la misma BD del ERP):
-- EXEC dbo.SP_INTRANET_CANCELAR_VAC @cod_empresa = '0001', @cod_personal = '000101', @cod_corr_vac = 1;
