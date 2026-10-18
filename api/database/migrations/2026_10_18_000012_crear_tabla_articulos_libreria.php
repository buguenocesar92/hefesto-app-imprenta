<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Articulos de libreria y papeleria: la reventa que acompana al negocio de
 * impresion.
 *
 * Es el flujo simple al lado del resto, y a proposito lo es. Un cuaderno o
 * un estuche de plumas se venden igual que en cualquier negocio: se compra,
 * se vende y queda stock. No tiene aprobacion de cliente ni consumo de
 * plancha.
 *
 * QUE NO HAY EN ESTA TABLA Y POR QUE
 *
 * No hay columna `stock`. El stock se DERIVA sumando `movimientos_libreria`.
 * Si el stock fuera una columna que se edita a mano, en dos semanas de
 * ventas reales estaria pasado y nadie sabria en cuanto. Con el movimiento
 * como unico origen de la verdad, el stock no se puede equivocar: o esta
 * bien o falta un movimiento, y eso se ve.
 *
 * `precio_compra` y `precio_venta` admiten NULL: son datos comerciales de la
 * imprenta y esta aplicacion no los inventa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articulos_libreria', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->string('sku', 40);
            $tabla->string('nombre', 160);

            // 'cuadernos', 'escritorio', 'plastificacion', 'servicios'...
            // Texto libre acotado por uso, no catalogo cerrado: el local
            // decide que vende.
            $tabla->string('categoria', 60);

            // Unidad en que se vende: 'unidad', 'paquete', 'metro'...
            $tabla->string('unidad', 24);

            $tabla->decimal('precio_compra', 14, 2)->nullable();
            $tabla->decimal('precio_venta', 14, 2)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'sku'], 'articulos_libreria_tenant_sku_unique');
            $tabla->index('tenant_id', 'articulos_libreria_tenant_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->check(
                'precio_compra is null or precio_compra >= 0',
                'articulos_libreria_precio_compra_valido'
            );

            $tabla->check(
                'precio_venta is null or precio_venta >= 0',
                'articulos_libreria_precio_venta_valido'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articulos_libreria');
    }
};
