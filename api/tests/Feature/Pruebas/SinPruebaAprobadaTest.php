<?php

declare(strict_types=1);

namespace Tests\Feature\Pruebas;

use App\Enums\EstadoPedido;
use App\Enums\EstadoPrueba;
use App\Exceptions\ErrorDeImprenta;
use App\Models\Arte;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Papel;
use App\Models\Plancha;
use App\Models\Prueba;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Pedidos\ServicioDeAprobacion;
use App\Services\Produccion\ServicioDeProduccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nada se imprime sin que el cliente haya aprobado la prueba.
 *
 * ESTA ES LA PROMESA CENTRAL DEL PRODUCTO, Y POR ESO SE PRUEBA POR LAS DOS
 * CAPAS.
 *
 * Capa 1: el servicio, que da un mensaje que el taller entiende y puede
 * seguir. Capa 2: el gatillo de PostgreSQL (migracion 000015), que impide el
 * INSERT aunque alguien se saltee el servicio.
 *
 * Se prueban las dos porque fallan distinto: si el codigo se rompe, el
 * gatillo sigue sosteniendo la regla; si el gatillo no esta, un script
 * cualquiera puede imprimir 5.000 tarjetas sin que nadie lo haya aprobado.
 */
class SinPruebaAprobadaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $empresa;

    private Papel $papel;

    private Usuario $preparador;

    private Usuario $supervisor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Tenant::factory()->create();

        $plancha = Plancha::factory()->de($this->empresa)->deReferencia()->create();

        $this->papel = Papel::factory()
            ->de($this->empresa)
            ->dePlancha($plancha)
            ->offset120()
            ->conMerma(4.0, 2.0)
            ->create();

        $this->preparador = Usuario::factory()->de($this->empresa)->create();
        $this->supervisor = Usuario::factory()->de($this->empresa)->supervisor()->create();
    }

    #[Test]
    public function un_pedido_sin_pruebas_no_se_puede_aprobar(): void
    {
        $pedido = $this->pedidoConItem();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido): void {
            try {
                app(ServicioDeAprobacion::class)->aprobar($pedido, $this->supervisor);
                $this->fail('Se esperaba que la aprobacion fallara sin pruebas.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('pedido_sin_pruebas_aprobadas', $e->codigo());
                $this->assertStringContainsString('prueba aprobada', $e->getMessage());
            }
        });
    }

    #[Test]
    public function un_pedido_aprobado_solo_tiene_las_pruebas_aprobadas_registradas(): void
    {
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido, $item): void {
            $prueba = $this->pruebaPara($item, EstadoPrueba::APROBADA);

            $aprobado = app(ServicioDeAprobacion::class)->aprobar($pedido, $this->supervisor);

            $this->assertSame(EstadoPedido::APROBADO, $aprobado->estado);

            // Y queda CONSTANCIA de quien registro la respuesta del cliente.
            // Eso es lo que hace auditable el pedido meses despues.
            $this->assertSame($this->supervisor->id, $aprobado->aprobado_por);
            $this->assertNotNull($aprobado->aprobado_en);

            $prueba->refresh();
            $this->assertTrue($prueba->habilitaProduccion());
        });
    }

    #[Test]
    public function un_preparador_no_puede_aprobar_una_prueba(): void
    {
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido, $item): void {
            $prueba = $this->pruebaPara($item, EstadoPrueba::ENVIADA);

            try {
                app(ServicioDeAprobacion::class)->registrarAprobacion($prueba, $this->preparador);
                $this->fail('Se esperaba que un preparador no pudiera aprobar.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('sin_permiso_aprobar', $e->codigo());
            }
        });
    }

    #[Test]
    public function una_prueba_rechazada_no_habilita_la_produccion(): void
    {
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido, $item): void {
            $prueba = $this->pruebaPara($item, EstadoPrueba::RECHAZADA);

            try {
                app(ServicioDeAprobacion::class)->aprobar($pedido, $this->supervisor);
                $this->fail('Una prueba rechazada no debe habilitar la produccion.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('pedido_sin_pruebas_aprobadas', $e->codigo());
            }

            $prueba->refresh();
            $this->assertFalse($prueba->habilitaProduccion());
        });
    }

    #[Test]
    public function una_prueba_vencida_no_habilita_la_produccion(): void
    {
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido, $item): void {
            // Enviada hace 10 dias, con el plazo por omision en 5. VENCIDO NO
            // ES APROBADO: el sistema nunca aprueba por silencio.
            $this->pruebaPara($item, EstadoPrueba::ENVIADA, enviadosHace: 10);

            try {
                app(ServicioDeAprobacion::class)->aprobar($pedido, $this->supervisor);
                $this->fail('Una prueba vencida no debe aprobar el pedido.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('pedido_sin_pruebas_aprobadas', $e->codigo());
            }
        });
    }

    #[Test]
    public function no_se_registra_produccion_de_un_item_sin_prueba_aprobada(): void
    {
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido, $item): void {
            $pedido->estado = EstadoPedido::APROBADO;
            $pedido->save();

            $this->pruebaPara($item, EstadoPrueba::ENVIADA);

            try {
                app(ServicioDeProduccion::class)->registrar($item, $this->preparador);
                $this->fail('Se esperaba que la produccion se rechazara sin prueba aprobada.');
            } catch (ErrorDeImprenta $e) {
                $this->assertSame('sin_prueba_aprobada', $e->codigo());
                $this->assertStringContainsString('todavia no aprueba', $e->getMessage());
            }
        });
    }

    #[Test]
    public function la_base_de_datos_tambien_impide_registrar_produccion_sin_prueba(): void
    {
        // Esta prueba NO pasa por el servicio: escribe directo, como lo haria
        // un script, una consola o un informe futuro. Si aqui entra, el
        // aislamiento entre el codigo y la base no es cierto.
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($item): void {
            try {
                DB::table('ordenes_produccion')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'tenant_id' => $this->empresa->id,
                    'pedido_item_id' => $item->id,
                    'papel_id' => $this->papel->id,
                    'pliegos_netos' => 10,
                    'pliegos_prueba' => 1,
                    'pliegos_merma' => 1,
                    'pliegos_totales' => 12,
                    'producto_bueno' => 1400,
                    'usuario_id' => $this->preparador->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->fail('El gatillo de PostgreSQL deberia haber rechazado el INSERT.');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('prueba aprobada', $e->getMessage());
            }
        });
    }

    #[Test]
    public function con_prueba_aprobada_la_produccion_si_se_registra(): void
    {
        $pedido = $this->pedidoConItem();
        $item = $pedido->items()->first();

        $this->comoEmpresa($this->empresa->id, function () use ($pedido, $item): void {
            $this->pruebaPara($item, EstadoPrueba::APROBADA);

            app(ServicioDeAprobacion::class)->aprobar($pedido, $this->supervisor);

            $orden = app(ServicioDeProduccion::class)->registrar($item, $this->preparador);

            // 5.000 tarjetas de 90 x 50 mm, 140 por plancha: 36 pliegos
            // netos. Con 4 % y 2 % de merma, mas pliegos.
            $this->assertSame(36, $orden->pliegos_netos);
            $this->assertSame(2, $orden->pliegos_prueba);
            $this->assertSame(1, $orden->pliegos_merma);
            $this->assertSame(39, $orden->pliegos_totales);

            // La suma tiene que cuadrar: un pliego perdido escondido en un
            // total inflado es justamente lo que el CHECK impide.
            $this->assertSame(
                $orden->pliegos_netos + $orden->pliegos_prueba + $orden->pliegos_merma,
                $orden->pliegos_totales
            );

            $pedido->refresh();
            $this->assertSame(EstadoPedido::EN_PRODUCCION, $pedido->estado);
        });
    }

    /**
     * Un pedido con un solo item de tarjetas, listo para aprobar.
     */
    private function pedidoConItem(): Pedido
    {
        return $this->comoEmpresa($this->empresa->id, function (): Pedido {
            $pedido = Pedido::factory()->de($this->empresa)->create();

            PedidoItem::create([
                'tenant_id' => $this->empresa->id,
                'pedido_id' => $pedido->id,
                'papel_id' => $this->papel->id,
                'descripcion' => '5.000 tarjetas de presentacion',
                'cantidad_unidades' => 5_000,
                'ancho_mm' => 90,
                'alto_mm' => 50,
                'n_por_ventana' => 1,
            ]);

            return $pedido->fresh();
        });
    }

    /**
     * Crea una prueba en el estado indicado, con su arte.
     */
    private function pruebaPara(
        PedidoItem $item,
        EstadoPrueba $estado,
        int $enviadosHace = 0
    ): Prueba {
        $arte = Arte::create([
            'tenant_id' => $item->tenant_id,
            'pedido_item_id' => $item->id,
            'version' => 1,
            'nombre_archivo' => 'tarjetas.pdf',
            'url' => 'https://archivos.ejemplo.cl/tarjetas.pdf',
            'sha256' => str_repeat('b', 64),
            'subido_por' => $this->preparador->id,
        ]);

        $aprobada = $estado === EstadoPrueba::APROBADA;

        return Prueba::create([
            'tenant_id' => $item->tenant_id,
            'pedido_item_id' => $item->id,
            'arte_id' => $arte->id,
            'version' => 1,
            'estado' => $estado->value,
            'enviada_en' => $enviadosHace > 0 ? now()->subDays($enviadosHace) : now(),
            'resuelta_en' => $aprobada ? now() : null,
            'aprobada_por' => $aprobada ? $this->supervisor->id : null,
        ]);
    }
}
