<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fija la empresa de la peticion para que la RLS de PostgreSQL sepa de quien
 * son las filas.
 *
 * MECANISMO
 * ---------
 * `set_config('app.tenant_id', ..., true)` con `true` significa alcance de
 * TRANSACCION: el valor desaparece al terminar la transaccion. Con `false`
 * (que es el omision) el valor queda pegado a la conexion y se loeria a la
 * siguiente peticion que use el mismo enlace del pool, que podria ser de otra
 * empresa. Por eso el `true` no es un detalle: es la garantia.
 *
 * FALLA CERRADO
 * -------------
 * `public.app_tenant_id()` lanza excepcion en vez de devolver `NULL`.
 *
 * Por que importa: con `NULL`, la politica `USING (tenant_id = NULL)` da
 * `NULL`, que en PostgreSQL es distinto de `falso`, y la fila NO se muestra.
 * Es decir, devolver `NULL` tambien oculta filas. La diferencia es que el
 * sistema sigue respondiendo 200 con una lista vacia, y un ticket
 * "me aparecen cero pedidos" se pierde mucho mas tarde que un 500 con
 * mensaje claro. Ante la duda, cortar.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
class ContextoTenant
{
    /**
     * Ejecuta la operacion aislada a una empresa.
     *
     * @template TResult
     *
     * @param  string  $tenantId  UUID de la empresa.
     * @param  Closure(): TResult  $operacion  Lo que se hace dentro.
     * @return TResult
     */
    public function ejecutarAislado(string $tenantId, Closure $operacion): mixed
    {
        return DB::transaction(function () use ($tenantId, $operacion) {
            // `true` = alcance de transaccion. Sin esto el valor sobrevive
            // al cierre y se filtra a la siguiente peticion del pool.
            DB::selectOne('SELECT set_config(?, ?, true)', [
                'app.tenant_id',
                $tenantId,
            ]);

            return $operacion();
        });
    }

    /**
     * Verifica que la conexion no pueda saltarse la RLS.
     *
     * Un rol con `BYPASSRLS` o superusuario ignora todas las politicas. En ese
     * caso el aislamiento es puro adorno, aunque el codigo este impecable.
     * Esta comprobacion es la unica que detecta ese fallo.
     *
     * @throws RuntimeException si la conexion tiene privilegios de escape.
     */
    public function verificarSinEscapeDeRls(): void
    {
        $filas = DB::select(
            "SELECT rolname, rolbypassrls, rolsuper
               FROM pg_roles
              WHERE rolname = current_user"
        );

        if ($filas === []) {
            throw new RuntimeException(
                'No se pudo determinar el rol de la base de datos en uso.'
            );
        }

        $rol = $filas[0];

        if ((bool) $rol->rolbypassrls === true || (bool) $rol->rolsuper === true) {
            throw new RuntimeException(
                sprintf(
                    'El rol "%s" tiene BYPASSRLS o es superusuario: ignora la Row Level '
                    . 'Security y puede leer todas las imprentas. El aislamiento NO esta '
                    . 'activo. Use un rol sin esos privilegios.',
                    $rol->rolname
                )
            );
        }
    }

    /** Empresa de la peticion actual, o `null` si todavia no hay contexto. */
    public function tenantActual(): ?Tenant
    {
        $id = DB::selectOne("SELECT current_setting('app.tenant_id', true) AS id")?->id;

        if ($id === null || $id === '') {
            return null;
        }

        return Tenant::find($id);
    }
}
