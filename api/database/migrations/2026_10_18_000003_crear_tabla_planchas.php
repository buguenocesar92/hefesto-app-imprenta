<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plancha: la hoja entera de papel, con su medida.
 *
 * ESTA TABLA ES LA RAZON DE QUE LA CONVERSION NO SEA UN NUMERO FIJO.
 *
 * De la plancha dependen dos cosas que cambian el costo:
 *
 *   - cuantas piezas caben (y por lo tanto cuantos pliegos hacen falta);
 *   - cuanto pesa un pliego (y por lo tanto cuantos kilos hay que comprar).
 *
 * El mismo trabajo en una plancha de 70 x 100 cm y en una de 64 x 88 cm sale
 * distinto, con el mismo gramaje. Por eso la medida va en el catalogo y no
 * en una constante del codigo.
 *
 * `pliegos_por_paquete` admite `NULL`: es la cantidad de pliegos que trae un
 * paquete del proveedor y cada proveedor la maneja distinto. Mientras no se
 * cargue, el sistema no lo asumira.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planchas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('codigo', 24);
            $tabla->string('nombre', 120);

            // Medida de la plancha en milimetros. Milimetros y no centimetros
            // porque el margen de la plancha se negocia en milimetros.
            $tabla->integer('ancho_mm');
            $tabla->integer('alto_mm');

            // Acepta NULL: queda pendiente de carga hasta que el proveedor
            // confirme el formato del paquete.
            $tabla->integer('pliegos_por_paquete')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo'], 'planchas_tenant_codigo_unique');
            $tabla->index('tenant_id', 'planchas_tenant_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->check('ancho_mm > 0 and alto_mm > 0', 'planchas_medida_valida');

            $tabla->check(
                'pliegos_por_paquete is null or pliegos_por_paquete > 0',
                'planchas_paquete_valido'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planchas');
    }
};
