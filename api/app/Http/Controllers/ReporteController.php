<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\Prueba;
use App\Services\Entregas\ServicioDeEntregas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Reportes: el estado de la imprenta en una pantalla.
 *
 * NO CALCULA PRECIOS.
 *
 * La aplicacion calcula PAPEL: cuanto se gasta, cuanta merma hay, cuanto
 * falta por entregar. No calcula cuanto se cobra, porque el precio lo pone
 * la imprenta y un reporte con precios inventados seria peor que uno sin
 * precios.
 */
class ReporteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', Pedido::class);

        $servicioEntregas = app(ServicioDeEntregas::class);
        $dias = (int) config('imprenta.pruebas.dias_para_vencer', 5);

        $pedidos = Pedido::query()->with('cliente:id,nombre')->get();

        // Piezas en cola: pedidos aprobados o en produccion que aun no
        // salieron completas.
        $enCola = $pedidos->filter(
            static fn (Pedido $p): bool => in_array(
                $p->estado,
                [EstadoPedido::APROBADO, EstadoPedido::EN_PRODUCCION, EstadoPedido::PARCIALMENTE_ENTREGADO],
                true
            )
        );

        // Piezas prometidas y no entregadas.
        $piezasPrometidas = 0;
        $piezasEntregadas = 0;

        foreach ($enCola as $pedido) {
            $piezasPrometidas += $pedido->cantidadPedida();
            $piezasEntregadas += $pedido->cantidadEntregada();
        }

        // Pedidos cuyo estado guardado no cuadra con la suma de entregas.
        $descuadrados = [];

        foreach ($pedidos as $pedido) {
            $comparacion = $servicioEntregas->estadoEsperado($pedido);

            if ($comparacion['estado_guardado'] !== $comparacion['estado_esperado']) {
                $descuadrados[] = $comparacion;
            }
        }

        return response()->json([
            'pedidos_por_estado' => $pedidos
                ->groupBy(static fn (Pedido $p): string => $p->estado->value)
                ->map(static fn ($grupo): int => $grupo->count()),
            'en_cola' => [
                'pedidos' => $enCola->count(),
                'piezas_prometidas' => $piezasPrometidas,
                'piezas_entregadas' => $piezasEntregadas,
                'piezas_saldo' => max(0, $piezasPrometidas - $piezasEntregadas),
            ],
            'aprobaciones' => [
                'pendientes_de_respuesta' => Prueba::query()
                    ->where('estado', 'enviada')
                    ->count(),
                'vencidas' => Prueba::query()
                    ->where('estado', 'enviada')
                    ->whereNotNull('enviada_en')
                    ->where('enviada_en', '<=', now()->subDays($dias))
                    ->count(),
                'dias_para_vencer' => $dias,
            ],
            'proximo_vencimiento' => $enCola
                ->sortBy('fecha_prometida')
                ->take(10)
                ->values()
                ->map(static fn (Pedido $p): array => [
                    'numero' => $p->numero,
                    'cliente' => $p->cliente?->nombre,
                    'fecha_prometida' => $p->fecha_prometida?->toDateString(),
                    'saldo' => $p->saldo(),
                ]),
            'estados_descuadrados' => $descuadrados,
            'momento' => now()->toIso8601String(),
        ]);
    }
}
