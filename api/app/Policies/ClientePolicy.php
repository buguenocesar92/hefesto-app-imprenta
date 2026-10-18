<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use App\Models\Usuario;

/**
 * Clientes.
 *
 * Todos pueden ver y consultar: el mostrador tiene que saber a quien se le
 * esta imprentando.
 *
 * Dar de alta un cliente y editar sus datos es de preparador o superior.
 * No es un dato menor: es a quien se le entrega y a quien se le cobra.
 */
class ClientePolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Cliente $cliente): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeRegistrarProduccion();
    }

    public function update(Usuario $usuario, Cliente $cliente): bool
    {
        return $usuario->rol->puedeRegistrarProduccion();
    }
}
