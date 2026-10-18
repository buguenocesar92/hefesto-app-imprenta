<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orden en que corren los datos de ejemplo.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Primero la empresa: sin ella no hay nada mas.
            ImprentaEjemploSeeder::class,
        ]);
    }
}
