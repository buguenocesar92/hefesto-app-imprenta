<?php

declare(strict_types=1);

namespace App\Services\Produccion;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorDeImprenta;
use App\Models\OrdenProduccion;
use App\Models\PedidoItem;
use App\Models\Usuario;
use App\Services\Papel\CalculadoraDeConsumo;
use App\Services\Papel\DesgloseDeConsumo;
use Illuminate\Support\Facades\DB;

/**
 * Registra que se imprimio, y con cuanta merma.
 *
 * LA REGLA CENTRAL DE ESTE PRODUCTO ESTA AQUI Y TAMBIEN EN LA BASE.
 *
 * Este servicio no deja registrar produccion de un item sin prueba aprobada,
 * con un mensaje que el taller entiende. Y la migracion 000015 pone un
 * gatillo de PostgreSQL que hace lo mismo para quien venga por otro lado
 * (un script, un reporte, una actualizacion futura).
 *
 * Las dos capas estan porque fallan distinto: el codigo da un mensaje
 * legible y la base impide el rodeo. Si solo estuviera el codigo, un
 * `INSERT` manual lo saltaria. Si solo estuviera el gatillo, el taller
 * veria un error de PostgreSQL que no explica que hacer.
 */
class ServicioDeProduccion
{
    public function __construct(private readonly CalculadoraDeConsumo $calculadora) {}

    /**
     * Registra una orden de produccion de un item.
     *
     * Los pliegos se calculan con el desglose, no se reciben a mano: si se
     * recibieran, el informe de merma seria tan inventado como la merma
     * misma. Lo unico que se registra a mano es `producto_bueno`, que es
     * un conteo real de la maquina.
     *
     * @throws ErrorDeImprenta si el item no tiene prueba aprobada, o si el
     *                          papel no tiene la merma configurada.
     */
    public function registrar(
        PedidoItem $item,
        Usuario $usuario,
        ?int $productoBueno = null,
        ?string $notas = null
    ): OrdenProduccion {
        if (! $usuario->rol->puedeRegistrarProduccion()) {
            throw new ErrorDeImprenta(
                'Registrar produccion es tarea de preparador, supervisor o administrador.',
                'sin_permiso_producir'
            );
        }

        // La regla que sostiene todo el producto.
        $this->exigirPruebaAprobada($item);

        $pedido = $item->pedido;

        if ($pedido === null) {
            throw new ErrorDeImprenta(
                'El item no pertenece a ningun pedido.',
                'item_sin_pedido'
            );
        }

        if (! $pedido->estado->admiteProduccion()) {
            throw new ErrorDeImprenta(
                sprintf(
                    'El pedido esta "%s": en ese estado no se imprime.',
                    $pedido->estado->value
                ),
                'pedido_no_imprimible'
            );
        }

        // Esto falla cerrado si al papel le falta un porcentaje de merma.
        $desglose = $this->calculadora->calcular($item);

        $pliegosNetos = $desglose->pliegosNetos;

        $orden = DB::transaction(function () use (
            $item,
            $usuario,
            $pedido,
            $pliegosNetos,
            $desglose,
            $productoBueno,
            $notas
        ): OrdenProduccion {
            $orden = OrdenProduccion::create([
                'tenant_id' => $item->tenant_id,
                'pedido_item_id' => $item->id,
                'papel_id' => $item->papel_id,
                'pliegos_netos' => $pliegosNetos,
                'pliegos_prueba' => $this->pliegosDeMerma($desglose, 'prueba'),
                'pliegos_merma' => $this->pliegosDeMerma($desglose, 'plancha'),
                'pliegos_totales' => $desglose->pliegosTotales,
                'producto_bueno' => $productoBueno ?? $item->cantidad_unidades,
                'usuario_id' => $usuario->id,
                'notas' => $notas,
            ]);

            if ($pedido->estado === EstadoPedido::APROBADO) {
                $pedido->estado = EstadoPedido::EN_PRODUCCION;
                $pedido->save();
            }

            return $orden;
        });

        return $orden->fresh() ?? $orden;
    }

    /**
     * Desglose del consumo previsto de un item.
     *
     * Se puede pedir en cualquier momento, incluso sin prueba aprobada: es
     * una cotizacion. Cotizar no es imprimir.
     */
    public function presupuestar(PedidoItem $item): DesgloseDeConsumo
    {
        return $this->calculadora->calcular($item);
    }

    /**
     * @throws ErrorDeImprenta siempre que falte la prueba aprobada.
     */
    private function exigirPruebaAprobada(PedidoItem $item): void
    {
        if ($item->tienePruebaAprobada()) {
            return;
        }

        throw new ErrorDeImprenta(
            sprintf(
                'No se imprime "%s": el cliente todavia no aprueba la prueba. '
                . 'Nada sale a imprenta sin que el cliente lo haya visto.',
                $item->descripcion
            ),
            'sin_prueba_aprobada'
        );
    }

    /** Pliegos de merma de un origen concreto del desglose. */
    private function pliegosDeMerma(DesgloseDeConsumo $desglose, string $origen): int
    {
        foreach ($desglose->mermas as $merma) {
            if ($merma->origen === $origen) {
                return $merma->pliegos;
            }
        }

        return 0;
    }
}
