<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que operan la imprenta.
 *
 * El correo es UNICO POR EMPRESA, no global: la misma persona puede trabajar
 * en dos imprentas y tener un rol distinto en cada una. Por eso el selector
 * del login pregunta por la empresa antes de entrar (ver `AuthController` y
 * la vista `vw_impresas_publicas`).
 *
 * La contrasena no vive aca: la resuelve Authentik. Lo que se guarda es el
 * correo como identidad, el nombre y el ROL con el que se autoriza cada
 * accion dentro de esta imprenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->string('email', 180);
            $tabla->string('nombre', 160);

            // Rol de autorizacion: auditor, preparador, supervisor o
            // administrador. Ver App\Enums\RolImprenta.
            $tabla->string('rol', 32);

            $tabla->boolean('activo')->default(true);
            $tabla->rememberToken();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'email'], 'usuarios_tenant_email_unique');
            $tabla->index('tenant_id', 'usuarios_tenant_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->check(
                "rol in ('auditor', 'preparador', 'supervisor', 'administrador')",
                'usuarios_rol_valido'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
