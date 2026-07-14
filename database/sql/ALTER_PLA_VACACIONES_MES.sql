-- ============================================================================
-- ALTER_PLA_VACACIONES_MES.sql
-- Agrega las columnas que necesita la intranet en PLA_VACACIONES_MES.
-- Ejecutar en cada BD del ERP donde la tabla ya existe pero le faltan columnas.
-- Es seguro: cada ALTER solo se ejecuta si la columna no existe.
--
-- USO: Cambiar USE por la BD destino y ejecutar.
-- ============================================================================

USE BDV0004;   -- ← CAMBIAR POR LA BD DESTINO
GO

-- ── ESTADO_APROBACION ────────────────────────────────────────────────────────
-- PE=Pendiente | AJ=Aprobado Jefe | RJ=Rechazado Jefe
-- AR=Aprobado RRHH | RR=Rechazado RRHH | CA=Cancelado
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'ESTADO_APROBACION'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD ESTADO_APROBACION CHAR(2) NOT NULL DEFAULT 'PE';
    PRINT '[OK] ESTADO_APROBACION agregada.';
END
ELSE
    PRINT '[--] ESTADO_APROBACION ya existe.';
GO

-- ── FEC_APROBACION_JEFE ───────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'FEC_APROBACION_JEFE'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD FEC_APROBACION_JEFE DATETIME NULL;
    PRINT '[OK] FEC_APROBACION_JEFE agregada.';
END
ELSE
    PRINT '[--] FEC_APROBACION_JEFE ya existe.';
GO

-- ── FEC_APROBACION_RRHH ───────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'FEC_APROBACION_RRHH'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD FEC_APROBACION_RRHH DATETIME NULL;
    PRINT '[OK] FEC_APROBACION_RRHH agregada.';
END
ELSE
    PRINT '[--] FEC_APROBACION_RRHH ya existe.';
GO

-- ── PERIODO_VACACIONAL ────────────────────────────────────────────────────────
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('dbo.PLA_VACACIONES_MES') AND name = 'PERIODO_VACACIONAL'
)
BEGIN
    ALTER TABLE dbo.PLA_VACACIONES_MES
        ADD PERIODO_VACACIONAL NVARCHAR(100) NULL;
    PRINT '[OK] PERIODO_VACACIONAL agregada.';
END
ELSE
    PRINT '[--] PERIODO_VACACIONAL ya existe.';
GO

-- ── Verificar estructura final ────────────────────────────────────────────────
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME  = 'PLA_VACACIONES_MES'
  AND TABLE_SCHEMA = 'dbo'
ORDER BY ORDINAL_POSITION;
GO
