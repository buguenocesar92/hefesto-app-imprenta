<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrdenProduccion;
use App\Models\Usuario;

/**
 * Ordenes de produccion.
 *
 * Registrar una tirada es del preparador. ANULAR una tirada registrada no
 * existe como operacion: las ordenes son la evidencia de lo que se gasto de
 * papel, y borrar una seria borrar la prueba de la merma.
 *
 * Si una tirada se registro con numeros que no corresponden, la correccion se
 * hace con una nueva orden y una nota que la explique, no borrando.
 */
class OrdenProduccionPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, OrdenProduccion $orden): bool
    {
        return true;
    }

    public function registrar(Usuario $usuario, OrdenProduccion $orden): bool
    {
        return $usuario->rol->puedeRegistrarProduccion();
    }
}
