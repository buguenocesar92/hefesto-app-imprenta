<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoPedido;
use App\Http\Requests\RegistrarEntregaRequest;
use App\Models\Entrega;
use App\Models\Pedido;
use App\Services\Entregas\ServicioDeEntregas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/**
 * Entregas: las salidas parciales y totales.
 *
 * LA RESPUESTA SIEMPRE TRAE EL SALDO Y EL ESTADO RECALCULADO.
 *
 * No alcanza con decir "registre": el mostrador tiene que ver, en la misma
 * pantalla, cuanto queda y en que estado quedo el pedido. Asi una entrega
 * parcial no se confunde con el cierre de un pedido.
 */
class EntregaController extends Controller
{
    public function __construct(private readonly ServicioDeEntregas $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', Entrega::class);

        $entregas = Entrega::query()
            ->with(['pedido:id,numero', 'usuario'])
            ->orderByDesc('entregado_en')
            ->paginate(50);

        return response()->json($entregas);
    }

    /**
     * Registra una entrega y recalcula el estado del pedido.
     *
     * Si la cantidad supera el saldo, el servicio lanza y esto devuelve 422
     * con `entrega_supera_saldo`, diciendo cuantas quedaban.
     */
    public function registrar(RegistrarEntregaRequest $request, string $pedido): JsonResponse
    {
        $modelo = Pedido::query()->with('items')->findOrFail($pedido);

        $this->authorize($request, 'registrarEntrega', $modelo);

        $entrega = $this->servicio->registrar(
            pedido: $modelo,
            usuario: $request->user(),
            cantidad: (int) $request->input('cantidad_unidades'),
            medio: (string) $request->input('medio'),
            entregadoPor: $request->input('entregado_por'),
            fecha: $request->filled('entregado_en')
                ? Carbon::parse((string) $request->input('entregado_en'))
                : null,
            notas: $request->input('notas'),
        );

        $modelo->refresh();

        $cerrado = $modelo->estado === EstadoPedido::ENTREGADO;

        return response()->json([
            'mensaje' => $cerrado
                ? 'Entrega registrada. El pedido quedo entregado.'
                : 'Entrega parcial registrada. Quedan piezas por entregar.',
            'entrega' => [
                'id' => $entrega->id,
                'cantidad_unidades' => $entrega->cantidad_unidades,
                'medio' => $entrega->medio,
                'entregado_en' => $entrega->entregado_en?->toDateString(),
            ],
            'pedido' => [
                'numero' => $modelo->numero,
                'estado' => $modelo->estado->value,
                'cantidad_pedida' => $modelo->cantidadPedida(),
                'cantidad_entregada' => $modelo->cantidadEntregada(),
                'saldo' => $modelo->saldo(),
                'cerrado' => $cerrado,
            ],
        ], 201);
    }

    /**
     * Pedidos con saldo pendiente.
     *
     * Es la pantalla del mostrador: a quien hay que volver a llamar. Un
     * pedido con entrega parcial aparece aca, y uno entregado no.
     */
    public function pendientes(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', Entrega::class);

        $pedidos = Pedido::query()
            ->with('cliente:id,nombre')
            ->whereIn('estado', [
                EstadoPedido::APROBADO->value,
                EstadoPedido::EN_PRODUCCION->value,
                EstadoPedido::PARCIALMENTE_ENTREGADO->value,
            ])
            ->get()
            ->map(static fn (Pedido $p): array => [
                'id' => $p->id,
                'numero' => $p->numero,
                'cliente' => $p->cliente?->nombre,
                'estado' => $p->estado->value,
                'fecha_prometida' => $p->fecha_prometida?->toDateString(),
                'cantidad_pedida' => $p->cantidadPedida(),
                'cantidad_entregada' => $p->cantidadEntregada(),
                'saldo' => $p->saldo(),
            ])
            ->filter(static fn (array $fila): bool => $fila['saldo'] > 0)
            ->values();

        return response()->json(['pendientes' => $pedidos]);
    }
}
