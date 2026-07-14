-- ============================================================================
-- STORED PROCEDURE: SP_INTRANET_GET_TRABAJADOR
-- BASE DE DATOS:    Cada BD del ERP (BDV0004, BDV0004_PRUEBA, etc.)
-- PATRÓN:          B — el SP vive en la BD de la empresa; Laravel lo llama
--                  como EXEC [db_name].dbo.SP_INTRANET_GET_TRABAJADOR
-- DESCRIPCIÓN:     Retorna datos completos del trabajador activo.
-- ============================================================================
-- Ejecutar en cada BD del ERP donde existan empleados.
-- USE BDV0004_PRUEBA;
-- GO

IF OBJECT_ID('dbo.SP_INTRANET_GET_TRABAJADOR', 'P') IS NOT NULL
    DROP PROCEDURE dbo.SP_INTRANET_GET_TRABAJADOR;
GO

CREATE PROCEDURE dbo.SP_INTRANET_GET_TRABAJADOR
    @cod_personal VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        p.COD_PERSONAL,
        p.NOM_TRABAJADOR,
        p.APE_PATERNO,
        p.APE_MATERNO,
        A.NUM_DOC_IDENTIDAD,
        A.TIP_DOC_IDENTIDAD,
        A.NUM_TELEFONO,
        C.DES_CARGO,
        MA.DES_AREAS,
        PC.DES_CATEGORIA,
        TP.DES_TIPO_PLANILLA,
        P.FEC_INGRESO,
        PP.DES_PROFESION,
        PZ.DES_ZONA
    FROM      dbo.PLA_PERSONAL      p
    LEFT JOIN dbo.MAE_AUXILIAR      A   ON  A.COD_AUXILIAR       = p.COD_AUXILIAR
    LEFT JOIN dbo.MAE_AREAS         MA  ON  MA.COD_AREAS         = p.COD_AREAS
    LEFT JOIN dbo.PLA_CATEGORIAS    PC  ON  PC.COD_CATEGORIA     = p.COD_CATEGORIA
    LEFT JOIN dbo.PLA_CARGOS        C   ON  C.COD_CARGO          = p.COD_CARGO
                                        AND C.COD_CATEGORIA      = p.COD_CATEGORIA
    LEFT JOIN dbo.PLA_TIPO_PLANILLA TP  ON  TP.COD_TIPO_PLANILLA = p.COD_TIPO_PLANILLA
    LEFT JOIN dbo.PLA_PROFESIONES   PP  ON  PP.COD_PROFESION     = p.COD_PROFESION
    LEFT JOIN dbo.PLA_ZONAS         PZ  ON  PZ.COD_ZONA          = p.COD_ZONA
    WHERE  p.COD_PERSONAL = @cod_personal
      AND  p.TIP_ESTADO   = 'AC';
END;
GO

-- TEST (ejecutar en la misma BD del ERP):
-- EXEC dbo.SP_INTRANET_GET_TRABAJADOR @cod_personal = '000101';
