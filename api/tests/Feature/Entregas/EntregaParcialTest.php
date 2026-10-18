<?php

declare(strict_types=1);

namespace Tests\Feature\Entregas;

use App\Enums\EstadoPedido;
use App\Exceptions\ErrorDeImprenta;
use App\Models\Entrega;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Papel;
use App\Models\Plancha;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Entregas\ServicioDeEntregas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Entregas parciales que NO cierran el pedido.
 *
 * UN PEDIDO DE 20.000 SE ENTREGA EN DOS. ESO NO ES UN CASO RARO: es lo normal
 * en una imprenta que entrega por tandas mientras imprime.
 *
 * El sistema con el que mas se ha roto esto en otras aplicaciones es el que
 * guarda el saldo en el pedido: marcar una entrega actualiza el saldo a mano
 * y el pedido queda "completo" por un error de tipeo. Aca el saldo NO esta
 * guardado: se deriva sumando las entregas, y el estado se recalcula.
 *
 * Ver docs/06-PRIMER-PEDIDO.md.
 */
class EntregaParcialTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private Pedido $pedido;

    private Usuario $preparador;

    private ServicioDeEntregas $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();
        $this->preparador = Usuario::factory()->de($this->empresa)->create();
        $this->servicio = app(ServicioDeEntregas::class);

        $plancha = Plancha::factory()->de($this->empresa)->deReferencia()->create();

        $papel = Papel::factory()
            ->de($this->empresa)
            ->dePlancha($plancha)
            ->offset120()
            ->conMerma()
            ->create();

        $this->pedido = $this->comoEmpresa($this->empresa->id, function () use ($papel): Pedido {
            $pedido = Pedido::factory()->de($this->empresa)->aprobado()->create();

            PedidoItem::create([
                'tenant_id' => $this->empresa->id,
                'pedido_id' => $pedido->id,
                'papel_id' => $papel->id,
                'descripcion' => '20.000 tarjetas',
                'cantidad_unidades' => 20_000,
                'ancho_mm' => 90,
                'alto_mm' => 50,
                'n_por_ventana' => 1,
            ]);

            return $pedido->fresh();
        });
    }

    #[Test]
    public function una_entrega_parcial_no_cierra_el_pedido(): void
    {
        $this->comoEmpresa($this->empresa->id, function (): void {
            $this->servicio->registrar($this->pedido, $this->preparador, 8_000, 'retiro');

            $this->pedido->refresh();

            // ESTE es el punto de toda la suite. El pedido quedo
            // "parcialmente entregado", no "entregado".
            $this->assertSame(EstadoPedido::PARCIALMENTE_ENTREGADO, $this->pedido->estado);

            $this->assertSame(8_000, $this->pedido->cantidadEntregada());
            $this->assertSame(12_000, $this->pedido->saldo());
        });
    }

    #[Test]
    public function la_segunda_entrega_cierra_el_pedido_solo_por_completar_la_suma(): void
    {
        $this->comoEmpresa($this->empresa->id, function (): void {
            $this->servicio->registrar($this->pedido, $this->preparador, 8_000, 'retiro');
            $this->servicio->registrar($this->pedido, $this->preparador, 12_000, 'envio');

            $this->pedido->refresh();

            // El estado depende de la SUMA de las dos entregas, no de la
            // ultima. Por eso no queda en un saldo guardado que se pueda
            // desactualizar.
            $this->assertSame(EstadoPedido::ENTREGADO, $this->pedido->estado);
            $this->assertSame(0, $this->pedido->saldo());
            $this->assertSame(2, Entrega::query()->count());
        });
    }

    #[Test]
    public function el_estado_del_pedido_se_deriva_y_no_se_pone_a_mano(): void
    {
        $this->comoEmpresa($this->empresa->id, function (): void {
            $this->servicio->registrar($this->pedido, $this->preparador, 5_000, 'mostrador');

            // El servicio sabe decir que estado DEBERIA tener. Con una
            // entrega de 5.000 sobre 20.000, es parcial.
            $comparacion = $this->servicio->estadoEsperado($this->pedido->fresh());

            $this->assertSame('parcialmente_entregado', $comparacion['estado_guardado']);
            $this->assertSame('parcialmente_entregado', $comparacion['estado_esperado']);

            // Si alguien cambiara el estado a mano, la comparacion lo
            // delata. Por eso existe el comando `imprenta:revisar-estados`.
            $this->pedido->estado = EstadoPedido::ENTREGADO;
            $this->pedido->saveQuietly();

            $comparacion = $this->servicio->estadoEsperado($this->pedido->fresh());

            $this->assertSame('entregado', $comparacion['estado_guardado']);
            $this->assertSame('parcialmente_entregado', $comparacion['estado_esperado']);
        });
    }

    #[Test]
    public function no_se_puede_entregar_mas_piezas_de_las_que_quedan(): void
    {
        $this->comoEmpresa($this->empresa->id, function (): void {
            $this->servicio->registrar($this->pedido, $this->preparador, 8_000, 'retiro');

            try {
                $this->servicio->registrar($this->pedido, $this->preparador, 15_000, 'envio');
                $this->fail('Se esperaba que la entrega fuera rechazada por exceso.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('entrega_supera_saldo', $e->codigo());
                $this->assertStringContainsString('12.000 pendientes', $e->getMessage());
            }

            $this->pedido->refresh();

            // Y el pedido sigue como estaba: el intento fallido no lo altera.
            $this->assertSame(EstadoPedido::PARCIALMENTE_ENTREGADO, $this->pedido->estado);
            $this->assertSame(8_000, $this->pedido->cantidadEntregada());
        });
    }

    #[Test]
    public function no_se_acepta_una_entrega_de_cero_piezas(): void
    {
        // Una entrega de cero deja al pedido igual y oculta que no se entrego
        // nada. El CHECK de la base y el servicio lo rechazan los dos.
        $this->comoEmpresa($this->empresa->id, function (): void {
            try {
                $this->servicio->registrar($this->pedido, $this->preparador, 0, 'retiro');
                $this->fail('Se esperaba que una entrega de cero fuera rechazada.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('entrega_sin_cantidad', $e->codigo());
            }
        });
    }

    #[Test]
    public function el_saldo_sobre_un_pedido_sin_entregas_es_todo_lo_pedido(): void
    {
        $this->assertSame(20_000, $this->pedido->saldo());
        $this->assertSame(0, $this->pedido->cantidadEntregada());
    }

    #[Test]
    public function recalcular_es_idempotente_y_no_pisa_un_pedido_sin_entregas(): void
    {
        $this->comoEmpresa($this->empresa->id, function (): void {
            // Sin entregas, el recalculo NO debe tocar el estado: un pedido
            // en produccion que nadie ha entregado sigue en produccion.
            $estadoAntes = $this->pedido->estado;

            $this->servicio->recalcularEstado($this->pedido);

            $this->pedido->refresh();
            $this->assertSame($estadoAntes, $this->pedido->estado);

            // Y despues de una entrega parcial, recalcular dos veces da lo
            // mismo: el estado es una funcion de los datos, no del numero de
            // veces que se ejecuto.
            $this->servicio->registrar($this->pedido, $this->preparador, 1_000, 'retiro');

            $this->servicio->recalcularEstado($this->pedido);
            $this->servicio->recalcularEstado($this->pedido);

            $this->pedido->refresh();
            $this->assertSame(EstadoPedido::PARCIALMENTE_ENTREGADO, $this->pedido->estado);
            $this->assertSame(19_000, $this->pedido->saldo());
        });
    }
}
