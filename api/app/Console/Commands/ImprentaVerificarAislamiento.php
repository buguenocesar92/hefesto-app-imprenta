<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba que el aislamiento entre imprentas este realmente activo.
 *
 * ESTE COMANDO ES LA BARRA QUE HAY QUE PASAR ANTES DE CONFIAR EN EL
 * AISLAMIENTO.
 *
 * Tres cosas que se comprueban, en este orden, y todas importan:
 *
 *  1. Que el rol de la conexion NO tenga BYPASSRLS ni sea superusuario. Con
 *     cualquiera de las dos, PostgreSQL ignora TODAS las politicas. En ese
 *     caso el codigo puede estar impecable y el aislamiento es puro adorno.
 *
 *  2. Que todas las tablas de empresa tengan RLS habilitada Y forzada. Con
 *     `ENABLE` pero sin `FORCE`, el duena de la tabla no pasa por la
 *     politica, y el duena es el rol con que corren las migraciones.
 *
 *  3. Que este rol tenga alguna politica de lectura sobre las tablas. Con
 *     RLS activa y sin politica, la tabla queda vacia para todos: el
 *     symptoms es "no veo nada", que es distinto de "veo de mas".
 *
 * Uso:
 *   php artisan imprenta:verificar-aislamiento
 *   php artisan imprenta:verificar-aislamiento --vacio
 *
 * La opcion `--vacio` ademas intenta leer una fila sin fijar el contexto de
 * empresa. Debe devolver CERO filas. Si devuelve alguna, hay una fuga.
 */
class ImprentaVerificarAislamiento extends Command
{
    protected $signature = 'imprenta:verificar-aislamiento {--vacio : Ademas intenta leer sin contexto de empresa}';

    protected $description = 'Verifica que la Row Level Security este activa, forzada y sin escapes para este rol.';

    /** Tablas que deben quedar aisladas. */
    private const TABLAS = [
        'usuarios', 'planchas', 'papeles', 'clientes', 'pedidos', 'pedido_items',
        'artes', 'pruebas', 'ordenes_produccion', 'entregas',
        'articulos_libreria', 'movimientos_libreria',
    ];

    public function handle(ContextoTenant $contexto): int
    {
        $problemas = 0;

        // --- 1. El rol no puede saltarse la RLS --------------------------
        try {
            $contexto->verificarSinEscapeDeRls();
            $this->info('Rol sin BYPASSRLS y sin privilegios de superusuario.');
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            $problemas++;

            // No tiene sentido seguir: si el rol escapa, todo lo demas da igual.
            return self::FAILURE;
        }

        // --- 2. RLS activa y forzada en cada tabla -----------------------
        $filas = DB::select(
            "SELECT c.relname AS tabla,
                    c.relrowsecurity AS habilitada,
                    c.relforcerowsecurity AS forzada
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public'
                AND c.relname = ANY(?)",
            ['{' . implode(',', self::TABLAS) . '}']
        );

        $vistas = [];

        foreach ($filas as $fila) {
            $vistas[(string) $fila->tabla] = $fila;
        }

        foreach (self::TABLAS as $tabla) {
            if (! isset($vistas[$tabla])) {
                $this->error(sprintf('La tabla "%s" no existe. Revise las migraciones.', $tabla));
                $problemas++;
                continue;
            }

            if (! (bool) $vistas[$tabla]->habilitada) {
                $this->error(sprintf('La tabla "%s" tiene RLS habilitada en falso.', $tabla));
                $problemas++;
            }

            if (! (bool) $vistas[$tabla]->forzada) {
                $this->error(sprintf(
                    'La tabla "%s" NO tiene FORCE ROW LEVEL SECURITY: el duena de la tabla '
                    . 'puede saltarse la politica.',
                    $tabla
                ));
                $problemas++;
            }
        }

        if ($problemas === 0) {
            $this->info(sprintf('RLS activa y forzada en las %d tablas de empresa.', count(self::TABLAS)));
        }

        // --- 3. Este rol tiene politicas de lectura ----------------------
        $politicas = DB::select(
            "SELECT count(*) AS total
               FROM pg_policies
              WHERE schemaname = 'public'
                AND tablename = ANY(?)",
            ['{' . implode(',', self::TABLAS) . '}']
        );

        $totalPoliticas = (int) ($politicas[0]->total ?? 0);

        if ($totalPoliticas === 0) {
            $this->error('No hay ninguna politica de RLS: las tablas quedarian vacias para todos.');
            $problemas++;
        } else {
            $this->info('Politicas de RLS activas: ' . $totalPoliticas . '.');
        }

        // --- 4. Opcional: leer sin contexto debe dar cero -----------------
        if ($this->option('vacio')) {
            $contexto->ejecutarAislado('00000000-0000-0000-0000-000000000000', function (): void {
                foreach (self::TABLAS as $tabla) {
                    $total = DB::table($tabla)->count();

                    if ($total > 0) {
                        $this->error(sprintf(
                            'FUGA: "%s" devolvio %d filas sin empresa en el contexto.',
                            $tabla,
                            $total
                        ));
                    }
                }
            });

            if ($problemas === 0) {
                $this->info('Sin empresa en el contexto no se lee ninguna fila.');
            }
        }

        if ($problemas > 0) {
            $this->error(sprintf('Aislamiento con %d problema(s).', $problemas));

            return self::FAILURE;
        }

        $this->info('Aislamiento entre imprentas verificado.');

        return self::SUCCESS;
    }
}
