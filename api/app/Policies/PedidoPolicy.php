<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Pedido;
use App\Models\Usuario;

/**
 * Pedidos.
 *
 * Un auditor ve todos y no cambia nada.
 *
 * Un preparador crea y edita pedidos, pero NO anula: anular es quitarle una
 * pieza al cliente, y eso es decision del supervisor.
 *
 * Un supervisor ademas anula, porque es quien responde por lo que se le
 * prometio al cliente.
 */
class PedidoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Pedido $pedido): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeRegistrarProduccion();
    }

    public function update(Usuario $usuario, Pedido $pedido): bool
    {
        return $this->create($usuario);
    }

    /**
     * Aprobar un pedido exige supervisor: es la accion que habilita imprimir.
     */
    public function aprobar(Usuario $usuario, Pedido $pedido): bool
    {
        return $usuario->rol->puedeAprobarPruebas();
    }

    public function anular(Usuario $usuario, Pedido $pedido): bool
    {
        return $usuario->rol->puedeAnularPedidos();
    }

    /**
     * Registrar una entrega es del dia a dia del mostrador.
     */
    public function registrarEntrega(Usuario $usuario, Pedido $pedido): bool
    {
        return $usuario->rol->puedeRegistrarEntrega();
    }
}
