-- ============================================================================
-- DIAGNÓSTICO: Verificar datos que envía Laravel a USP_CREAR_SOL_VACACIONES
-- PASO 1: Ejecutar este bloque en SSMS (contexto: BDV0004)
-- PASO 2: Si los datos se ven correctos, crear la tabla PLA_SOL_VACACIONES
--         y luego ejecutar el SP real.
-- ============================================================================

-- Cambiar a la BD del ERP donde vive el SP
USE BDV0004;
GO

-- ============================================================================
-- PASO 1 — SP DE PRUEBA: mismos parámetros, sin tocar ninguna tabla
-- Ejecuta este bloque para crear el SP de diagnóstico.
-- ============================================================================
IF OBJECT_ID('dbo.USP_TEST_SOL_VACACIONES', 'P') IS NOT NULL
    DROP PROCEDURE dbo.USP_TEST_SOL_VACACIONES;
GO

CREATE PROCEDURE dbo.USP_TEST_SOL_VACACIONES
    @COD_PERSONAL       VARCHAR(20),
    @COD_EMPRESA        VARCHAR(10),
    @TIP_VACACIONES     VARCHAR(5),
    @ANO_PROCESO        INT,
    @MES_PROCESO        INT,
    @FEC_INICIO         DATE,
    @FEC_FINAL          DATE,
    @COD_USER_ACTUAL    VARCHAR(20),
    @COD_PERSONAL_JEFE  VARCHAR(20)  = NULL,
    @IMP_ADELANTO_VACAC DECIMAL(18,2)= NULL,
    @DESCUENTO_AFP      DECIMAL(18,2)= NULL,
    @PERIODO_VAC        VARCHAR(20)  = NULL,
    @COD_CORR_SOL       VARCHAR(5)   OUTPUT,
    @RESULTADO          INT          OUTPUT,
    @MENSAJE            VARCHAR(500) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    -- Solo imprime los datos recibidos para verificar que Laravel envía bien
    SELECT
        'DIAGNÓSTICO — Datos recibidos por el SP' AS TIPO,
        @COD_PERSONAL       AS COD_PERSONAL,
        @COD_EMPRESA        AS COD_EMPRESA,
        @TIP_VACACIONES     AS TIP_VACACIONES,
        @ANO_PROCESO        AS ANO_PROCESO,
        @MES_PROCESO        AS MES_PROCESO,
        @FEC_INICIO         AS FEC_INICIO,
        @FEC_FINAL          AS FEC_FINAL,
        DATEDIFF(DAY, @FEC_INICIO, @FEC_FINAL) + 1 AS NUM_DIAS_CALCULADO,
        @COD_USER_ACTUAL    AS COD_USER_ACTUAL,
        @COD_PERSONAL_JEFE  AS COD_PERSONAL_JEFE,
        @IMP_ADELANTO_VACAC AS IMP_ADELANTO_VACAC,
        @DESCUENTO_AFP      AS DESCUENTO_AFP,
        @PERIODO_VAC        AS PERIODO_VAC;

    -- Simula respuesta exitosa sin escribir nada
    SET @COD_CORR_SOL = '01';
    SET @RESULTADO    = 0;
    SET @MENSAJE      = 'SP de prueba OK — datos recibidos correctamente.';
END;
GO

-- ============================================================================
-- PASO 2 — CAMBIAR LA LLAMADA EN LARAVEL TEMPORALMENTE
-- En ErpService.php, línea 259, cambiar:
--   EXEC [{$dbName}].dbo.USP_CREAR_SOL_VACACIONES
-- por:
--   EXEC [{$dbName}].dbo.USP_TEST_SOL_VACACIONES
-- Hacer la solicitud desde el frontend y revisar los logs de Laravel
-- (storage/logs/laravel.log) o la respuesta de la API.
-- ============================================================================

-- ============================================================================
-- PASO 3 — TEST DIRECTO EN SSMS
-- Ejecuta este bloque exacto con los mismos valores que usa el usuario admin:
-- ============================================================================

DECLARE @sol VARCHAR(5), @res INT, @msg VARCHAR(500);

EXEC BDV0004.dbo.USP_TEST_SOL_VACACIONES
    @COD_PERSONAL       = '000101',
    @COD_EMPRESA        = '0001',
    @TIP_VACACIONES     = 'VG',
    @ANO_PROCESO        = 2026,
    @MES_PROCESO        = 7,
    @FEC_INICIO         = '2026-07-13',
    @FEC_FINAL          = '2026-07-31',
    @COD_USER_ACTUAL    = 'admin',
    @COD_PERSONAL_JEFE  = NULL,
    @IMP_ADELANTO_VACAC = NULL,
    @DESCUENTO_AFP      = NULL,
    @PERIODO_VAC        = NULL,
    @COD_CORR_SOL       = @sol OUTPUT,
    @RESULTADO          = @res OUTPUT,
    @MENSAJE            = @msg OUTPUT;

SELECT
    @sol AS cod_corr_sol,
    @res AS resultado,
    @msg AS mensaje;
GO
