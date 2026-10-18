<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GuardarPapelRequest;
use App\Models\Papel;
use App\Models\Plancha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Papel y planchas: el catalogo que define el costo.
 *
 * Cada papel que se lista trae su rendimiento POR PLANCHA para el caso mas
 * comun, y avisa cuando no se puede calcular porque le falta la merma. No
 * se muestra un calculo parcial sin decirlo.
 */
class PapelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $papeles = Papel::query()
            ->with('plancha')
            ->when($request->filled('q'), function ($consulta) use ($request): void {
                $consulta->where(function ($interna) use ($request): void {
                    $interna->where('codigo', 'ilike', '%' . $request->string('q')->toString() . '%')
                        ->orWhere('nombre', 'ilike', '%' . $request->string('q')->toString() . '%');
                });
            })
            ->orderBy('tipo')
            ->orderBy('gramaje_gm2')
            ->get();

        return response()->json([
            'papeles' => $papeles->map(static fn (Papel $p): array => [
                'id' => $p->id,
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'tipo' => $p->tipo->value,
                'gramaje_gm2' => $p->gramaje_gm2,
                'plancha' => $p->plancha?->medidaLegible(),
                'unidad_compra' => $p->unidad_compra->value,
                'descripcion' => $p->descripcionCorta(),
                'merma_configurada' => $p->mermaConfigurada(),
                'precio_referencia_por_kg' => $p->precio_referencia_por_kg,
                'activo' => $p->activo,
            ]),
        ]);
    }

    public function mostrar(Request $request, string $papel): JsonResponse
    {
        $modelo = Papel::query()->with('plancha')->findOrFail($papel);

        $this->authorize($request, 'view', $modelo);

        return response()->json([
            'id' => $modelo->id,
            'codigo' => $modelo->codigo,
            'nombre' => $modelo->nombre,
            'tipo' => $modelo->tipo->value,
            'gramaje_gm2' => $modelo->gramaje_gm2,
            'descripcion' => $modelo->descripcionCorta(),
            'unidad_compra' => $modelo->unidad_compra->value,
            'merma' => [
                'prueba_porcentaje' => $modelo->merma_prueba_porcentaje,
                'plancha_porcentaje' => $modelo->merma_plancha_porcentaje,
                'configurada' => $modelo->mermaConfigurada(),
            ],
            'precio_referencia_por_kg' => $modelo->precio_referencia_por_kg,
            'gramos_por_pliego' => $modelo->plancha !== null
                ? round($modelo->plancha->areaEnMetros() * $modelo->gramaje_gm2, 1)
                : null,
            'plancha' => $modelo->plancha?->medidaLegible(),
            'activo' => $modelo->activo,
        ]);
    }

    public function guardar(GuardarPapelRequest $request, string $papel): JsonResponse
    {
        $modelo = Papel::query()->findOrFail($papel);

        $this->authorize($request, 'update', $modelo);

        $modelo->fill($request->validated());
        $modelo->save();

        return response()->json([
            'mensaje' => 'Papel guardado.',
            'merma_configurada' => $modelo->mermaConfigurada(),
        ]);
    }

    public function crear(GuardarPapelRequest $request): JsonResponse
    {
        $papel = Papel::query()->create([
            ...$request->validated(),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json([
            'id' => $papel->id,
            'mensaje' => 'Papel creado.',
        ], 201);
    }

    /**
     * Planchas del catalogo.
     *
     * Se listan porque son el otro lado de la conversion: sin la medida de la
     * plancha no hay rendimiento.
     */
    public function planchas(Request $request): JsonResponse
    {
        $this->authorize($request, 'viewAny', Plancha::class);

        return response()->json([
            'planchas' => Plancha::query()
                ->orderBy('nombre')
                ->get()
                ->map(static fn (Plancha $p): array => [
                    'id' => $p->id,
                    'codigo' => $p->codigo,
                    'nombre' => $p->nombre,
                    'medida' => $p->medidaLegible(),
                    'area_m2' => round($p->areaEnMetros(), 4),
                    'pliegos_por_paquete' => $p->pliegos_por_paquete,
                    'activo' => $p->activo,
                ]),
        ]);
    }
}
