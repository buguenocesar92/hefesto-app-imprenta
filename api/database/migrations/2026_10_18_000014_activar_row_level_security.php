<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aislamiento entre imprentas: Row Level Security.
 *
 * ESTE ES EL MISMO PATRON DE `apps/almacen`. No es una adaptacion: es el
 * patron. Cambiarlo seria inventar un cuarto esquema de aislamiento, que es
 * exactamente el defecto que este encargo quiere evitar.
 *
 * Las tres piezas, y las tres hacen falta:
 *
 *  1. Politica con `USING` (lectura) y `WITH CHECK` (escritura). El `WITH
 *     CHECK` es lo que impide INSERTAR una fila de otra empresa.
 *  2. `FORCE ROW LEVEL SECURITY`. Sin esto, el dueño de la tabla (el rol
 *     que la creo) no pasa por la politica, y como la migraciones corren con
 *     ese rol, el aislamiento seria falso justamente en el camino normal.
 *  3. El contexto: `set_config('app.tenant_id', ..., true)` con alcance de
 *     TRANSACCION. Lo pone `ContextoTenant`.
 *
 * La tabla `tenants` NO lleva RLS a proposito: se consulta en el login,
 * cuando todavia no hay `app.tenant_id`. Por eso existe la vista
 * `vw_impresas_publicas`, que expone solo tres columnas y solo de empresas
 * activas. La consulta esa SÍ se usa de verdad: `AuthController::empresas()`
 * lee la vista, y el selector del login muestra lo que devuelve.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
return new class extends Migration
{
    /**
     * Tablas que llevan `tenant_id` y por lo tanto van protegidas.
     *
     * @var list<string>
     */
    private array $tablas = [
        'usuarios',
        'planchas',
        'papeles',
        'clientes',
        'pedidos',
        'pedido_items',
        'artes',
        'pruebas',
        'ordenes_produccion',
        'entregas',
        'articulos_libreria',
        'movimientos_libreria',
    ];

    public function up(): void
    {
        // --- 1. La funcion de contexto, que falla cerrado ---------------
        //
        // Sin tenant fijado devuelve `NULL`. La politica compara
        // `tenant_id = NULL`, que en PostgreSQL es NULL y no `false`: el
        // `WHERE` no trae la fila. Es decir, tambien oculta. La diferencia
        // es que el sistema seguiria respondiendo 200 con una lista vacia, y
        // un ticket "no veo los pedidos" se pierde mucho mas tarde que un
        // error claro. Ante la duda, cortar.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid AS $$
            BEGIN
                RETURN NULLIF(current_setting('app.tenant_id', true), '')::uuid;
            EXCEPTION WHEN others THEN
                -- Falla cerrado: si el contexto esta corrupto, se corta.
                RAISE EXCEPTION USING
                    ERRCODE = '42501',
                    MESSAGE = 'No hay empresa en el contexto de la sesion.',
                    HINT = 'Falta fijar app.tenant_id con set_config(..., true).';
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);

        // --- 2. Politicas por tabla -------------------------------------
        foreach ($this->tablas as $tabla) {
            DB::unprepared(sprintf(<<<'SQL'
                ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY;
                ALTER TABLE public.%s FORCE ROW LEVEL SECURITY;

                DROP POLICY IF EXISTS %s_aislamiento ON public.%s;
                CREATE POLICY %s_aislamiento ON public.%s
                    FOR ALL
                    TO PUBLIC
                    USING (tenant_id = public.app_tenant_id())
                    WITH CHECK (tenant_id = public.app_tenant_id());
            SQL, $tabla, $tabla, $tabla, $tabla, $tabla, $tabla));
        }

        // --- 3. La vista publica de empresas ---------------------------
        //
        // `tenants` no lleva RLS, y su contenido es de por si publico (son
        // las empresas que existen). Lo que no debe salir en un selector de
        // acceso es el razon social, el correo ni nada que no sirva para
        // elegir a donde entrar. Por eso la vista expone SOLO tres columnas,
        // y solo de empresas activas.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE VIEW public.vw_impresas_publicas AS
                SELECT slug, nombre, activo
                  FROM public.tenants;

            COMMENT ON VIEW public.vw_impresas_publicas IS
                'Columnas publicas para el selector de acceso. Sin RLS a proposito: '
                . 'se consulta antes de que exista app.tenant_id. La lee '
                . 'App\Http\Controllers\AuthController::empresas().';
        SQL);
    }

    public function down(): void
    {
        foreach ($this->tablas as $tabla) {
            DB::unprepared(sprintf('DROP POLICY IF EXISTS %s_aislamiento ON public.%s;', $tabla, $tabla));
        }

        DB::unprepared('DROP VIEW IF EXISTS public.vw_impresas_publicas;');
        DB::unprepared('DROP FUNCTION IF EXISTS public.app_tenant_id();');
    }
};
