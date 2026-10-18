<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ErrorDeImprenta;
use App\Services\Papel\CalculadoraDeMerma;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Merma: el papel que se gasta sin llegar a ser producto.
 *
 * LA PRUEBA CENTRAL DE ESTA SUITE ES QUE NO HAY VALOR POR OMISION.
 *
 * Si el papel no tiene los porcentajes cargados, el calculo TIENE que
 * fallar. Si en vez de fallar devolviera "5 %", cada trabajo ganaria un 5 %
 * de papel fantasma y el margen de la imprenta desapareceria sin que nadie
 * lo viera. Por eso se prueba el fallo, y no solo el acierto.
 */
class CalculadoraDeMermaTest extends TestCase
{
    private CalculadoraDeMerma $calculadora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculadora = new CalculadoraDeMerma();
    }

    #[Test]
    public function la_merma_se_parte_por_origen_y_no_es_un_total_opaco(): void
    {
        $mermas = $this->calculadora->calcular(100, 4.0, 2.0);

        $this->assertCount(2, $mermas);

        // Papel de prueba: 4 % de 100 = 4 pliegos.
        $this->assertSame('prueba', $mermas[0]->origen);
        $this->assertSame(4, $mermas[0]->pliegos);

        // Papel danado en la plancha: 2 % de 100 = 2 pliegos.
        $this->assertSame('plancha', $mermas[1]->origen);
        $this->assertSame(2, $mermas[1]->pliegos);
    }

    #[Test]
    public function sin_merma_de_prueba_cargada_el_calculo_falla_cerrado(): void
    {
        $this->expectException(ErrorDeImprenta::class);
        $this->expectExceptionMessage('merma de prueba');

        $this->calculadora->calcular(100, null, 2.0);
    }

    #[Test]
    public function sin_merma_de_plancha_cargada_el_calculo_tambien_falla_cerrado(): void
    {
        $this->expectException(ErrorDeImprenta::class);
        $this->expectExceptionMessage('merma de plancha');

        $this->calculadora->calcular(100, 4.0, null);
    }

    #[Test]
    public function sin_ninguno_de_los_dos_porcentajes_falla_antes_de_inventar(): void
    {
        $this->expectException(ErrorDeImprenta::class);

        $this->calculadora->calcular(1_000, null, null);
    }

    #[Test]
    #[DataProvider('porcentajesInvalidos')]
    public function un_porcentaje_fuera_de_rango_se_rechaza_en_vez_de_recortarse(float $porcentaje): void
    {
        // Un -5 % de merma no es un -5 %: es un dato malo. Recortarlo a 0
        // haria que el sistema dijera "no se pierde papel" cuando el taller
        // sabe que se pierde.
        $this->expectException(ErrorDeImprenta::class);
        $this->expectExceptionMessage('deberia estar entre 0 y 100');

        $this->calculadora->calcular(100, $porcentaje, 2.0);
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function porcentajesInvalidos(): array
    {
        return [
            'negativo' => [-5.0],
            'sobre cien' => [150.0],
        ];
    }

    #[Test]
    public function los_pliegos_de_merma_se_redondean_hacia_arriba_para_no_perder_papel(): void
    {
        // 10 pliegos netos con 4 % de merma dan 0,4 pliegos. Redondear al
        // piso seria 0: el taller gastaria 0 pliegos de prueba y el papel
        // "sobraria" de una partida que en realidad se perdio.
        $mermas = $this->calculadora->calcular(10, 4.0, 2.0);

        $this->assertSame(1, $mermas[0]->pliegos);
        $this->assertSame(1, $mermas[1]->pliegos);
    }

    #[Test]
    public function una_merma_real_se_reporta_al_lado_de_la_prevista(): void
    {
        $mermas = $this->calculadora->calcular(
            pliegosNetos: 100,
            mermaPruebaPorcentaje: 4.0,
            mermaPlanchaPorcentaje: 2.0,
            pliegosPruebaReales: 9,
            pliegosMermaReales: 1,
        );

        // La prevista era 4 %, la real fue 9 %. Esa diferencia es la que
        // sirve para corregir el catalogo.
        $this->assertSame(4.0, $mermas[0]->porcentaje);
        $this->assertSame(9.0, $mermas[0]->porcentajeReal);

        $this->assertSame(2.0, $mermas[1]->porcentaje);
        $this->assertSame(1.0, $mermas[1]->porcentajeReal);
    }

    #[Test]
    public function sin_corrida_medida_la_merma_real_es_null_y_no_cero(): void
    {
        $mermas = $this->calculadora->calcular(100, 4.0, 2.0);

        // NULL y no cero: decir cero seria afirmar que no se perdio nada.
        // Todavia no hay corrida que lo haya medido.
        $this->assertNull($mermas[0]->porcentajeReal);
        $this->assertNull($mermas[1]->porcentajeReal);
    }
}
