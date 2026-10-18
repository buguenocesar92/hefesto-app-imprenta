<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ErrorDeImprenta;
use App\Models\Papel;
use App\Models\PedidoItem;
use App\Models\Plancha;
use App\Services\Papel\CalculadoraDeConsumo;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Conversion de unidades de papel: pliegos, kilos y piezas.
 *
 * POR QUE ESTA SUITE ES LA MAS IMPORTANTE DEL ARCHIVO
 *
 * Es la pregunta que va a hacer el primer cliente al ver el sistema: "por que
 * 5.000 tarjetas de 9 x 5 cm necesitan tantos pliegos?". Si esa cuenta esta
 * mal, o no se puede explicar, el sistema no sirve para cotizar nada.
 *
 * Y POR QUE NO HAY UN NUMERO FIJO
 *
 * Los casos de esta suite demuestran que el MISMO papel rinde cifras muy
 * distintas segun la pieza, y que el mismo trabajo rinde distinto segun el
 * gramaje. Por eso el factor se calcula por item y no se guarda en el
 * catalogo.
 *
 * Todas las cuentas salen de medidas FIJAS (plancha de 700 x 1000 mm), no
 * de numeros aleatorios: una prueba de calculo con valores al azar no
 * verifica nada, solo que el codigo no se caiga.
 */
class CalculadoraDeConsumoTest extends TestCase
{
    private CalculadoraDeConsumo $calculadora;

