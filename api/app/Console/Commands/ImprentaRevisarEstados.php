<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Pedido;
use App\Services\Entregas\ServicioDeEntregas;
use Illuminate\Console\Command;

/**
 * Recalcula el estado de los pedidos y avisa de los que quedaron cuadrados
 * por error.
 *
 * POR QUE EXISTE
 *
 * El estado de un pedido se deriva de la SUMA de sus entregas. El servicio lo
 * recalcula en cada entrega, asi que no deberia desalinearse nunca. Pero
 * "no deberia" no es "no puede": una carga de datos, una restauracion vieja o
 * un desarrollo futuro pueden dejarlo desalineado, y cuando eso pase no hay
 * forma de saberlo salvo que alguien mire.
 *
 * Este comando es esa mirada. No corrige nada a escondidas: informa, y con
 * `--corregir` recien ahi recalcula.
 *
 * Uso:
 *   php artisan imprenta:revisar-estados
 *   php artisan imprenta:revisar-estados --corregir
 */
class ImprentaRevisarEstados extends Command
{
    protected $signature = 'imprenta:revisar-estados
                            {--corregir : Ademas de informar, recalcula y guarda el estado que corresponde}';

    protected $description = 'Revisa que el estado de cada pedido cuadre con la suma de sus entregas.';

    public function handle(ServicioDeEntregas $servicio): int
    {
        $pedidos = Pedido::query()->with('items')->get();

        if ($pedidos->isEmpty()) {
            $this->info('No hay pedidos que revisar.');

            return self::SUCCESS;
        }

        $descuadrados = [];

        foreach ($pedidos as $pedido) {
            $comparacion = $servicio->estadoEsperado($pedido);

            if ($comparacion['estado_guardado'] !== $comparacion['estado_esperado']) {
                $descuadrados[] = $comparacion;
            }
        }

        if ($descuadrados === []) {
            $this->info(sprintf('Los %d pedidos cuadran con sus entregas.', $pedidos->count()));

            return self::SUCCESS;
        }

        $this->warn(sprintf('%d pedido(s) con el estado desalineado:', count($descuadrados)));

        $filas = [];

        foreach ($descuadrados as $fila) {
            $filas[] = [
                $fila['pedido'],
                $fila['estado_guardado'],
                $fila['estado_esperado'],
            ];
        }

        $this->table(['Pedido', 'Estado guardado', 'Estado que corresponde'], $filas);

        if (! $this->option('corregir')) {
            $this->info('Para corregir, ejecute el mismo comando con --corregir.');

            return self::FAILURE;
        }

        $corregidos = 0;

        foreach ($descuadrados as $fila) {
            $pedido = Pedido::query()->where('numero', $fila['pedido'])->firstOrFail();

            $servicio->recalcularEstado($pedido);
            $corregidos++;
        }

        $this->info(sprintf('Se recalcularon %d pedido(s).', $corregidos));

        return self::SUCCESS;
    }
}
