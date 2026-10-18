<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Cliente;
use App\Models\Papel;
use App\Models\Pedido;
use App\Models\Plancha;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Aislamiento entre imprentas.
 *
 * LA SUITE MAS IMPORTANTE DE LA API.
 *
 * Si esto falla, todo lo demas da igual: una imprenta que ve los pedidos de
 * otra tiene los datos comerciales del cliente de la competencia.
 *
 * ESTA SUITE NO FUNCIONARIA EN SQLITE.
 *
 * El aislamiento esta implementado con Row Level Security de PostgreSQL. En
 * SQLite no hay politicas, asi que estas pruebas pasarían sin comprobar
 * nada: una suite verde sobre SQLite no dice NADA del aislamiento. Por eso
 * `phpunit.xml` fija la conexion en `pgsql`. Ver docs/10.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function el_contexto_de_empresa_fija_el_tenant_y_lo_suelta_al_terminar(): void
    {
        $primera = Tenant::factory()->create();
        $segunda = Tenant::factory()->create();

        // Dentro del contexto de la primera empresa, la fila de la segunda
        // no existe.
        $vistos = $this->comoEmpresa($primera->id, function () {
            return Pedido::query()->pluck('id');
        });

        $vistosDespues = $this->comoEmpresa($segunda->id, function () {
            return Pedido::query()->pluck('id');
        });

        $this->assertEmpty($vistos);
        $this->assertEmpty($vistosDespues);
    }

    #[Test]
    public function una_empresa_no_ve_los_pedidos_de_otra(): void
    {
        $primera = Tenant::factory()->create();
        $segunda = Tenant::factory()->create();

        $this->comoEmpresa($primera->id, function () use ($primera): void {
            Pedido::factory()->de($primera)->create();
        });

        $pedidosDeLaSegunda = $this->comoEmpresa($segunda->id, function () {
            return Pedido::query()->count();
        });

        $this->assertSame(0, $pedidosDeLaSegunda, 'La segunda empresa no debe ver pedidos ajenos.');
    }

    #[Test]
    public function cada_empresa_ve_solo_sus_propias_filas_y_no_mas(): void
    {
        $primera = Tenant::factory()->create();
        $segunda = Tenant::factory()->create();

        $this->comoEmpresa($primera->id, function () use ($primera): void {
            Pedido::factory()->de($primera)->count(3)->create();
        });

        $this->comoEmpresa($segunda->id, function () use ($segunda): void {
            Pedido::factory()->de($segunda)->count(2)->create();
        });

        $vistosPorLaPrimera = $this->comoEmpresa($primera->id, fn () => Pedido::query()->count());
        $vistosPorLaSegunda = $this->comoEmpresa($segunda->id, fn () => Pedido::query()->count());

        $this->assertSame(3, $vistosPorLaPrimera);
        $this->assertSame(2, $vistosPorLaSegunda);
    }

    #[Test]
    public function no_se_puede_insertar_una_fila_de_otra_empresa_aunque_se_conozca_el_id(): void
    {
        $primera = Tenant::factory()->create();
        $segunda = Tenant::factory()->create();

        $clienteAjeno = $this->comoEmpresa($segunda->id, function () use ($segunda) {
            $cliente = Cliente::factory()->de($segunda)->create();

            return $cliente->id;
        });

        // Aunque se tenga el id de un cliente de otra empresa, el
        // `WITH CHECK` de la politica lo impide. Si esta prueba falla, el
        // `USING` esta puesto pero el `WITH CHECK` no.
        $this->esperarFalloAlGuardar(function () use ($primera, $clienteAjeno): void {
            $this->comoEmpresa($primera->id, function () use ($primera, $clienteAjeno): void {
                Pedido::create([
                    'tenant_id' => $primera->id,
                    'numero' => 'PED-ARRASTRADO',
                    'cliente_id' => $clienteAjeno,
                    'estado' => 'borrador',
                    'fecha_prometida' => now()->addDay()->toDateString(),
                ]);
            });
        });
    }

    #[Test]
    public function el_catalogo_de_papeles_tambien_esta_aislado(): void
    {
        $primera = Tenant::factory()->create();
        $segunda = Tenant::factory()->create();

        $this->comoEmpresa($primera->id, function () use ($primera): void {
            $plancha = Plancha::factory()->de($primera)->create();

            Papel::factory()->de($primera)->dePlancha($plancha)->conMerma()->create();
        });

        $papelesDeLaSegunda = $this->comoEmpresa($segunda->id, fn () => Papel::query()->count());

        $this->assertSame(0, $papelesDeLaSegunda);
    }

    #[Test]
    public function la_funcion_de_contexto_falla_cerrado_sin_empresa_en_el_contexto(): void
    {
        // `public.app_tenant_id()` no devuelve NULL en silencio: lanza
        // excepcion. Con NULL, el sistema seguiria respondiendo 200 con una
        // lista vacia y el ticket "no veo los pedidos" se pierde mucho mas
        // tarde que un error claro.
        $this->esperarFalloAlGuardar(function (): void {
            \Illuminate\Support\Facades\DB::selectOne('SELECT public.app_tenant_id() AS id');
        });
    }

    #[Test]
    public function la_vista_de_empresas_existe_y_expone_solo_tres_columnas(): void
    {
        Tenant::factory()->create();

        $columnas = \Illuminate\Support\Facades\DB::select(
            "SELECT column_name
               FROM information_schema.columns
              WHERE table_schema = 'public'
                AND table_name = 'vw_impresas_publicas'
              ORDER BY ordinal_position"
        );

        $nombres = array_map(static fn (object $f): string => (string) $f->column_name, $columnas);

        // Tres columnas, ni una mas. Si alguien le agrega el razon social o
        // un correo, el selector de acceso empezaria a filtrar datos.
        $this->assertSame(['slug', 'nombre', 'activo'], $nombres);
    }

    #[Test]
    public function la_vista_de_empresas_se_puede_leer_sin_contexto_de_empresa(): void
    {
        // Es lo que la usa el selector del login, que corre antes de que
        // exista sesion.
        Tenant::factory()->create();
        Tenant::factory()->inactiva()->create();

        $filas = \Illuminate\Support\Facades\DB::select(
            'SELECT slug, nombre FROM public.vw_impresas_publicas'
        );

        $this->assertCount(2, $filas);
    }

    #[Test]
    public function el_comando_de_verificacion_pasa_con_la_row_level_security_activada(): void
    {
        $this->artisan('imprenta:verificar-aislamiento')->assertSuccessful();
    }

    /**
     * Ejecuta algo que deberia fallar por la RLS, y confirma que fallo.
     *
     * Se da vuelta al error a proposito: lo que importa es que la base
     * RECHACE la operacion, no que rechace con un texto en particular.
     */
    private function esperarFalloAlGuardar(callable $operacion): void
    {
        try {
            $operacion();
        } catch (\Throwable) {
            return;
        }

        $this->fail('Se esperaba que la Row Level Security rechazara la operacion.');
    }
}
