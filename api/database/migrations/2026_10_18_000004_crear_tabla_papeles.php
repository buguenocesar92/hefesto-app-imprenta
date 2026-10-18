<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papel: el catalogo que define el costo y lo que el cliente puede pedir.
 *
 * NO HAY FACTOR DE CONVERSION EN ESTA TABLA, Y NO ES UN OLVIDO.
 *
 * Un factor de consumo significa "de cada pliego salen X piezas", y eso no
 * es una propiedad del papel: depende tambien de la medida de la pieza que
 * se imprime. Un papel de 300 g/m2 es el mismo para una tarjeta de 9 x 5 cm
 * que para un afiche de 60 x 90 cm, y en el primer caso rinde decenas de
 * piezas por pliego y en el segundo, ni una. El factor se CALCULA en
 * `CalculadoraDeConsumo` con la medida de la plancha, la medida del trabajo
 * y la agrupacion, y se devuelve con su desglose. Ver docs/02.
 *
 * Sobre la merma (`merma_prueba_porcentaje`, `merma_plancha_porcentaje`):
 * admiten NULL a proposito y no hay valor por omision. Una merma inventada es
 * plata inventada: si el sistema supone que se pierde un 5 % de cada plancha
 * y en realidad se pierde un 2 %, el papel que "falta" no existe y el margen
 * del trabajo desaparece. Mientras esten en NULL, `CalculadoraDeMerma` falla
 * cerrado en vez de inventar un numero. El valor lo pone la imprenta con su
 * propio historial.
 *
 * `precio_referencia_por_kg` tambien admite NULL: es informacion de compra de
 * la imprenta y esta aplicacion no la inventa. El sistema calcula papel;
 * el precio se carga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('papeles', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('codigo', 40);
            $tabla->string('nombre', 120);

            // Offset, coated u opal. Texto cerrado, no libre.
            $tabla->string('tipo', 24);

            // Gramos por metro cuadrado.
            $tabla->integer('gramaje_gm2');

            $tabla->uuid('plancha_id');

            // 'pliego' o 'kilo'. Determina como se compra y, por lo tanto,
            // que conversion se necesita.
            $tabla->string('unidad_compra', 16);

            // Porcentaje de papel que se pierde en las pruebas. NULL =
            // pendiente: no se calcula merma hasta que la imprenta lo cargue.
            $tabla->decimal('merma_prueba_porcentaje', 5, 2)->nullable();

            // Porcentaje de papel que se daña en la plancha. NULL = igual.
            $tabla->decimal('merma_plancha_porcentaje', 5, 2)->nullable();

            // Precio de compra de referencia. NULL = pendiente de carga.
            $tabla->decimal('precio_referencia_por_kg', 14, 2)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo'], 'papeles_tenant_codigo_unique');
            $tabla->index('tenant_id', 'papeles_tenant_id_index');
            $tabla->index('plancha_id', 'papeles_plancha_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('plancha_id')
                ->references('id')
                ->on('planchas')
                ->restrictOnDelete();

            $tabla->check('gramaje_gm2 > 0', 'papeles_gramaje_valido');

            $tabla->check(
                "tipo in ('offset', 'coated', 'opal')",
                'papeles_tipo_valido'
            );

            $tabla->check(
                "unidad_compra in ('pliego', 'kilo')",
                'papeles_unidad_compra_valida'
            );

            $tabla->check(
                'merma_prueba_porcentaje is null or (merma_prueba_porcentaje >= 0 and merma_prueba_porcentaje <= 100)',
                'papeles_merma_prueba_valida'
            );

            $tabla->check(
                'merma_plancha_porcentaje is null or (merma_plancha_porcentaje >= 0 and merma_plancha_porcentaje <= 100)',
                'papeles_merma_plancha_valida'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('papeles');
    }
};
