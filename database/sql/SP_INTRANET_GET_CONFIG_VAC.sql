-- SP_INTRANET_GET_CONFIG_VAC
-- Devuelve la configuración de vacaciones para un trabajador:
--   IND_HABILITA_VACACIONES : S/N  – si el trabajador puede solicitar vacaciones
--   LIMITE_DIAS_VAC_ANIO    : INT  – límite de días por año (NULL = sin límite)
--   DIAS_USADOS_ANIO        : INT  – días ya usados/solicitados en el año indicado
--                                    (excluye estados RJ, RR, CA)
--
-- Requiere columnas en PLA_PERSONAL:
--   IND_HABILITA_VACACIONES CHAR(1) NOT NULL DEFAULT 'N'
--   LIMITE_DIAS_VAC_ANIO    INT     NULL

IF OBJECT_ID('dbo.SP_INTRANET_GET_CONFIG_VAC', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_CONFIG_VAC;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_CONFIG_VAC
    @cod_personal VARCHAR(20),
    @ano          INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF @ano IS NULL SET @ano = YEAR(GETDATE());

    SELECT
        ISNULL(p.IND_HABILITA_VACACIONES, 'N')    AS IND_HABILITA_VACACIONES,
        p.LIMITE_DIAS_VAC_ANIO,
        ISNULL((
            SELECT SUM(sv.NUM_DIAS)
            FROM   dbo.PLA_SOL_VACACIONES sv
            WHERE  sv.COD_PERSONAL        = p.COD_PERSONAL
              AND  sv.ANO_PROCESO         = @ano
              AND  sv.ESTADO_APROBACION NOT IN ('RJ', 'RR', 'CA')
        ), 0)                                       AS DIAS_USADOS_ANIO
    FROM dbo.PLA_PERSONAL p
    WHERE p.COD_PERSONAL = @cod_personal;
END;
GO
