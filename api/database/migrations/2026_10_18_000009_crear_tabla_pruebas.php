<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pruebas (mockups): lo que el cliente VE antes de que se imprima.
 *
 * ESTA TABLA ES EL CORAZON DEL PRODUCTO.
 *
 * Antes de imprimir 5.000 tarjetas el cliente tiene que verlas. Este
 * registro dice que se le mostro, en que version del arte, y que respondio.
 *
 * `aprobada_por` es QUIEN REGISTRO LA APROBACION, no quien la decidio. La
 * aprueba el cliente; el supervisor deja constancia. Guardar ese nombre es
 * lo que hace auditable el pedido meses despues, cuando el cliente diga que
 * el nunca habia aprobado eso.
 *
 * `estado` es un texto cerrado y la fila solo pasa a 'aprobada' por el
 * `ServicioDeAprobacion`. No hay forma de llegar a 'aprobada' con un UPDATE
 * suelto desde la aplicacion, y un gatillo de PostgreSQL impide registrar
 * produccion sin una prueba aprobada de este item (migracion 000015).
 *
 * Un mismo item puede tener varias pruebas, una por version. Solo la
 * APROBADA habilita la produccion: las rechazadas quedan como prueba de que
 * se corrigio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pruebas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('pedido_item_id');

            // Version del arte que se mostro en esta prueba.
            $tabla->uuid('arte_id');
            $tabla->integer('version');

            // 'pendiente', 'enviada', 'aprobada' o 'rechazada'.
            $tabla->string('estado', 16)->default('pendiente');

            $tabla->timestampTz('enviada_en')->nullable();
            $tabla->timestampTz('resuelta_en')->nullable();

            // Quien registro la respuesta del cliente.
            $tabla->uuid('aprobada_por')->nullable();

            $tabla->text('comentario')->nullable();

            $tabla->timestampsTz();

            $tabla->index('tenant_id', 'pruebas_tenant_id_index');
            $tabla->index('pedido_item_id', 'pruebas_item_id_index');
            $tabla->index('arte_id', 'pruebas_arte_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('pedido_item_id')
                ->references('id')
                ->on('pedido_items')
                ->cascadeOnDelete();

            $tabla->foreign('arte_id')
                ->references('id')
                ->on('artes')
                ->cascadeOnDelete();

            $tabla->foreign('aprobada_por')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();

            $tabla->check(
                "estado in ('pendiente', 'enviada', 'aprobada', 'rechazada')",
                'pruebas_estado_valido'
            );

            $tabla->check('version >= 1', 'pruebas_version_valida');

            // Una prueba aprobada tiene que decir quien la registro y cuando.
            // Una prueba no aprobada no puede tener `aprobada_por`.
            $tabla->check(
                "(estado <> 'aprobada') or (aprobada_por is not null and resuelta_en is not null)",
                'pruebas_aprobada_con_autor'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pruebas');
    }
};
