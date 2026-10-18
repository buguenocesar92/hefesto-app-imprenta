<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordenes de produccion: la evidencia de que se imprimio, y de cuanto papel
 * se perdio en el camino.
 *
 * LA DIFERENCIA ENTRE LO USADO Y LO ENTREGADO TIENE QUE QUEDAR VISIBLE,
 * PORQUE ESA DIFERENCIA ES LA PLATA.
 *
 * Por eso `pliegos_totales` esta partido en tres y no es un numero suelto:
 *
 *   pliegos_netos  = los que salen como producto terminado
 *   pliegos_prueba = papel que se gasto en sacar la maqueta
 *   pliegos_merma  = papel que se danio en la plancha y se descarta
 *   pliegos_totales = la suma de los tres
 *
 * Un CHECK obliga a que la suma cuadre. Sin eso, el total podria no cuadrar
 * con las partes y el informe de merma seria falso.
 *
 * `producto_bueno` es el.CONTADO REAL de piezas buenas de esta corrida: lo
 * que salio bien de verdad. Puede ser MENOR que lo pedido, y eso no se
 * corrige a mano: es informacion real del taller y es lo que permite
 * comparar lo previsto contra lo ocurrido.
 *
 * Un gatillo de PostgreSQL (migracion 000015) impide registrar una orden si
 * el item no tiene una prueba aprobada. La regla no depende de que la
 * aplicacion se acuerde de aplicarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_produccion', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // Se registra por item, no por pedido: cada item tiene su papel,
            // su medida y su propia prueba.
            $tabla->uuid('pedido_item_id');

            // Papel realmente usado en esta corrida.
            $tabla->uuid('papel_id');

            $tabla->integer('pliegos_netos');
            $tabla->integer('pliegos_prueba');
            $tabla->integer('pliegos_merma');
            $tabla->integer('pliegos_totales');

            // Piezas buenas contadas al salir de la maquina.
            $tabla->integer('producto_bueno');

            $tabla->uuid('usuario_id');

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->index('tenant_id', 'ordenes_produccion_tenant_id_index');
            $tabla->index('pedido_item_id', 'ordenes_produccion_item_id_index');
            $tabla->index('papel_id', 'ordenes_produccion_papel_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('pedido_item_id')
                ->references('id')
                ->on('pedido_items')
                ->restrictOnDelete();

            $tabla->foreign('papel_id')
                ->references('id')
                ->on('papeles')
                ->restrictOnDelete();

            $tabla->foreign('usuario_id')
                ->references('id')
                ->on('usuarios')
                ->restrictOnDelete();

            $tabla->check(
                'pliegos_netos >= 0 and pliegos_prueba >= 0 and pliegos_merma >= 0',
                'ordenes_produccion_pliegos_no_negativos'
            );

            $tabla->check('producto_bueno >= 0', 'ordenes_produccion_producto_valido');

            // El total tiene que ser la suma de las partes. Sin esta regla,
            // un pliego perdido puede quedar escondido dentro de un total
            // inflado.
            $tabla->check(
                'pliegos_totales = pliegos_netos + pliegos_prueba + pliegos_merma',
                'ordenes_produccion_total_cuadra'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_produccion');
    }
};
