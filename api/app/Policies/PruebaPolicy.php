<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Prueba;
use App\Models\Usuario;

/**
 * Pruebas (mockups).
 *
 * Cargar una version nueva del arte es de cualquiera: el preparador lo hace
 * varias veces al dia.
 *
 * Registrar la respuesta del cliente es de supervisor. No porque el
 * supervisor sea el que apruebe, sino porque es el que deja constancia de lo
 * que el cliente respondio, y esa constancia tiene que tener un responsable.
 */
class PruebaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Prueba $prueba): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeRegistrarProduccion();
    }

    public function registrarAprobacion(Usuario $usuario, Prueba $prueba): bool
    {
        return $usuario->rol->puedeAprobarPruebas();
    }

    public function registrarRechazo(Usuario $usuario, Prueba $prueba): bool
    {
        return $usuario->rol->puedeAprobarPruebas();
    }
}
