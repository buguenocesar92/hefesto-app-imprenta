<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Crea la aplicacion para las pruebas.
 *
 * Va en su propio archivo para que `TestCase` no tenga que importar nada
 * raro, igual que en el esqueleto de Laravel.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
