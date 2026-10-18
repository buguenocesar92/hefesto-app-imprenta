<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ArticuloLibreria;
use App\Models\Usuario;

/**
 * Articulos de libreria y papeleria.
 *
 * Consultar el catalogo y ver el stock es para todos: es el mostrador.
 *
 * Dar de alta un articulo lo decide administracion, porque el precio de
 * venta no se inventa y un articulo sin precio cargado se vende a un
 * precio que nadie eligio.
 *
 * Registrar una compra o una venta es del preparador: es la operacion
 * corriente del mostrador.
 */
class ArticuloLibreriaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, ArticuloLibreria $articulo): bool
    {
        return true;
    }

    public function verStock(Usuario $usuario, ArticuloLibreria $articulo): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function update(Usuario $usuario, ArticuloLibreria $articulo): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /** Comprar y vender en mostrador. */
    public function registrarMovimiento(Usuario $usuario, ArticuloLibreria $articulo): bool
    {
        return $usuario->rol->puedeRegistrarEntrega();
    }
}
