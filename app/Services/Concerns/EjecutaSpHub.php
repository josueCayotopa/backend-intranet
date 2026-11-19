<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Ejecuta SP_RRHH_* del hub (INTRANETCLL) devolviendo todos sus result sets.
 * Los THROW 50000+HTTP del SP llegan como RuntimeException con ese código HTTP.
 */
trait EjecutaSpHub
{
    /** @return array<int, array<int, array<string, mixed>>> */
    protected function ejecutar(string $llamada, array $params): array
    {
        $pdo = DB::connection()->getPdo();

        try {
            $stmt = $pdo->prepare('SET NOCOUNT ON; EXEC dbo.' . $llamada);
            foreach (array_values($params) as $i => $v) {
                $stmt->bindValue($i + 1, $v, $v === null ? PDO::PARAM_NULL : (is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR));
            }
            $stmt->execute();

            $resultados = [];
            do {
                if ($stmt->columnCount() > 0) {
                    $resultados[] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } while ($stmt->nextRowset());

            return $resultados;
        } catch (PDOException $e) {
            $numero = (int) ($e->errorInfo[1] ?? 0);
            if ($numero >= 50000 && $numero < 51000) {
                $mensaje = preg_replace('/^(\[[^\]]*\])+\s*/', '', (string) ($e->errorInfo[2] ?? $e->getMessage()));
                throw new RuntimeException(trim($mensaje), $numero - 50000, $e);
            }
            throw $e;
        }
    }

    /** Columnas en minúsculas, textos sin espacios finales. */
    protected function filas(array $filas): array
    {
        return array_map(function ($f) {
            $salida = [];
            foreach ($f as $k => $v) {
                $salida[strtolower($k)] = is_string($v) ? rtrim($v) : $v;
            }
            return $salida;
        }, $filas);
    }
}
