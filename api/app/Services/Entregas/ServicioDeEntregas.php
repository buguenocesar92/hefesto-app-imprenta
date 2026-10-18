<?php

declare(strict_types=1);

namespace App\Services\Entregas;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorDeImprenta;
use App\Models\Entrega;
use App\Models\Pedido;
use App\Models\Usuario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra las salidas de un pedido y recalcula su estado.
 *
 * UN PEDIDO DE 20.000 SE ENTREGA EN DOS, Y ESO NO CIERRA NADA.
 *
 * El estado del pedido NUNCA se pone a mano. Se deriva:
 *
 *   entregado == 0                      -> el estado sigue como estaba
 *   0 < entregado < pedido              -> PARCIALMENTE_ENTREGADO
 *   entregado >= pedido                 -> ENTREGADO
 *
 * Que quede escrito asi de explicito porque es la parte que mas se ha roto
 * en otros sistemas: marcar una entrega y que el pedido se cerrara. Aqui
 * el estado no depende de la ultima entrega sino de la SUMA de todas, contra
 * la cantidad pedida. Por eso `pedidos` no tiene columna de entregado: el
 * saldo se deriva sumando `entregas` y asi no puede quedar desactualizado.
 *
 * Por que no se permite entregar de mas: si una entrega pasara, el estado
 * quedaria "entregado" con saldo negativo, y el taller dejaria de avisar
 * que falta material. La tolerancia por sobreentrega, si la imprenta quiere
 * una, se configura con `imprenta.entregas.margen_tolerancia_piezas`, y por
 * omision es CERO.
 */
class ServicioDeEntregas
{
    /**
     * Registra una entrega parcial o total y recalcula el estado.
     *
     * @throws ErrorDeImprenta si la entrega supera el saldo, o si el pedido
     *                          no admite entregas en su estado.
     */
    public function registrar(
        Pedido $pedido,
        Usuario $usuario,
        int $cantidad,
        string $medio,
        ?string $entregadoPor = null,
        ?Carbon $fecha = null,
        ?string $notas = null
    ): Entrega {
        if (! $usuario->rol->puedeRegistrarEntrega()) {
            throw new ErrorDeImprenta(
                'Registrar una entrega es tarea de preparador, supervisor o administrador.',
                'sin_permiso_entregar'
            );
        }

        if (! $pedido->estado->admiteEntrega()) {
            throw new ErrorDeImprenta(
                sprintf('El pedido esta "%s": en ese estado no se entrega.', $pedido->estado->value),
                'pedido_no_entregable'
            );
        }

        if ($cantidad <= 0) {
            throw new ErrorDeImprenta(
                'La cantidad entregada tiene que ser mayor que cero.',
                'entrega_sin_cantidad'
            );
        }

        $pedido = DB::transaction(function () use ($pedido, $usuario, $cantidad, $medio, $entregadoPor, $fecha, $notas): Entrega {
            $pedido->refresh();

            $saldo = $pedido->saldo();
            $tolerancia = (int) config('imprenta.entregas.margen_tolerancia_piezas', 0);

            if ($cantidad > $saldo + $tolerancia) {
                throw new ErrorDeImprenta(
                    sprintf(
                        'No se pueden entregar %d piezas: solo quedan %d pendientes%s.',
                        $cantidad,
                        $saldo,
                        $tolerancia > 0
                            ? sprintf(' (tolerancia configurada: %d)', $tolerancia)
                            : ''
                    ),
                    'entrega_supera_saldo'
                );
            }

            $entrega = Entrega::create([
                'tenant_id' => $pedido->tenant_id,
                'pedido_id' => $pedido->id,
                'cantidad_unidades' => $cantidad,
                'medio' => $medio,
                'entregado_por' => $entregadoPor,
                'entregado_en' => $fecha ?? Carbon::now()->toDateString(),
                'usuario_id' => $usuario->id,
                'notas' => $notas,
            ]);

            $this->recalcularEstado($pedido);

            return $entrega;
        });

        return $entrega->fresh() ?? $entrega;
    }

    /**
     * Recalcula el estado del pedido desde la suma de las entregas.
     *
     * Es publico a proposito: el comando de verificacion y el reporte lo
     * usan para comprobar que el estado guardado corresponde a los datos, y
     * si no corresponde, avisar. Un estado que no se puede recalcular no se
     * puede auditar.
     */
    public function recalcularEstado(Pedido $pedido): Pedido
    {
        $pedido->refresh();

        $pedidoTotal = $pedido->cantidadPedida();
        $entregado = $pedido->cantidadEntregada();

        // Sin items no hay nada que entregar y el estado no se toca.
        if ($pedidoTotal <= 0) {
            return $pedido;
        }

        // Un pedido anulado no se "desanula" porque tenga entregas.
        if ($pedido->estado === EstadoPedido::ANULADO) {
            return $pedido;
        }

        $estadoAnterior = $pedido->estado;

        $pedido->estado = match (true) {
            $entregado <= 0 => $pedido->estado,
            $entregado >= $pedidoTotal => EstadoPedido::ENTREGADO,
            default => EstadoPedido::PARCIALMENTE_ENTREGADO,
        };

        // Si cambio, se guarda. Si no cambio, no se toca: no se pisa un
        // pedido que esta en produccion solo porque nadie ha entregado aun.
        if ($pedido->estado !== $estadoAnterior) {
            $pedido->save();
        }

        return $pedido;
    }

    /**
     * Estado que DEBERIA tener el pedido segun sus entregas.
     *
     * Lo usa el comando `imprenta:revisar-estados` para detectar pedidos
     * cuyo estado guardado no cuadra con la suma de las entregas.
     *
     * @return array{pedido: string, estado_guardado: string, estado_esperado: string}
     */
    public function estadoEsperado(Pedido $pedido): array
    {
        $pedidoTotal = $pedido->cantidadPedida();
        $entregado = $pedido->cantidadEntregada();

        $esperado = match (true) {
            $pedido->estado === EstadoPedido::ANULADO => EstadoPedido::ANULADO,
            $pedidoTotal <= 0 || $entregado <= 0 => $pedido->estado,
            $entregado >= $pedidoTotal => EstadoPedido::ENTREGADO,
            default => EstadoPedido::PARCIALMENTE_ENTREGADO,
        };

        return [
            'pedido' => $pedido->numero,
            'estado_guardado' => $pedido->estado->value,
            'estado_esperado' => $esperado->value,
        ];
    }
}
