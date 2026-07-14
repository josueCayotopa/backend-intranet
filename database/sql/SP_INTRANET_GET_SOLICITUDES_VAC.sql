-- ============================================================================
-- SP_INTRANET_GET_SOLICITUDES_VAC  — LECTURA
-- BASE DE DATOS:  Cada BD del ERP (BDV0004, BDV0004_PRUEBA, etc.)
-- PATRÓN:        B — el SP vive en la BD de la empresa; Laravel lo llama
--                como EXEC [db_name].dbo.SP_INTRANET_GET_SOLICITUDES_VAC
-- Lee las solicitudes de vacaciones generadas desde la intranet.
-- Tabla: PLA_SOL_VACACIONES
-- Estados: PE=Pendiente | AJ=Aprobado Jefe | RJ=Rechazado Jefe
--          AR=Aprobado RRHH | RR=Rechazado RRHH | CA=Cancelado
-- ============================================================================
-- USE BDV0004;  -- cambiar por la BD destino
-- GO

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

-- TEST (ejecutar en la misma BD del ERP):
-- EXEC dbo.SP_INTRANET_GET_SOLICITUDES_VAC @cod_personal = '000101';
