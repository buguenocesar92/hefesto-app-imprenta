<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GuardarArticuloLibreriaRequest;
use App\Models\ArticuloLibreria;
use App\Models\MovimientoLibreria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Libreria y papeleria: la reventa de insumos.
 *
 * ES EL FLUJO SIMPLE AL LADO DEL RESTO, Y A PROPOSITO.
 *
 * Un cuaderno o un estuche de plumas se compran y se venden, sin prueba que
 * aprobar ni consumo de plancha. Por eso NO tiene nada del mecanismo de
 * pedidos, y esa es la decision: meterle la maquina de aprobaciones a una
 * venta de mostrador la volveria inutilizable.
 *
 * Lo que si comparte es el aislamiento: los movimientos tienen `tenant_id` y
 * su politica los restringe igual que a los pedidos.
 */
class LibreriaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', ArticuloLibreria::class);

        $articulos = ArticuloLibreria::query()
            ->when($request->filled('q'), function ($consulta) use ($request): void {
                $termino = $request->string('q')->toString();
                $consulta->where(function ($interna) use ($termino): void {
                    $interna->where('nombre', 'ilike', '%' . $termino . '%')
                        ->orWhere('sku', 'ilike', '%' . $termino . '%');
                });
            })
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'articulos' => $articulos->map(static fn (ArticuloLibreria $a): array => [
                'id' => $a->id,
                'sku' => $a->sku,
                'nombre' => $a->nombre,
                'categoria' => $a->categoria,
                'unidad' => $a->unidad,
                // El stock se deriva de los movimientos, no se edita.
                'stock' => $a->stock(),
                'precio_compra' => $a->precio_compra,
                'precio_venta' => $a->precio_venta,
                'activo' => $a->activo,
            ]),
        ]);
    }

    public function guardar(GuardarArticuloLibreriaRequest $request, string $articulo): JsonResponse
    {
        $modelo = ArticuloLibreria::query()->findOrFail($articulo);

        $this->authorize($request, 'update', $modelo);

        $modelo->fill($request->validated());
        $modelo->save();

        return response()->json(['mensaje' => 'Articulo guardado.']);
    }

    public function crear(GuardarArticuloLibreriaRequest $request): JsonResponse
    {
        $articulo = ArticuloLibreria::query()->create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json([
            'id' => $articulo->id,
            'mensaje' => 'Articulo creado.',
        ], 201);
    }

    /**
     * Registra una compra o una venta.
     *
     * El stock NUNCA se edita: se suma o se resta con un movimiento. Si
     * queda negativo, lo impide el gatillo de PostgreSQL (migracion 000015),
     * no solo esta validacion.
     */
    public function registrarMovimiento(Request $request, string $articulo): JsonResponse
    {
        $modelo = ArticuloLibreria::query()->findOrFail($articulo);

        $this->authorize($request, 'registrarMovimiento', $modelo);

        $datos = $request->validate([
            'sentido' => ['required', Rule::in(['entrada', 'salida'])],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['required', Rule::in([
                'compra', 'venta', 'merma', 'devolucion', 'regularizacion',
            ])],
            'referencia' => ['nullable', 'string', 'max:60'],
        ]);

        $movimiento = MovimientoLibreria::create([
            'tenant_id' => $modelo->tenant_id,
            'articulo_id' => $modelo->id,
            'sentido' => $datos['sentido'],
            'cantidad' => $datos['cantidad'],
            'motivo' => $datos['motivo'],
            'referencia' => $datos['referencia'] ?? null,
            'usuario_id' => $request->user()->id,
        ]);

        return response()->json([
            'mensaje' => 'Movimiento registrado.',
            'movimiento' => ['id' => $movimiento->id],
            'articulo' => [
                'id' => $modelo->id,
                'stock' => $modelo->stock(),
            ],
        ], 201);
    }
}
