<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de libreria: compras y ventas de insumos.
 *
 * Esta tabla es el unico origen del stock de libreria. `articulos_libreria`
 * no guarda stock: el stock es la suma de estas filas.
 *
 * Cada movimiento suma (`entrada`) o resta (`salida`). No hay UPDATE: una
 * correccion se hace con un movimiento contrario y queda la traza de los dos.
 * Un CHECK impide el stock negativo, que en un local pequeno casi siempre es
 * un error de tipeo y no una devolucion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_libreria', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('articulo_id');

            // 'entrada' o 'salida'.
            $tabla->string('sentido', 8);

            $tabla->decimal('cantidad', 18, 3);

            // 'compra', 'venta', 'merma', 'devolucion', 'regularizacion'.
            $tabla->string('motivo', 40);

            // Referencia externa: numero de boleta, de orden de compra.
            $tabla->string('referencia', 60)->nullable();

            $tabla->uuid('usuario_id');
            $tabla->timestampsTz();

            $tabla->index('tenant_id', 'movimientos_libreria_tenant_id_index');
            $tabla->index('articulo_id', 'movimientos_libreria_articulo_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('articulo_id')
                ->references('id')
                ->on('articulos_libreria')
                ->cascadeOnDelete();

            $tabla->foreign('usuario_id')
                ->references('id')
                ->on('usuarios')
                ->restrictOnDelete();

            $tabla->check("sentido in ('entrada', 'salida')", 'movimientos_sentido_valido');
            $tabla->check('cantidad > 0', 'movimientos_cantidad_valida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos_libreria');
    }
};
