<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Services\Pedidos\ServicioDeAprobacion;
use App\Services\Produccion\ServicioDeProduccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pedidos: el corazon del negocio.
 *
 * Cada respuesta trae, ademas de los datos, SI SE PUEDE IMPRIMIR y por que
 * no. El taller no deberia tener que abrir tres pantallas para averiguar si
 * un pedido puede subir a la plancha.
 */
class PedidoController extends Controller
{
    public function __construct(
        private readonly ServicioDeAprobacion $aprobacion,
        private readonly ServicioDeProduccion $produccion,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $pedidos = Pedido::query()
            ->with(['cliente:id,nombre', 'items:id,pedido_id'])
            ->when($request->filled('estado'), function ($consulta) use ($request): void {
                $consulta->where('estado', $request->string('estado')->toString());
            })
            ->orderByDesc('fecha_prometida')
            ->paginate(50);

        return response()->json($pedidos);
    }

    public function mostrar(Request $request, string $pedido): JsonResponse
    {
        $modelo = Pedido::query()
            ->with(['cliente', 'items.papel.plancha', 'items.pruebas.arte', 'entregas', 'aprobador'])
            ->findOrFail($pedido);

        $this->autorizar($request, 'view', $modelo);

        return response()->json([
            'id' => $modelo->id,
            'numero' => $modelo->numero,
            'estado' => $modelo->estado->value,
            'fecha_prometida' => $modelo->fecha_prometida?->toDateString(),
            'descripcion' => $modelo->descripcion,
            'cliente' => $modelo->cliente?->nombre,
            'cantidad_pedida' => $modelo->cantidadPedida(),
            'cantidad_entregada' => $modelo->cantidadEntregada(),
            'saldo' => $modelo->saldo(),
            'aprobado_en' => $modelo->aprobado_en?->toIso8601String(),
            'aprobado_por' => $modelo->aprobador?->nombre,
            'puede_imprimirse' => $modelo->todoItemAprobado(),
            'piezas_pendientes' => $this->aprobacion->piezasPendientes($modelo),
            'items' => $modelo->items->map(function ($item): array {
                return [
                    'id' => $item->id,
                    'descripcion' => $item->descripcion,
                    'cantidad_unidades' => $item->cantidad_unidades,
                    'medida' => $item->medidaLegible(),
                    'n_por_ventana' => $item->n_por_ventana,
                    'papel' => $item->papel?->descripcionCorta(),
                    'tiene_prueba_aprobada' => $item->tienePruebaAprobada(),
                    'prueba_vencida' => $item->pruebaVencida(),
                ];
            }),
            'entregas' => $modelo->entregas->map(static fn ($e): array => [
                'id' => $e->id,
                'cantidad_unidades' => $e->cantidad_unidades,
                'medio' => $e->medio,
                'entregado_en' => $e->entregado_en?->toDateString(),
            ]),
        ]);
    }

    /**
     * Consumo previsto de un item, con el desglose completo.
     *
     * Se puede pedir SIN que haya prueba aprobada: esto es una cotizacion, y
     * cotizar no es imprimir. La pantalla de pedidos lo usa para mostrarle al
     * cliente "esto sale en tantos pliegos, y asi se llega".
     */
    public function consumo(Request $request, string $item): JsonResponse
    {
        $modelo = PedidoItem::query()
            ->with('papel.plancha')
            ->findOrFail($item);

        $this->autorizar($request, 'view', $modelo->pedido);

        return response()->json(
            $this->produccion->presupuestar($modelo)->toArray()
        );
    }

    /**
     * Aprueba el pedido.
     *
     * El servicio es quien decide: la ruta solo traduce el error a una
     * respuesta con codigo. Si falta una prueba, esto devuelve 422 con
     * `sin_pruebas_aprobadas` y la lista de piezas pendientes.
     */
    public function aprobar(Request $request, string $pedido): JsonResponse
    {
        $modelo = Pedido::query()->with('items')->findOrFail($pedido);

        $this->autorizar($request, 'aprobar', $modelo);

        $this->aprobacion->aprobar($modelo, $request->user());

        return response()->json([
            'mensaje' => 'Pedido aprobado. Ya se puede imprimir.',
            'estado' => $modelo->estado->value,
        ]);
    }

    /**
     * Marca el pedido como a la espera de la respuesta del cliente.
     */
    public function esperarAprobacion(Request $request, string $pedido): JsonResponse
    {
        $modelo = Pedido::query()->findOrFail($pedido);

        $this->autorizar($request, 'update', $modelo);

        $this->aprobacion->marcarEsperandoAprobacion($modelo);

        return response()->json([
            'mensaje' => 'El pedido quedo esperando la aprobacion del cliente.',
            'estado' => $modelo->estado->value,
        ]);
    }

    public function anular(Request $request, string $pedido): JsonResponse
    {
        $modelo = Pedido::query()->findOrFail($pedido);

        $this->autorizar($request, 'anular', $modelo);

        $modelo->estado = EstadoPedido::ANULADO;
        $modelo->save();

        return response()->json(['mensaje' => 'Pedido anulado.']);
    }

    /**
     * Autoriza contra la Policy.
     *
     * Si la Policy rechaza, `bootstrap/app.php` traduce el
     * AuthorizationException a 404: no se confirma que el recurso exista en
     * otra empresa.
     */
    private function autorizar(Request $request, string $habilidad, mixed $modelo): void
    {
        $this->authorize($request, $habilidad, $modelo);
    }
}
