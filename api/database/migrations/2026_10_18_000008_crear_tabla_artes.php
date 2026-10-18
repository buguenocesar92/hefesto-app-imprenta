<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Artes: los archivos de diseno.
 *
 * EL CONTENIDO DEL DISENO VIVE FUERA DE ESTE SISTEMA. Aca se guarda el
 * REGISTRO: que archivo es, en que version, y quien lo subio. El archivo
 * esta en el servidor de archivos o en la nube; el sistema guarda su
 * `url` y su `sha256`, que sirve para demostrar que el archivo aprobado es
 * el mismo que se imprimio.
 *
 * `version` es un entero y arranca en 1. Una version nueva NO reemplaza a la
 * anterior: el taller puede volver a imprimir por la version 2 cuando el
 * cliente pidio ese cambio, y la version 1 sigue estando ahi para probar
 * que fue lo que aprobaron.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('pedido_item_id');

            // Version del arte para este item. Empieza en 1.
            $tabla->integer('version');

            $tabla->string('nombre_archivo', 255);

            // Donde vive el archivo, fuera de este sistema.
            $tabla->string('url', 500);

            // Huella del archivo. Sirve para probar que el arte aprobado es
            // el mismo que se imprimio.
            $tabla->string('sha256', 64)->nullable();

            $tabla->text('notas')->nullable();

            $tabla->uuid('subido_por');
            $tabla->timestampsTz();

            $tabla->unique(
                ['pedido_item_id', 'version'],
                'artes_item_version_unique'
            );

            $tabla->index('tenant_id', 'artes_tenant_id_index');
            $tabla->index('pedido_item_id', 'artes_item_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('pedido_item_id')
                ->references('id')
                ->on('pedido_items')
                ->cascadeOnDelete();

            $tabla->foreign('subido_por')
                ->references('id')
                ->on('usuarios')
                ->restrictOnDelete();

            $tabla->check('version >= 1', 'artes_version_valida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artes');
    }
};
