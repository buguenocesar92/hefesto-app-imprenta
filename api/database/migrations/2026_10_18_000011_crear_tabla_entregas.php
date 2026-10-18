<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entregas: las salidas parciales y totales de un pedido.
 *
 * UN PEDIDO DE 20.000 SE ENTREGA EN DOS, Y ESO NO LO ARREGLA NADA.
 *
 * Por eso esta tabla NO tiene una columna de "saldo". El saldo se deriva
 * sumando estas filas contra la cantidad pedida, y el estado del pedido lo
 * recalcula `ServicioDeEntregas` con esa comparacion. Si el saldo se guardara
 * en el pedido, marcar una entrega podria dejarlo en "completo" por error, y
 * el cliente recibiria mil piezas donde aumento veinte mil.
 *
 * `cantidad_unidades` tiene un CHECK de positividad: una entrega de cero o
 * negativa es un error de tipeo, y un error de tipeo aqui es un pedido que
 * aparece entregado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entregas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');
            $tabla->uuid('pedido_id');

            // Piezas entregadas en ESTA salida.
            $tabla->integer('cantidad_unidades');

            // 'retiro', 'envio' o 'mostrador'.
            $tabla->string('medio', 40);

            // Quien recibio, en el caso de retiro o mostrador.
            $tabla->string('entregado_por', 160)->nullable();

            $tabla->date('entregado_en');

            $tabla->uuid('usuario_id');

            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->index('tenant_id', 'entregas_tenant_id_index');
            $tabla->index('pedido_id', 'entregas_pedido_id_index');

            $tabla->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $tabla->foreign('pedido_id')
                ->references('id')
                ->on('pedidos')
                ->cascadeOnDelete();

            $tabla->foreign('usuario_id')
                ->references('id')
                ->on('usuarios')
                ->restrictOnDelete();

            $tabla->check('cantidad_unidades > 0', 'entregas_cantidad_valida');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas');
    }
};
