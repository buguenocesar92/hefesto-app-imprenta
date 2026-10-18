<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Items del pedido: que se imprime, en que papel y de que medida.
 *
 * `cantidad_unidades` es el numero de piezas QUE SE ENTREGAN (tarjetas, hojas,
 * afiches). No es el numero de pliegos: el papel se cuenta aparte, en
 * `ordenes_produccion`, porque de eso depende el costo y porque el papel que
 * se gasta no es el que se entrega.
 *
 * `n_por_ventana` es la agrupacion (ganging): cuantas piezas del mismo trabajo
 * se imprimen juntas en una misma cara de la plancha. Con `1` cada pieza ocupa
 * su lugar; con `4` se imprime una de las cuatro tiras juntas para no gastar
 * tinta en los espacios vacios. Cambia el rendimiento del papel, asi que es
 * parte del calculo de consumo, no un detalle de maquina.
 *
 * NOTA: `unidades_por_pliego` NO es una columna. Se calcula con la medida de
 * la plancha, la medida de la pieza y la agrupacion, porque depende del papel
 * de este item y no es una constante. Ver `CalculadoraDeConsumo`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('pedido_id');

            // Papel con que se imprime este item.
            $tabla->uuid('papel_id');

            // Que se imprime. Texto libre a proposito: "Tarjetas de presentacion",
            // "3000 flyers A5", "500 hojas membretadas". El catalogo de
            // trabajos es de la imprenta y cambia; lo que el sistema obliga es
            // la medida y la cantidad.
            $tabla->string('descripcion', 200);

            // Piezas a entregar.
            $tabla->integer('cantidad_unidades');

            // Measure de la pieza acabada, en milimetros.
            $tabla->integer('ancho_mm');
            $tabla->integer('alto_mm');

            // Agrupacion: piezas por cara de plancha. Minimo 1.
            $tabla->integer('n_por_ventana')->default(1);

            $tabla->timestampsTz();

            $tabla->index('tenant_id', 'pedido_items_tenant_id_index');
            $tabla->index('pedido_id', 'pedido_items_pedido_id_index');
            $tabla->index('papel_id', 'pedido_items_papel_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('pedido_id')
                ->references('id')
                ->on('pedidos')
                ->cascadeOnDelete();

            $tabla->foreign('papel_id')
                ->references('id')
                ->on('papeles')
                ->restrictOnDelete();

            $tabla->check('cantidad_unidades > 0', 'pedido_items_cantidad_valida');
            $tabla->check('ancho_mm > 0 and alto_mm > 0', 'pedido_items_medida_valida');
            $tabla->check('n_por_ventana >= 1', 'pedido_items_agrupacion_valida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_items');
    }
};
