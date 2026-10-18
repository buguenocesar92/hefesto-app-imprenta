<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoPrueba;
use App\Models\Arte;
use App\Models\PedidoItem;
use App\Models\Prueba;
use App\Services\Pedidos\ServicioDeAprobacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pruebas (mockups) y artes.
 *
 * EL SISTEMA GUARDA EL REGISTRO, NO EL DISENO.
 *
 * `artes` no tiene el archivo: tiene el nombre, la ruta donde vive, la
 * version y su huella SHA-256. El diseno vive en el servidor de archivos o
 * en la nube; duplicarlo aca seria guardarlo dos veces y terminarían
 * versiones distintas.
 *
 * Por que la huella importa: sin ella, tres meses despues el cliente puede
 * decir que el archivo aprobado era otro. Con ella, se comprueba.
 */
class PruebaController extends Controller
{
    public function __construct(private readonly ServicioDeAprobacion $aprobacion) {}

    /**
     * Lista de pruebas que esperan respuesta, de toda la empresa.
     *
     * Es la pantalla con la que el taller decide a quien llamar primero. Por
     * eso trae `vencida`: una prueba sin respuesta hace dias es la que hay
     * que perseguir, no la mas nueva.
     */
    public function index(Request $request): JsonResponse
    {
        $pruebas = Prueba::query()
            ->with(['item:id,descripcion,pedido_id', 'arte:id,version,nombre_archivo', 'aprobador'])
            ->whereIn('estado', [
                EstadoPrueba::PENDIENTE->value,
                EstadoPrueba::ENVIADA->value,
            ])
            ->orderBy('enviada_en')
            ->paginate(50);

        $dias = (int) config('imprenta.pruebas.dias_para_vencer', 5);
        $limite = now()->subDays($dias);

        return response()->json([
            'dias_para_vencer' => $dias,
            'pruebas' => $pruebas->getCollection()->map(static fn (Prueba $p): array => [
                'id' => $p->id,
                'item' => $p->item?->descripcion,
                'version' => $p->version,
                'archivo' => $p->arte?->nombre_archivo,
                'estado' => $p->estado->value,
                'enviada_en' => $p->enviada_en?->toIso8601String(),
                'vencida' => $p->enviada_en !== null && $p->enviada_en->lessThan($limite),
            ]),
            'meta' => [
                'pagina' => $pruebas->currentPage(),
                'total' => $pruebas->total(),
            ],
        ]);
    }

    /**
     * Registra una version nueva del arte y su prueba.
     *
     * La version se calcula sola: el siguiente entero sobre el item. Un arte
     * nuevo NUNCA reemplaza al anterior.
     */
    public function registrarVersion(Request $request, string $item): JsonResponse
    {
        $modelo = PedidoItem::query()->findOrFail($item);

        $this->authorize($request, 'create', [Prueba::class, $modelo]);

        $datos = $request->validate([
            'nombre_archivo' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'sha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $version = Arte::siguienteVersion($modelo->id);

        $arte = Arte::create([
            'tenant_id' => $modelo->tenant_id,
            'pedido_item_id' => $modelo->id,
            'version' => $version,
            'nombre_archivo' => $datos['nombre_archivo'],
            'url' => $datos['url'],
            'sha256' => $datos['sha256'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'subido_por' => $request->user()->id,
        ]);

        $prueba = Prueba::create([
            'tenant_id' => $modelo->tenant_id,
            'pedido_item_id' => $modelo->id,
            'arte_id' => $arte->id,
            'version' => $version,
            'estado' => EstadoPrueba::PENDIENTE,
        ]);

        return response()->json([
            'arte' => [
                'id' => $arte->id,
                'version' => $arte->version,
                'nombre_archivo' => $arte->nombre_archivo,
            ],
            'prueba' => [
                'id' => $prueba->id,
                'estado' => $prueba->estado->value,
            ],
        ], 201);
    }

    /** Marca la prueba como mostrada al cliente. */
    public function enviar(Request $request, string $prueba): JsonResponse
    {
        $modelo = Prueba::query()->findOrFail($prueba);

        $this->authorize($request, 'create', [Prueba::class, $modelo->pedido_item]);

        if ($modelo->estado !== EstadoPrueba::PENDIENTE) {
            return response()->json([
                'mensaje' => 'Esta prueba ya no esta pendiente.',
                'codigo' => 'prueba_no_pendiente',
            ], 422);
        }

        $modelo->estado = EstadoPrueba::ENVIADA;
        $modelo->enviada_en = now();
        $modelo->save();

        return response()->json([
            'mensaje' => 'Prueba marcada como enviada al cliente.',
            'estado' => $modelo->estado->value,
        ]);
    }

    /**
     * Registra la respuesta del cliente.
     *
     * La aprueba el CLIENTE: aca queda el nombre de quien dejo constancia.
     */
    public function resolver(Request $request, string $prueba): JsonResponse
    {
        $modelo = Prueba::query()->with('pedido_item')->findOrFail($prueba);

        $this->authorize($request, 'registrarAprobacion', $modelo);

        $datos = $request->validate([
            'estado' => ['required', 'string'],
            'comentario' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($datos['estado'] === EstadoPrueba::APROBADA->value) {
            $this->aprobacion->registrarAprobacion($modelo, $request->user());
        } else {
            $this->aprobacion->registrarRechazo(
                $modelo,
                $request->user(),
                $datos['comentario'] ?? null
            );
        }

        return response()->json([
            'mensaje' => $modelo->estado === EstadoPrueba::APROBADA
                ? 'Aprobacion registrada.'
                : 'Rechazo registrado. Hay que corregir el arte y mandar otra prueba.',
            'estado' => $modelo->estado->value,
            'registrado_por' => $request->user()->nombre,
        ]);
    }
}
