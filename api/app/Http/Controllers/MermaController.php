<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OrdenProduccion;
use App\Models\Papel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Merma: la diferencia entre el papel gastado y el producto entregado.
 *
 * ESTA ES LA PANTALLA QUE RESPONDE DONDE SE FUE LA PLATA.
 *
 * Por eso separa lo previsto de lo real: el porcentaje del catalogo dice lo
 * que la imprenta ESPERA perder; las ordenes de produccion dicen lo que
 * REALMENTE se perdio. Cuando los dos se separan, el porcentaje del catalogo
 * esta viejo, y eso se corrige acá.
 *
 * La diferencia entre ambos se compara en puntos porcentuales, y el umbral
 * es configurable (`imprenta.merma.aviso_puntos_porcentuales`). Es un aviso,
 * no un bloqueo: el taller tiene que poder seguir imprimiendo aunque una
 * tirada salga mala.
 */
class MermaController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', OrdenProduccion::class);

        $umbral = (int) config('imprenta.merma.aviso_puntos_porcentuales', 5);
        $desde = $request->filled('desde') ? (string) $request->input('desde') : null;

        $consulta = OrdenProduccion::query()
            ->with(['item:id,descripcion', 'papel:id,codigo,nombre,tipo,merma_prueba_porcentaje,merma_plancha_porcentaje'])
            ->when($desde !== null, fn ($c) => $c->whereDate('created_at', '>=', $desde));

        $filas = $consulta->get();

        // Acumulado por papel, que es donde se decide el porcentaje.
        $porPapel = [];

        foreach ($filas as $orden) {
            $clave = $orden->papel_id;

            if (! isset($porPapel[$clave])) {
                $porPapel[$clave] = [
                    'papel' => $orden->papel?->nombre,
                    'codigo' => $orden->papel?->codigo,
                    'merma_prevista_porcentaje' => $this->prevista($orden->papel),
                    'pliegos_netos' => 0,
                    'pliegos_prueba' => 0,
                    'pliegos_merma' => 0,
                    'pliegos_totales' => 0,
                    'producto_bueno' => 0,
                    'ordenes' => 0,
                ];
            }

            $porPapel[$clave]['pliegos_netos'] += $orden->pliegos_netos;
            $porPapel[$clave]['pliegos_prueba'] += $orden->pliegos_prueba;
            $porPapel[$clave]['pliegos_merma'] += $orden->pliegos_merma;
            $porPapel[$clave]['pliegos_totales'] += $orden->pliegos_totales;
            $porPapel[$clave]['producto_bueno'] += $orden->producto_bueno;
            $porPapel[$clave]['ordenes'] += 1;
        }

        $resumen = [];

        foreach ($porPapel as $fila) {
            $perdidos = $fila['pliegos_prueba'] + $fila['pliegos_merma'];
            $netos = $fila['pliegos_netos'];

            $real = $netos > 0 ? round(($perdidos / $netos) * 100.0, 2) : null;
            $prevista = $fila['merma_prevista_porcentaje'];

            // Con netos en cero no se puede calcular un porcentaje real, y se
            // devuelve `null`: afirmar 0 % seria decir que no se perdio nada.
            $desvio = ($real !== null && $prevista !== null)
                ? round($real - $prevista, 2)
                : null;

            $resumen[] = [
                ...$fila,
                'merma_real_porcentaje' => $real,
                'desvio_puntos' => $desvio,
                'requiere_revision' => $desvio !== null && abs($desvio) > $umbral,
            ];
        }

        usort($resumen, static fn (array $a, array $b): int =>
            ($b['pliegos_totales'] <=> $a['pliegos_totales']));

        return response()->json([
            'umbral_aviso_puntos' => $umbral,
            'desde' => $desde,
            'resumen' => $resumen,
            'total_ordenes' => $filas->count(),
        ]);
    }

    /**
     * Merma prevista del papel: la suma de sus dos porcentajes.
     *
     * Devuelve `null` si falta alguno, porque una prevista a medias seria
     * exactamente el supuesto que este sistema no quiere hacer.
     */
    private function prevista(?Papel $papel): ?float
    {
        if ($papel === null
            || $papel->merma_prueba_porcentaje === null
            || $papel->merma_plancha_porcentaje === null
        ) {
            return null;
        }

        return round(
            (float) $papel->merma_prueba_porcentaje + (float) $papel->merma_plancha_porcentaje,
            2
        );
    }
}
