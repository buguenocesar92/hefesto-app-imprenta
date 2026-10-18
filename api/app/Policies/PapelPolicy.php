<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Papel;
use App\Models\Usuario;

/**
 * Papel del catalogo.
 *
 * Consultar el catalogo es parte del dia a dia de cualquiera que prepare un
 * trabajo: sin ver el papel no se puede cotizar.
 *
 * Cargar o desactivar un papel es decision de administracion. Desactivar mal
 * un papel que tiene pedidos abiertos deja esos pedidos sin cotizar.
 */
class PapelPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Papel $papel): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function update(Usuario $usuario, Papel $papel): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /**
     * Desactivar un papel corta la produccion que lo usa.
     */
    public function desactivar(Usuario $usuario, Papel $papel): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /**
     * Ver el consumo calculado de un papel: todos. Es la pantalla con la que
     * el taller trabaja.
     */
    public function verConsumo(Usuario $usuario, Papel $papel): bool
    {
        return true;
    }
}
