<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoPedido;
use App\Enums\EstadoPrueba;
use App\Enums\RolImprenta;
use App\Models\Arte;
use App\Models\ArticuloLibreria;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Papel;
use App\Models\Plancha;
use App\Models\Prueba;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Entregas\ServicioDeEntregas;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de ejemplo para desarrollo.
 *
 * LO QUE NO TRAE, Y ES A PROPOSITO
 *
 *  - PRECIOS. `papeles.precio_referencia_por_kg` y los precios de libreria
 *    quedan en NULL. Esta aplicacion no inventa precios: los carga la
 *    imprenta.
 *
 *  - MERMA INVENTADA. La que el ejemplo carga viene marcada como EJEMPLO y
 *    no como valor recomendado. El sesacarian del historial real de cada
 *    imprenta, y una cifra que parece autorizada pero sale de un seeder es
 *    peor que una que falta: nadie va a preguntar de donde salio.
 *
 *  - Gramajes inventados. Los tres que se cargan (80, 120 y 300 g/m2) son
 *    los de uso corriente en una imprenta de PyME y vienen con la medida de
 *    plancha asociada, para que el calculo se pueda probar de inmediato.
 */
class ImprentaEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $empresa = Tenant::query()->firstOrCreate(
            ['slug' => 'imprenta-demo'],
            [
                'nombre' => 'Imprenta de Ejemplo',
                'razon_social' => 'Imprenta de Ejemplo Ltda.',
                'activo' => true,
            ]
        );

        // El seeder escribe con el contexto de la empresa, igual que el
        // middleware: sin esto, la RLS rechazaria cada INSERT.
        DB::transaction(function () use ($empresa): void {
            DB::selectOne('SELECT set_config(?, ?, true)', ['app.tenant_id', $empresa->id]);

            $this->catalogoDePapeles($empresa);
            $this->personas($empresa);
            $this->catalogoDeLibreria($empresa);
            $this->pedidoDeEjemplo($empresa);
        });
    }

    /**
     * Planchas y papeles del catalogo.
     *
     * Los porcentajes de merma son de EJEMPLO. Se cargan para que el
     * calculo de consumo se pueda ver funcionando, y estan marcados como
     * tales en los comentarios de la interfaz.
     */
    private function catalogoDePapeles(Tenant $empresa): void
    {
        $planchaGrande = Plancha::query()->firstOrCreate(
            ['tenant_id' => $empresa->id, 'codigo' => 'P70100'],
            [
                'nombre' => 'Plancha 70 x 100 cm',
                'ancho_mm' => 700,
                'alto_mm' => 1_000,
                'pliegos_por_paquete' => null,
                'activo' => true,
            ]
        );

        // Formatos menos comunes. Los numeros de pliegos por paquete quedan
        // en NULL: cada proveedor los maneja distinto y no hay dato
        // verificable aca.
        Plancha::query()->firstOrCreate(
            ['tenant_id' => $empresa->id, 'codigo' => 'P6609'],
            [
                'nombre' => 'Plancha 66 x 94 cm',
                'ancho_mm' => 660,
                'alto_mm' => 940,
                'pliegos_por_paquete' => null,
                'activo' => true,
            ]
        );

        // Gramajes de uso corriente en una PyME: 80, 120 y 300 g/m2.
        $papeles = [
            ['OFF080', 'Offset 80 g/m2', 'offset', 80],
            ['OFF120', 'Offset 120 g/m2', 'offset', 120],
            ['OPL300', 'Opal 300 g/m2', 'opal', 300],
        ];

        foreach ($papeles as [$codigo, $nombre, $tipo, $gramaje]) {
            Papel::query()->firstOrCreate(
                ['tenant_id' => $empresa->id, 'codigo' => $codigo],
                [
                    'nombre' => $nombre,
                    'tipo' => $tipo,
                    'gramaje_gm2' => $gramaje,
                    'plancha_id' => $planchaGrande->id,
                    'unidad_compra' => 'pliego',

                    // PORCENTAJES DE EJEMPLO. No son una recomendacion: son
                    // un numero de partida para probar el calculo. Cada
                    // imprenta carga los suyos desde su historial real.
                    'merma_prueba_porcentaje' => 3.00,
                    'merma_plancha_porcentaje' => 2.00,

                    'precio_referencia_por_kg' => null,
                    'activo' => true,
                ]
            );
        }
    }

    private function personas(Tenant $empresa): void
    {
        $personas = [
            ['admin@imprenta.ejemplo.cl', 'Administracion de Ejemplo', RolImprenta::ADMINISTRADOR],
            ['supervisor@imprenta.ejemplo.cl', 'Supervision de Ejemplo', RolImprenta::SUPERVISOR],
            ['preparador@imprenta.ejemplo.cl', 'Mesa de trabajo', RolImprenta::PREPARADOR],
            ['auditor@imprenta.ejemplo.cl', 'Revision de Ejemplo', RolImprenta::AUDITOR],
        ];

        foreach ($personas as [$correo, $nombre, $rol]) {
            Usuario::query()->firstOrCreate(
                ['tenant_id' => $empresa->id, 'email' => $correo],
                [
                    'nombre' => $nombre,
                    'rol' => $rol->value,
                    'activo' => true,
                ]
            );
        }
    }

    /**
     * Catalogo de libreria con los STOCK EN CERO A PROPOSITO.
     *
     * El stock se deriva de los movimientos, y no hay movimientos. Un seeder
     * que inventa existencias para probar "que se vea el stock en pantalla"
     * estaria sembrando un numero que nadie puede defender.
     */
    private function catalogoDeLibreria(Tenant $empresa): void
    {
        $articulos = [
            ['LIB-CUA-A4', 'Cuaderno A4 tapa dura', 'cuadernos', 'unidad'],
            ['LIB-ESG-B', 'Estuche de boligrafos', 'escritorio', 'paquete'],
            ['LIB-PLA-AC', 'Plastificadora de oficina', 'equipos', 'unidad'],
            ['LIB-SOB-C', 'Sobre americano carta', 'papeleria', 'paquete'],
        ];

        foreach ($articulos as [$sku, $nombre, $categoria, $unidad]) {
            ArticuloLibreria::query()->firstOrCreate(
                ['tenant_id' => $empresa->id, 'sku' => $sku],
                [
                    'nombre' => $nombre,
                    'categoria' => $categoria,
                    'unidad' => $unidad,
                    'precio_compra' => null,
                    'precio_venta' => null,
                    'activo' => true,
                ]
            );
        }
    }

    /**
     * Un pedido completo y uno a medio camino, para ver las dos caras del
     * estado de entregas.
     */
    private function pedidoDeEjemplo(Tenant $empresa): void
    {
        $cliente = Cliente::query()->firstOrCreate(
            ['tenant_id' => $empresa->id, 'codigo' => 'CLI-0001'],
            [
                'nombre' => 'Comercio de Ejemplo Ltda.',
                'contacto' => 'Compras',
                'correo' => 'compras@ejemplo.cl',
                'telefono' => null,
                'activo' => true,
            ]
        );

        $papel = Papel::query()
            ->where('tenant_id', $empresa->id)
            ->where('codigo', 'OPL300')
            ->first();

        $supervisor = Usuario::query()
            ->where('tenant_id', $empresa->id)
            ->where('rol', RolImprenta::SUPERVISOR->value)
            ->first();

        $preparador = Usuario::query()
            ->where('tenant_id', $empresa->id)
            ->where('rol', RolImprenta::PREPARADOR->value)
            ->first();

        // --- Pedido 1: esperando aprobacion ---------------------------
        $pedidoNuevo = Pedido::query()->firstOrCreate(
            ['tenant_id' => $empresa->id, 'numero' => 'PED-0001'],
            [
                'cliente_id' => $cliente->id,
                'estado' => EstadoPedido::ESPERANDO_APROBACION->value,
                'fecha_prometida' => now()->addDays(7)->toDateString(),
                'descripcion' => 'Tarjetas de presentacion para el equipo de ventas.',
            ]
        );

        $item = PedidoItem::query()->firstOrCreate(
            ['pedido_id' => $pedidoNuevo->id, 'descripcion' => 'Tarjetas 90 x 50 mm'],
            [
                'tenant_id' => $empresa->id,
                'papel_id' => $papel?->id,
                'cantidad_unidades' => 5_000,
                'ancho_mm' => 90,
                'alto_mm' => 50,
                'n_por_ventana' => 1,
            ]
        );

        if ($item->arte()->count() === 0 && $preparador !== null) {
            $arte = Arte::create([
                'tenant_id' => $empresa->id,
                'pedido_item_id' => $item->id,
                'version' => 1,
                'nombre_archivo' => 'tarjetas-v1.pdf',
                'url' => 'https://archivos.ejemplo.cl/imprenta-demo/tarjetas-v1.pdf',
                'sha256' => null,
                'notas' => 'Archivo de ejemplo. La huella se carga cuando el archivo real se suba.',
                'subido_por' => $preparador->id,
            ]);

            Prueba::create([
                'tenant_id' => $empresa->id,
                'pedido_item_id' => $item->id,
                'arte_id' => $arte->id,
                'version' => 1,
                'estado' => EstadoPrueba::ENVIADA->value,
                'enviada_en' => now()->subDay(),
                'resuelta_en' => null,
                'aprobada_por' => null,
                'comentario' => 'Pendiente de respuesta del cliente.',
            ]);
        }

        // --- Pedido 2: entregado a medias ---------------------------
        $pedidoParcial = Pedido::query()->firstOrCreate(
            ['tenant_id' => $empresa->id, 'numero' => 'PED-0002'],
            [
                'cliente_id' => $cliente->id,
                'estado' => EstadoPedido::EN_PRODUCCION->value,
                'fecha_prometida' => now()->addDays(3)->toDateString(),
                'descripcion' => 'Folders y hojas membretadas por tandas.',
                'aprobado_por' => $supervisor?->id,
                'aprobado_en' => now()->subDays(2),
            ]
        );

        PedidoItem::query()->firstOrCreate(
            ['pedido_id' => $pedidoParcial->id, 'descripcion' => 'Folders 24 x 33 cm'],
            [
                'tenant_id' => $empresa->id,
                'papel_id' => $papel?->id,
                'cantidad_unidades' => 20_000,
                'ancho_mm' => 240,
                'alto_mm' => 330,
                'n_por_ventana' => 1,
            ]
        );

        // Una entrega parcial: el pedido queda a medias, y ese es el punto.
        if ($preparador !== null && $pedidoParcial->entregas()->count() === 0) {
            app(ServicioDeEntregas::class)->registrar(
                pedido: $pedidoParcial,
                usuario: $preparador,
                cantidad: 8_000,
                medio: 'retiro',
            );
        }
    }
}
