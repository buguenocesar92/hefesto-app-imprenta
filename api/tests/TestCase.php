<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas.
 *
 * LA BASE ES POSTGRESQL, NO SQLITE, Y NO ES POR COMODIDAD.
 *
 * Todo lo que de verdad importa de esta aplicacion vive en PostgreSQL:
 *
 *  - El aislamiento entre imprentas es Row Level Security. En SQLite no
 *    existen las politicas, asi que la prueba pasaria sin comprobar nada: una
 *    suite verde sobre SQLite no dice nada del aislamiento.
 *
 *  - "Nada se imprime sin prueba aprobada" esta en un gatillo de
 *    PostgreSQL (migracion 000015). En SQLite no hay gatillos: la prueba
 *    pasaria sin comprobar la regla.
 *
 *  - Los CHECK de que los pliegos cuadren, la merma este en rango y el stock
 *    de libreria no quede negativo, tambien son de PostgreSQL.
 *
 * Por eso `phpunit.xml` fija `DB_CONNECTION=pgsql`. Ver docs/10.
 *
 * Ver apps/almacen/tests/TestCase.php: es el mismo patron.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Corre la operacion con el contexto de empresa fijado.
     *
     * Es el mismo camino que usa el middleware `ResolverTenant`, y se usa en
     * las pruebas para que escriban bajo la misma RLS que en produccion. Si
     * una prueba escribiera fuera de ese contexto, no estaria probando la
     * aplicacion sino una base sin politicas.
     */
    protected function comoEmpresa(string $tenantId, callable $operacion): mixed
    {
        return app(ContextoTenant::class)->ejecutarAislado($tenantId, $operacion);
    }
}
