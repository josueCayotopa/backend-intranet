-- ============================================================================
-- CREAR TABLA PLA_SOL_VACACIONES
-- Ejecutar en SSMS contra la BD del ERP del usuario (BDV0004_PRUEBA, BDV0004, etc.)
-- ============================================================================

USE BDV0004_PRUEBA;   -- ← cambiar por la BD destino según empresa
GO

-- ── 1. Verificar que el SP USP_CREAR_SOL_VACACIONES existe ────────────────
SELECT
    OBJECT_NAME(object_id) AS sp_name,
    create_date,
    modify_date
FROM sys.objects
WHERE object_id = OBJECT_ID(N'dbo.USP_CREAR_SOL_VACACIONES')
  AND type = 'P';
-- Si no devuelve filas, hay que crear o copiar el SP desde BDV0004 a esta BD.
GO

    -- ── 2. Crear tabla PLA_SOL_VACACIONES si no existe ────────────────────────
    IF NOT EXISTS (
        SELECT 1 FROM sys.objects
        WHERE object_id = OBJECT_ID(N'dbo.PLA_SOL_VACACIONES')
        AND type = 'U'
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

        PRINT 'Tabla PLA_SOL_VACACIONES creada correctamente.';
    END
    ELSE
    BEGIN
        PRINT 'La tabla PLA_SOL_VACACIONES ya existe.';
    END
    GO

    -- ── 3. Verificar estructura ────────────────────────────────────────────────
    SELECT
        COLUMN_NAME,
        DATA_TYPE,
        CHARACTER_MAXIMUM_LENGTH,
        IS_NULLABLE,
        COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_NAME   = 'PLA_SOL_VACACIONES'
    AND TABLE_SCHEMA = 'dbo'
    ORDER BY ORDINAL_POSITION;
    GO
