<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Pedido;
use App\Services\Entregas\ServicioDeEntregas;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalcula el estado de un pedido fuera del ciclo de la peticion.
 *
 * POR QUE ES UN JOB Y NO SE CALCULA EN EL PUNTO
 *
 * Recalcular el estado suma todas las entregas de un pedido. Con pocas
 * entregas da igual, pero ese "da igual" se cumple hasta que el cliente tiene
 * pedidos de 20.000 piezas entregados en once entregas parciales y la suma
 * empieza a doler dentro de la peticion de mostrador.
 *
 * El servicio lo recalcula igual y de forma sincrona al registrar una
 * entrega, porque el mostrador TIENE que ver el estado recien: si se difiere,
 * el que entrega ve un estado viejo. El Job existe para los casos en que el
 * recalculo viene de otro lado (una correccion masiva, una restauracion), y
 * se encola para que no bloquee a quien lo lanzo.
 *
 * Por eso el estado SIEMPRE se ve recalculado en la misma peticion. Este Job
 * no es el mecanismo de verdad, es el mecanismo de respaldo.
 */
class ActualizarEstadoPedidoJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $pedidoIds  Pedidos a recalcular.
     */
    public function __construct(public readonly array $pedidoIds)
    {
    }

    public function handle(ServicioDeEntregas $servicio): void
    {
        foreach ($this->pedidoIds as $id) {
            $pedido = Pedido::query()->find($id);

            if ($pedido === null) {
                // El pedido se borro entre que se encolo el trabajo y que se
                // ejecuto. No es un error: no hay nada que recalcular.
                continue;
            }

            $servicio->recalcularEstado($pedido);
        }
    }
}
