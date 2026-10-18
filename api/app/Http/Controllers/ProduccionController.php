<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDeImprenta;
use App\Http\Requests\RegistrarProduccionRequest;
use App\Models\OrdenProduccion;
use App\Models\PedidoItem;
use App\Services\Produccion\ServicioDeProduccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Produccion: que se imprime, con cuanta merma y por que.
 *
 * ESTA RUTA ES LA QUE RESPONDE "POR QUE NO PUEDO IMPRIMIR".
 *
 * Cuando una orden se rechaza, la respuesta no es un "no": es el motivo. Un
 * operario que sube a la plancha sin entender por que se le rechazo va a
 * buscar la forma de saltarselo, y esa forma existe siempre.
 */
class ProduccionController extends Controller
{
    public function __construct(private readonly ServicioDeProduccion $servicio) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', OrdenProduccion::class);

        $ordenes = OrdenProduccion::query()
            ->with(['item:id,descripcion,pedido_id', 'papel:id,codigo,nombre', 'usuario'])
            ->orderByDesc('created_at')
            ->paginate(50);

        return response()->json($ordenes);
    }

    /**
     * Registra una corrida.
     *
     * Los pliegos los calcula el servicio. Si el item no tiene prueba
     * aprobada, el servicio lanza `ErrorDeImprenta` con el codigo
     * `sin_prueba_aprobada`, `bootstrap/app.php` lo convierte en 422, y la
     * interfaz muestra el motivo.
     *
     * Si la migracion 000015 esta activa, ademas esta el gatillo de
     * PostgreSQL, que rechazaria el INSERT aunque el servicio se saltara.
     */
    public function registrar(RegistrarProduccionRequest $request, string $item): JsonResponse
    {
        $modelo = PedidoItem::query()->with(['papel.plancha', 'pedido'])->findOrFail($item);

        $this->authorize($request, 'registrar', [OrdenProduccion::class, $modelo]);

        try {
            $orden = $this->servicio->registrar(
                $modelo,
                $request->user(),
                $request->input('producto_bueno') !== null
                    ? (int) $request->input('producto_bueno')
                    : null,
                $request->input('notas')
            );
        } catch (ErrorDeImprenta $e) {
            // Se devuelve con detalle para que el taller sepa que hacer.
            return response()->json([
                'mensaje' => $e->getMessage(),
                'codigo' => $e->codigo(),
            ], 422);
        }

        return response()->json([
            'mensaje' => 'Produccion registrada.',
            'orden' => [
                'id' => $orden->id,
                'pliegos_netos' => $orden->pliegos_netos,
                'pliegos_prueba' => $orden->pliegos_prueba,
                'pliegos_merma' => $orden->pliegos_merma,
                'pliegos_totales' => $orden->pliegos_totales,
                'producto_bueno' => $orden->producto_bueno,
                'merma_real_porcentaje' => $orden->mermaRealPorcentaje(),
            ],
            'pedido' => [
                'estado' => $orden->item?->pedido?->estado->value,
                'imprimible' => $orden->item?->pedido?->estado->admiteProduccion() ?? false,
            ],
        ], 201);
    }

    /**
     * Consumo previsto de un item, con el desglose paso a paso.
     */
    public function presupuesto(Request $request, string $item): JsonResponse
    {
        $modelo = PedidoItem::query()->with('papel.plancha')->findOrFail($item);

        $this->authorize($request, 'view', [OrdenProduccion::class, $modelo]);

        $desglose = $this->servicio->presupuestar($modelo);

        return response()->json($desglose->toArray());
    }

    /**
     * Estado de produccion de todos los items de un pedido.
     *
     * Muestra de un vistazo que se puede imprimir y que no, que es la
     * pregunta que el taller se hace al iniciar la jornada.
     */
    public function porPedido(Request $request, string $pedido): JsonResponse
    {
        $this->authorize($request, 'viewAny', OrdenProduccion::class);

        $items = PedidoItem::query()
            ->with(['papel:id,codigo,nombre,gramaje_gm2', 'pruebas'])
            ->where('pedido_id', $pedido)
            ->get();

        return response()->json([
            'items' => $items->map(function (PedidoItem $item): array {
                $desglose = null;

                try {
                    $desglose = $this->servicio->presupuestar($item)->toArray();
                } catch (ErrorDeImprenta $e) {
                    // Un papel sin merma cargada no se puede cotizar. Se
                    // devuelve el motivo en vez de un calculo a medias.
                    $desglose = ['error' => $e->codigo(), 'mensaje' => $e->getMessage()];
                }

                return [
                    'id' => $item->id,
                    'descripcion' => $item->descripcion,
                    'cantidad_unidades' => $item->cantidad_unidades,
                    'aprobado' => $item->tienePruebaAprobada(),
                    'estado_pedido' => $item->pedido?->estado?->value,
                    'imprimible' => $item->pedido?->estado->admiteProduccion() ?? false,
                    'consumo' => $desglose,
                ];
            }),
        ]);
    }
}
