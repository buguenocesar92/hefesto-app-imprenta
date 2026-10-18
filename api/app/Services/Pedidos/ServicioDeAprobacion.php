<?php

declare(strict_types=1);

namespace App\Services\Pedidos;

use App\Enums\EstadoPedido;
use App\Enums\EstadoPrueba;
use App\Exceptions\ErrorDeImprenta;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Prueba;
use App\Models\Usuario;

/**
 * Decide cuando un pedido pasa de "sin aprobar" a "aprobado".
 *
 * ESTE SERVICIO ES LA RAZON DEL ESTADO `APROBADO`.
 *
 * La regla es una sola y no admite excepciones: un pedido se aprueba cuando
 * TODOS sus items tienen una prueba APROBADA. No cuando "la mayoria", no
 * cuando "la que se vio primero", no cuando el cliente dijo que si por
 * telefono a las seis de la tarde.
 *
 * Por que es una regla y no un estado que alguien pone a mano: porque en una
 * imprenta reimprimir lo que el cliente no aprobo es el error mas caro del
 * rubro, y en el mostrador la prisa siempre gana. Si el estado se pudiera
 * cambiar a mano, habria un camino corto para saltarse la prueba. Con este
 * servicio no lo hay.
 *
 * Y por que NO hay `aprobar()` que tome un booleano "aprobar todo": porque
 * ese metodo seria exactamente el atajo. Se aprueba item por item.
 */
class ServicioDeAprobacion
{
    /**
     * Aprueba el pedido si, y solo si, todos sus items tienen prueba aprobada.
     *
     * @throws ErrorDeImprenta si queda algun item sin aprobar. El mensaje
     *                          dice cuales, porque el taller tiene que saber
     *                          a quien llamar.
     */
    public function aprobar(Pedido $pedido, Usuario $usuario): Pedido
    {
        if (! $usuario->rol->puedeAprobarPruebas()) {
            throw new ErrorDeImprenta(
                'Registrar la aprobacion de una prueba es tarea de supervisor o administrador.',
                'sin_permiso_aprobar'
            );
        }

        if ($pedido->estado === EstadoPedido::ANULADO) {
            throw new ErrorDeImprenta(
                'El pedido esta anulado: no se puede aprobar.',
                'pedido_anulado'
            );
        }

        if ($pedido->estado === EstadoPedido::ENTREGADO) {
            throw new ErrorDeImprenta(
                'El pedido ya esta entregado: no se puede aprobar.',
                'pedido_entregado'
            );
        }

        $sinAprobar = $pedido->items()
            ->get()
            ->reject(static fn (PedidoItem $item): bool => $item->tienePruebaAprobada())
            ->map(static fn (PedidoItem $item): string => sprintf(
                '"%s" (version %d de la pieza pendiente)',
                $item->descripcion,
                (int) $item->ultimaPrueba()?->version
            ))
            ->values();

        if ($sinAprobar->isNotEmpty()) {
            throw new ErrorDeImprenta(
                'No se puede aprobar el pedido: hay piezas sin prueba aprobada. ' . $sinAprobar->implode('; ') . '.',
                'pedido_sin_pruebas_aprobadas'
            );
        }

        $pedido->estado = EstadoPedido::APROBADO;
        $pedido->aprobado_por = $usuario->id;
        $pedido->aprobado_en = now();
        $pedido->save();

        return $pedido;
    }

    /**
     * Pasa el pedido a "esperando aprobacion".
     *
     * No es un formality: desde aca el taller ve que hay que conseguir una
     * respuesta, y el sistema puede avisar cuando la prueba se vence.
     */
    public function marcarEsperandoAprobacion(Pedido $pedido): Pedido
    {
        if (! $pedido->estado->estaAbierto()) {
            throw new ErrorDeImprenta(
                sprintf('El pedido esta "%s": ya no admite cambios de aprobacion.', $pedido->estado->value),
                'pedido_cerrado'
            );
        }

        $pedido->estado = EstadoPedido::ESPERANDO_APROBACION;
        $pedido->save();

        return $pedido;
    }

    /**
     * Registra la respuesta del cliente a una prueba.
     *
     * La aprueba el CLIENTE: lo que se guarda aqui es el nombre de quien
     * dejo constancia de esa respuesta.
     */
    public function registrarAprobacion(Prueba $prueba, Usuario $usuario): Prueba
    {
        if (! $usuario->rol->puedeAprobarPruebas()) {
            throw new ErrorDeImprenta(
                'Registrar la aprobacion de una prueba es tarea de supervisor o administrador.',
                'sin_permiso_aprobar'
            );
        }

        if ($prueba->estado === EstadoPrueba::APROBADA) {
            throw new ErrorDeImprenta(
                'Esta prueba ya estaba aprobada.',
                'prueba_ya_aprobada'
            );
        }

        $prueba->estado = EstadoPrueba::APROBADA;
        $prueba->aprobada_por = $usuario->id;
        $prueba->resuelta_en = now();
        $prueba->save();

        return $prueba;
    }

    /** Registra que el cliente rechazo la prueba: hay que corregir el arte. */
    public function registrarRechazo(Prueba $prueba, Usuario $usuario, ?string $comentario = null): Prueba
    {
        if (! $usuario->rol->puedeAprobarPruebas()) {
            throw new ErrorDeImprenta(
                'Registrar el rechazo de una prueba es tarea de supervisor o administrador.',
                'sin_permiso_aprobar'
            );
        }

        $prueba->estado = EstadoPrueba::RECHAZADA;
        $prueba->aprobada_por = null;
        $prueba->resuelta_en = now();

        if ($comentario !== null && $comentario !== '') {
            $prueba->comentario = $comentario;
        }

        $prueba->save();

        return $prueba;
    }

    /**
     * Piezas del pedido que todavia no pueden imprimirse.
     *
     * Es lo que la pantalla de pedidos muestra en rojo, para que el taller
     * sepa a quien llamar antes de subir a la plancha.
     *
     * @return list<string>
     */
    public function piezasPendientes(Pedido $pedido): array
    {
        return $pedido->items()
            ->get()
            ->reject(static fn (PedidoItem $item): bool => $item->tienePruebaAprobada())
            ->map(static fn (PedidoItem $item): string => $item->descripcion)
            ->values()
            ->all();
    }
}
