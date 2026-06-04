-- ============================================================
-- CAMPOS NECESARIOS EN PLA_VACACIONES_MES
-- para el módulo de solicitudes de vacaciones (intranet)
-- Solo lo que el EMPLEADO crea y visualiza.
-- La lógica de aprobación la gestiona el otro sistema.
-- ============================================================

-- ── 1. CAMPOS QUE YA DEBEN EXISTIR (no tocar) ────────────────
--
--  COD_EMPRESA        VARCHAR(4)    NOT NULL   -- código de la empresa
--  COD_PERSONAL       VARCHAR(6)    NOT NULL   -- código del trabajador
--  COD_CORR_VAC       INT           NOT NULL   -- correlativo por trabajador
--  TIP_VACACIONES     CHAR(2)       NOT NULL   -- 'VG'=Gozadas | 'VC'=Compra
--  FEC_INICIO         DATE          NOT NULL   -- fecha de inicio
--  FEC_FINAL          DATE          NOT NULL   -- fecha de fin
--  NUM_DIAS           SMALLINT      NOT NULL   -- días solicitados
--  ANO_PROCESO        CHAR(4)       NOT NULL   -- año de la solicitud
--
-- ── 2. CAMPO QUE DEBES AGREGAR (si no existe) ────────────────
--
--  ESTADO_APROBACION  CHAR(2)  DEFAULT 'PE'
--
--     PE = Pendiente          ← intranet escribe esto al crear
--     AJ = Aprobado Jefe      ← otro sistema
--     RJ = Rechazado Jefe     ← otro sistema
--     AR = Aprobado RRHH      ← otro sistema
--     RR = Rechazado RRHH     ← otro sistema
--     CA = Cancelado          ← intranet escribe esto al cancelar
-- ============================================================

-- Agrega el campo solo si no existe:
IF NOT EXISTS (
    SELECT 1 FROM sys.columns
    WHERE object_id = OBJECT_ID('BDV0004.dbo.PLA_VACACIONES_MES')
      AND name = 'ESTADO_APROBACION'
)
BEGIN
    ALTER TABLE BDV0004.dbo.PLA_VACACIONES_MES
    ADD ESTADO_APROBACION CHAR(2) NOT NULL DEFAULT 'PE';

    PRINT 'Columna ESTADO_APROBACION agregada correctamente.';
END
ELSE
BEGIN
    PRINT 'ESTADO_APROBACION ya existe, no se realizaron cambios.';
END

-- ── Verificar estructura final ────────────────────────────────
SELECT
    COLUMN_NAME,
    DATA_TYPE,
    CHARACTER_MAXIMUM_LENGTH,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM BDV0004.INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME = 'PLA_VACACIONES_MES'
ORDER BY ORDINAL_POSITION;
