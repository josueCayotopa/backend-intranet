-- ============================================================
-- SCRIPT: Marcar todas las vacaciones como Aprobadas por RRHH
-- TABLA:  BDV0004.dbo.PLA_VACACIONES_MES
-- USO:    Solo para pruebas / carga inicial de datos
-- ============================================================

UPDATE BDV0004.dbo.PLA_VACACIONES_MES
SET    ESTADO_APROBACION   = 'AR',
      
WHERE  ESTADO_APROBACION IS NULL
    OR ESTADO_APROBACION NOT IN ('CA');   -- deja canceladas como están

-- Verificar resultado
SELECT
    ESTADO_APROBACION,
    COUNT(*) AS CANTIDAD
FROM BDV0004.dbo.PLA_VACACIONES_MES
GROUP BY ESTADO_APROBACION
ORDER BY ESTADO_APROBACION;