    /** Plancha de 70 x 100 cm, la medida de referencia del taller. */
    private Plancha $plancha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculadora = app(CalculadoraDeConsumo::class);
        $this->plancha = Plancha::factory()->deReferencia()->create();
    }

    #[Test]
    public function el_rendimiento_depende_de_la_medida_de_la_pieza_y_no_solo_del_papel(): void
    {
        // En una plancha de 700 x 1000 mm caben 7 x 20 = 140 tarjetas de
        // 90 x 50 mm. Con la misma plancha, hojas membretadas de 210 x 297 mm
        // rinden 3 x 3 = 9. Mismo papel, cifras que no se parecen.
        $tarjetas = $this->item(cantidad: 5_000, ancho: 90, alto: 50);
        $membretadas = $this->item(cantidad: 5_000, ancho: 210, alto: 297);

        $consumoTarjetas = $this->calculadora->calcular($tarjetas);
        $consumoMembretadas = $this->calculadora->calcular($membretadas);

        $this->assertSame(140, $consumoTarjetas->unidadesPorPliego);
        $this->assertSame(9, $consumoMembretadas->unidadesPorPliego);

        // Y el mismo trabajo de 5.000 piezas sale en 36 pliegos contra 556.
        // Un factor unico guardado en el catalogo de papeles haria que una de
        // las dos cotizaciones fuera falsa.
        $this->assertSame(36, $consumoTarjetas->pliegosNetos);
        $this->assertSame(556, $consumoMembretadas->pliegosNetos);
    }

    #[Test]
    public function el_gramaje_cambia_el_peso_pero_no_el_rendimiento(): void
    {
        $liviano = $this->item(cantidad: 5_000, ancho: 90, alto: 50, gramaje: 120);
        $pesado = $this->item(cantidad: 5_000, ancho: 90, alto: 50, gramaje: 300);

        $consumoLiviano = $this->calculadora->calcular($liviano);
        $consumoPesado = $this->calculadora->calcular($pesado);

        // El rendimiento depende de la MEDIDA, no del gramaje: por eso se
        // calcula por item y no se busca en el catalogo de papeles.
        $this->assertSame(
            $consumoLiviano->unidadesPorPliego,
            $consumoPesado->unidadesPorPliego
        );

        // Pero el peso cambia de forma brutal: 0,7 m2 a 120 g/m2 son 84 g y
        // a 300 g/m2 son 210 g. Con la misma cantidad de pliegos, comprar uno
        // u otro son kilos distintos.
        $this->assertEqualsWithDelta(84.0, $consumoLiviano->gramosPorPliego, 0.1);
        $this->assertEqualsWithDelta(210.0, $consumoPesado->gramosPorPliego, 0.1);
    }

    #[Test]
    public function los_kilos_que_hay_que_comprar_dependen_del_gramaje(): void
    {
        $liviano = $this->item(cantidad: 200, ancho: 210, alto: 297, gramaje: 120);
        $pesado = $this->item(cantidad: 200, ancho: 210, alto: 297, gramaje: 300);

        $consumoLiviano = $this->calculadora->calcular($liviano);
        $consumoPesado = $this->calculadora->calcular($pesado);

        // Con 9 hojas por plancha, 200 piezas piden 23 pliegos netos, mas 1
        // de prueba y 1 de plancha: 25 en total. A 120 g/m2 eso son
        // 25 x 84 g = 2.100 g = 2,1 kg. A 300 g/m2 son 25 x 210 g =
        // 5.250 g = 5,25 kg. El mismo trabajo, el doble de papel.
        $this->assertSame(23, $consumoLiviano->pliegosNetos);
        $this->assertEqualsWithDelta(2.1, $consumoLiviano->kilosRequeridos, 0.01);
        $this->assertEqualsWithDelta(5.25, $consumoPesado->kilosRequeridos, 0.01);
    }

    #[Test]
    public function una_pieza_que_solo_cabe_rotada_se_calcula_rotada(): void
    {
        // 900 x 650 mm no cabe normal (900 > 700 del ancho), pero girada
        // caben 700 x 900 contra la plancha de 700 x 1000: una por cara.
        $item = $this->item(cantidad: 10, ancho: 900, alto: 650);

        $consumo = $this->calculadora->calcular($item);

        $this->assertSame('rotada', $consumo->orientacion);
        $this->assertSame(1, $consumo->unidadesPorPliego);
        $this->assertSame(10, $consumo->pliegosNetos);
    }

    #[Test]
    public function la_agrupacion_aumenta_el_rendimiento_por_plancha(): void
    {
        $suelto = $this->item(cantidad: 100_000, ancho: 90, alto: 50, agrupacion: 1);
        $agrupado = $this->item(cantidad: 100_000, ancho: 90, alto: 50, agrupacion: 4);

        $consumoSuelto = $this->calculadora->calcular($suelto);
        $consumoAgrupado = $this->calculadora->calcular($agrupado);

        // Con agrupacion de 4 entran cuatro veces mas piezas por plancha:
        // 140 pasa a 560, no 61.600. Agrupar es imprimir cuatro tiras del
        // mismo trabajo juntas en la misma cara.
        $this->assertSame(140, $consumoSuelto->unidadesPorPliego);
        $this->assertSame(560, $consumoAgrupado->unidadesPorPliego);

        // 100.000 piezas salen en 715 pliegos sueltas y en 179 agrupadas. Es
        // la diferencia entre tirar la plancha de sobra o no.
        $this->assertSame(715, $consumoSuelto->pliegosNetos);
        $this->assertSame(179, $consumoAgrupado->pliegosNetos);
        $this->assertLessThan($consumoSuelto->pliegosNetos, $consumoAgrupado->pliegosNetos);
    }

    #[Test]
    public function una_pieza_que_no_cabe_en_la_plancha_falla_con_un_mensaje_util(): void
    {
        // 1200 x 800 mm no cabe ni normal ni rotada.
        $item = $this->item(cantidad: 10, ancho: 1200, alto: 800);

        $this->expectException(ErrorDeImprenta::class);
        $this->expectExceptionMessage('no cabe');

        $this->calculadora->calcular($item);
    }

    #[Test]
    public function el_desglose_explica_la_cuenta_paso_a_paso(): void
    {
        $desglose = $this->calculadora->calcular(
            $this->item(cantidad: 5_000, ancho: 90, alto: 50)
        );

        // Sin esto el taller no puede discutir el calculo, y una cotizacion
        // que no se puede discutir no se firma.
        $this->assertNotEmpty($desglose->pasos);

        $texto = implode("\n", $desglose->pasos);

        $this->assertStringContainsString('Plancha', $texto);
        $this->assertStringContainsString('Mejor ajuste', $texto);
        $this->assertStringContainsString('Pliegos netos', $texto);
        $this->assertStringContainsString('Merma', $texto);
        $this->assertStringContainsString('Total a gastar', $texto);

        // Y el desglose trae la merma partida por origen, no un total opaco.
        $this->assertCount(2, $desglose->mermas);
        $this->assertSame('prueba', $desglose->mermas[0]->origen);
        $this->assertSame('plancha', $desglose->mermas[1]->origen);
    }

    #[Test]
    public function un_papel_sin_merma_configurada_falla_cerrado_y_no_inventa(): void
    {
        // Sin `conMerma()`: los porcentajes vienen en NULL.
        $item = $this->item(cantidad: 1_000, ancho: 90, alto: 50, conMerma: false);

        $this->expectException(ErrorDeImprenta::class);
        $this->expectExceptionMessage('merma');

        $this->calculadora->calcular($item);
    }

    /**
     * Arma un item de pedido con medidas y papel fijos, sobre la plancha de
     * referencia de la prueba.
     */
    private function item(
        int $cantidad,
        int $ancho,
        int $alto,
        int $gramaje = 120,
        int $agrupacion = 1,
        bool $conMerma = true
    ): PedidoItem {
        $papel = Papel::factory()
            ->dePlancha($this->plancha)
            ->offset120()
            ->state(['gramaje_gm2' => $gramaje])
            ->when($conMerma, fn ($f) => $f->conMerma(4.0, 2.0))
            ->create();

        // El calculador lee la relacion, no la base. Se carga aqui para que
        // la prueba no dependa de un id guardado.
        $papel->setRelation('plancha', $this->plancha);

        $item = new PedidoItem([
            'tenant_id' => $this->plancha->tenant_id,
            'papel_id' => $papel->id,
            'descripcion' => 'Prueba de conversion',
            'cantidad_unidades' => $cantidad,
            'ancho_mm' => $ancho,
            'alto_mm' => $alto,
            'n_por_ventana' => $agrupacion,
        ]);

        $item->id = (string) Str::uuid();
        $item->setRelation('papel', $papel);

        return $item;
    }
}
