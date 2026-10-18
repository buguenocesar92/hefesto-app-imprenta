<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes de la imprenta.
 *
 * Son las empresas y personas a las que se les imprime. El codigo es el que
 * usa el taller en la planilla; el sistema no lo genera solo porque cada
 * imprenta numera a sus clientes como quiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('codigo', 24);
            $tabla->string('nombre', 160);
            $tabla->string('contacto', 160)->nullable();
            $tabla->string('correo', 180)->nullable();
            $tabla->string('telefono', 32)->nullable();
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo'], 'clientes_tenant_codigo_unique');
            $tabla->index('tenant_id', 'clientes_tenant_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};
