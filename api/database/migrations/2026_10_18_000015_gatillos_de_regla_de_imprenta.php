<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reglas que la base impone, no la aplicacion.
 *
 * "Nada se imprime sin prueba aprobada" es la promesa central de este
 * producto. Si esa regla vive solo en el codigo, depende de que cada pantalla
 * y cada script la recuerden: basta un reporte nuevo, un artisan de Consola
 * o una actualizacion de Laravel para que se pierda sin que nadie lo note.
 *
 * Por eso vive AQUI, en un gatillo de PostgreSQL. No se puede insertar una
 * orden de produccion de un item sin una prueba aprobada, aunque el INSERT
 * venga de donde venga.
 *
 * El segundo gatillo protege la coherencia de una orden: el papel usado tiene
 * que ser el papel con que esta cotizado el item. Si no, el informe de merma
 * mediria un papel que el cliente nunca pidio.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Gatillo 1: no hay produccion sin prueba aprobada -----------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.gatillo_ordenes_exigen_prueba_aprobada()
            RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1
                      FROM public.pruebas
                     WHERE pedido_item_id = NEW.pedido_item_id
                       AND estado = 'aprobada'
                ) THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'No se puede registrar produccion: el item no tiene ninguna prueba aprobada.',
                        DETAIL = 'Item pedido_item_id: ' || NEW.pedido_item_id::text,
                        HINT = 'Registre la aprobacion del cliente en pruebas antes de imprimir.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ordenes_exigen_prueba_aprobada ON public.ordenes_produccion;
            CREATE TRIGGER ordenes_exigen_prueba_aprobada
                BEFORE INSERT OR UPDATE ON public.ordenes_produccion
                FOR EACH ROW
                EXECUTE FUNCTION public.gatillo_ordenes_exigen_prueba_aprobada();
        SQL);

        // --- Gatillo 2: el papel usado es el papel cotizado --------------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.gatillo_ordenes_exigen_papel_del_item()
            RETURNS trigger AS $$
            DECLARE
                papel_cotizado uuid;
            BEGIN
                SELECT papel_id INTO papel_cotizado
                  FROM public.pedido_items
                 WHERE id = NEW.pedido_item_id;

                IF papel_cotizado IS DISTINCT FROM NEW.papel_id THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'El papel de la orden de produccion no es el papel del item.',
                        DETAIL = 'Item pide papel ' || papel_cotizado::text
                                 || ' y la orden registra ' || NEW.papel_id::text,
                        HINT = 'Cambie el papel del item del pedido, no el de la orden.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ordenes_exigen_papel_del_item ON public.ordenes_produccion;
            CREATE TRIGGER ordenes_exigen_papel_del_item
                BEFORE INSERT OR UPDATE ON public.ordenes_produccion
                FOR EACH ROW
                EXECUTE FUNCTION public.gatillo_ordenes_exigen_papel_del_item();
        SQL);

        // --- Gatillo 3: el stock de libreria nunca queda negativo ---------
        //
        // El stock de libreria es la suma de los movimientos. Se calcula en
        // cada movimiento porque asi no depende de que el que escribe estire
        // bien la suma.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.gatillo_libreria_sin_stock_negativo()
            RETURNS trigger AS $$
            DECLARE
                saldo numeric(18,3);
            BEGIN
                SELECT COALESCE(SUM(
                           CASE WHEN sentido = 'entrada' THEN cantidad ELSE -cantidad END
                       ), 0)
                  INTO saldo
                  FROM public.movimientos_libreria
                 WHERE articulo_id = NEW.articulo_id;

                IF saldo < 0 THEN
                    RAISE EXCEPTION USING
                        ERRCODE = '23514',
                        MESSAGE = 'La salida dejaria el stock de libreria en negativo.',
                        DETAIL = 'Articulo: ' || NEW.articulo_id::text
                                 || ', saldo quedaria en ' || saldo::text,
                        HINT = 'Registre primero la entrada, o corrija con un movimiento contrario.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS libreria_sin_stock_negativo ON public.movimientos_libreria;
            CREATE TRIGGER libreria_sin_stock_negativo
                BEFORE INSERT OR UPDATE ON public.movimientos_libreria
                FOR EACH ROW
                EXECUTE FUNCTION public.gatillo_libreria_sin_stock_negativo();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ordenes_exigen_prueba_aprobada ON public.ordenes_produccion;');
        DB::unprepared('DROP TRIGGER IF EXISTS ordenes_exigen_papel_del_item ON public.ordenes_produccion;');
        DB::unprepared('DROP TRIGGER IF EXISTS libreria_sin_stock_negativo ON public.movimientos_libreria;');

        DB::unprepared('DROP FUNCTION IF EXISTS public.gatillo_ordenes_exigen_prueba_aprobada();');
        DB::unprepared('DROP FUNCTION IF EXISTS public.gatillo_ordenes_exigen_papel_del_item();');
        DB::unprepared('DROP FUNCTION IF EXISTS public.gatillo_libreria_sin_stock_negativo();');
    }
};
