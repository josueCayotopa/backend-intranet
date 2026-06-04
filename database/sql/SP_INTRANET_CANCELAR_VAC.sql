-- ============================================================================
-- SP_INTRANET_CANCELAR_VAC  — ESCRITURA
-- El empleado cancela su propia solicitud (solo si PE o AJ).
-- ============================================================================
USE INTRANETCLL;
GO

IF OBJECT_ID('dbo.SP_INTRANET_CANCELAR_VAC','P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_CANCELAR_VAC;
GO

CREATE PROCEDURE dbo.SP_INTRANET_CANCELAR_VAC
    @db_name      VARCHAR(50),
    @cod_empresa  VARCHAR(4),
    @cod_personal VARCHAR(20),
    @cod_corr_vac INT
AS
BEGIN
    SET NOCOUNT ON;

    IF @db_name LIKE '%[^A-Za-z0-9_]%'
    BEGIN RAISERROR('@db_name inválido.',16,1); RETURN; END

    -- Verificar que sea cancelable (PE o AJ)
    CREATE TABLE #Check (estado CHAR(2));

    DECLARE @chk NVARCHAR(MAX) =
        N'INSERT INTO #Check
          SELECT ESTADO_APROBACION
          FROM   [' + @db_name + N'].dbo.PLA_VACACIONES_MES
          WHERE  COD_EMPRESA  = @ce
            AND  COD_PERSONAL = @cp
            AND  COD_CORR_VAC = @corr
            AND  ESTADO_APROBACION IN (''PE'',''AJ'')';

    EXEC sp_executesql @chk,
        N'@ce VARCHAR(4), @cp VARCHAR(20), @corr INT',
        @ce = @cod_empresa, @cp = @cod_personal, @corr = @cod_corr_vac;

    IF NOT EXISTS (SELECT 1 FROM #Check)
    BEGIN
        DROP TABLE #Check;
        RAISERROR('La solicitud no se puede cancelar en su estado actual.',16,1);
        RETURN;
    END
    DROP TABLE #Check;

    -- Cancelar
    DECLARE @upd NVARCHAR(MAX) =
        N'UPDATE [' + @db_name + N'].dbo.PLA_VACACIONES_MES
          SET    ESTADO_APROBACION = ''CA''
          WHERE  COD_EMPRESA  = @ce
            AND  COD_PERSONAL = @cp
            AND  COD_CORR_VAC = @corr';

    EXEC sp_executesql @upd,
        N'@ce VARCHAR(4), @cp VARCHAR(20), @corr INT',
        @ce = @cod_empresa, @cp = @cod_personal, @corr = @cod_corr_vac;

    SELECT @@ROWCOUNT AS FILAS_AFECTADAS;
END;
GO

-- TEST:
-- EXEC dbo.SP_INTRANET_CANCELAR_VAC 'BDV0004','0001','000101',1;
