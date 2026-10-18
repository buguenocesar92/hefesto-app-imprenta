<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido: lo que el cliente compro.
 *
 * En una imprenta lo que se vende NO es un producto en stock: es una
 * produccion con una prueba aprobada y una fecha prometida. Por eso el
 * pedido tiene `fecha_prometida` y por eso su estado distingue
 * `esperando_aprobacion` de `aprobado`.
 *
 * `cantidad_entregada` NO EXISTE COMO COLUMNA A PROPOSITO. Se deriva sumando
 * las filas de `entregas`. Si se guardara, un segundo registro de entrega
 * la dejaria desactualizada y el pedido podria volver a "completo" solo
 * porque alguien marco una entrega. Ver `ServicioDeEntregas` y docs/06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // Numero del pedido. Es lo que dice el taller por telephone y lo
            // que va impreso en el despacho; lo pone la imprenta.
            $tabla->string('numero', 32);

            $tabla->uuid('cliente_id');

            // Ver App\Enums\EstadoPedido.
            $tabla->string('estado', 32)->default('borrador');

            // La fecha prometida es parte de lo que se vendio.
            $tabla->date('fecha_prometida');

            $tabla->text('descripcion')->nullable();

            // Quien registro la aprobacion del cliente y cuando. Se escribe
            // una sola vez, al aprobar; no se vuelve a tocar.
            $tabla->uuid('aprobado_por')->nullable();
            $tabla->timestampTz('aprobado_en')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero'], 'pedidos_tenant_numero_unique');
            $tabla->index('tenant_id', 'pedidos_tenant_id_index');
            $tabla->index('cliente_id', 'pedidos_cliente_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('cliente_id')
                ->references('id')
                ->on('clientes')
                ->restrictOnDelete();

            $tabla->foreign('aprobado_por')
                ->references('id')
                ->on('usuarios')
                ->nullOnDelete();

            $tabla->check(
                "estado in ('borrador', 'esperando_aprobacion', 'aprobado', 'en_produccion', "
                . "'parcialmente_entregado', 'entregado', 'anulado')",
                'pedidos_estado_valido'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
