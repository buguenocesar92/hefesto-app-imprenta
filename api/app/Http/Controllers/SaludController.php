<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprobacion de salud.
 *
 * ESTA RUTA NO USA RUIDO DE SALUD, Y NO ES POR SIMETRIA.
 *
 * Un chequeo que dice "estoy bien" cuando la base no responde sirve para que
 * el orquestador deje de reiniciar el contenedor mientras la aplicacion no
 * funciona. El healthcheck tiene que distinguir "el proceso vive" de "el
 * sistema sirve", porque son dos cosas distintas y confundirlas deja el
 * sistema caido con la luz en verde.
 *
 * La ruta es publica a proposito: el orquestador no tiene sesion. Por eso no
 * toca ninguna tabla protegida y por eso no necesita `app.tenant_id`.
 */
class SaludController extends Controller
{
    public function __invoke(ContextoTenant $contexto): JsonResponse
    {
        $base = false;
        $rlsActiva = false;
        $detalle = null;

        try {
            // Consulta minima. No toca ninguna tabla con RLS.
            $base = (bool) DB::selectOne('SELECT 1 AS vivo')?->vivo;

            // Que la RLS este habilitada en todas las tablas de la empresa.
            $rlsActiva = $this->rlsCubriendoEmpresas();

            $contexto->verificarSinEscapeDeRls();
        } catch (Throwable $e) {
            $detalle = $e->getMessage();
        }

        $sano = $base && $rlsActiva && $detalle === null;

        return response()->json([
            'estado' => $sano ? 'ok' : 'degradado',
            'base_de_datos' => $base,
            'aislamiento_activo' => $rlsActiva,
            'detalle' => $detalle,
            'momento' => now()->toIso8601String(),
        ], $sano ? 200 : 503);
    }

    /**
     * Comprueba que toda tabla de empresa tenga RLS habilitada Y forzada.
     *
     * Una tabla con `ENABLE` pero sin `FORCE` deja pasar a quien es duena de
     * la tabla, que es el rol con que corren las migraciones. Por eso se
     * comprueban las dos cosas.
     */
    private function rlsCubriendoEmpresas(): bool
    {
        $esperadas = [
            'usuarios', 'planchas', 'papeles', 'clientes', 'pedidos', 'pedido_items',
            'artes', 'pruebas', 'ordenes_produccion', 'entregas',
            'articulos_libreria', 'movimientos_libreria',
        ];

        $filas = DB::select(
            "SELECT c.relname AS tabla, c.relrowsecurity AS habilitada, c.relforcerowsecurity AS forzada
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public'
                AND c.relname = ANY(?)",
            ['{' . implode(',', $esperadas) . '}']
        );

        if (count($filas) !== count($esperadas)) {
            return false;
        }

        foreach ($filas as $fila) {
            if (! (bool) $fila->habilitada || ! (bool) $fila->forzada) {
                return false;
            }
        }

        return true;
    }
}
