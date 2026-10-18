<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolImprenta;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta una persona en una imprenta.
 *
 * POR QUE ES UN COMANDO Y NO UN BOTON
 *
 * Porque el alta de una persona con acceso a los pedidos de los clientes es
 * una decision administrativa que tiene que dejar nombre, fecha y quien la
 * tomo. Un boton en una pantalla es facil de dar y facil de abuse.
 *
 * Ademas el correo es unico POR EMPRESA: la misma persona puede estar en dos
 * imprentas con roles distintos, y por eso el comando exige indicar la
 * empresa.
 *
 * Uso:
 *   php artisan imprenta:crear-persona --empresa=imprenta --correo=persona@ejemplo.cl \
 *       --nombre="Nombre Completo" --rol=preparador
 */
class ImprentaCrearPersona extends Command
{
    protected $signature = 'imprenta:crear-persona
                            {--empresa= : Slug de la imprenta (obligatorio)}
                            {--correo= : Correo corporativo (obligatorio)}
                            {--nombre= : Nombre de la persona (obligatorio)}
                            {--rol=preparador : auditor, preparador, supervisor o administrador}';

    protected $description = 'Da de alta una persona en una imprenta con su rol.';

    public function handle(): int
    {
        $slug = (string) $this->option('empresa');
        $correo = strtolower(trim((string) $this->option('correo')));
        $nombre = trim((string) $this->option('nombre'));
        $rol = (string) $this->option('rol');

        $faltantes = [];

        if ($slug === '') {
            $faltantes[] = '--empresa';
        }

        if ($correo === '' || ! str_contains($correo, '@')) {
            $faltantes[] = '--correo';
        }

        if ($nombre === '') {
            $faltantes[] = '--nombre';
        }

        if ($faltantes !== []) {
            $this->error('Faltan datos obligatorios: ' . implode(', ', $faltantes) . '.');

            return self::FAILURE;
        }

        $rolEnum = RolImprenta::tryFrom($rol);

        if ($rolEnum === null) {
            $this->error(sprintf(
                'El rol "%s" no existe. Use uno de: %s.',
                $rol,
                implode(', ', array_column(RolImprenta::cases(), 'value'))
            ));

            return self::FAILURE;
        }

        $empresa = Tenant::query()->where('slug', $slug)->first();

        if ($empresa === null) {
            $this->error(sprintf('No existe ninguna empresa con el slug "%s".', $slug));

            return self::FAILURE;
        }

        if (! $empresa->activo) {
            $this->error(sprintf('La empresa "%s" esta inactiva.', $slug));

            return self::FAILURE;
        }

        $existe = Usuario::query()
            ->where('tenant_id', $empresa->id)
            ->where('email', $correo)
            ->exists();

        if ($existe) {
            $this->error(sprintf('La persona %s ya esta dada de alta en %s.', $correo, $slug));

            return self::FAILURE;
        }

        // Se crea dentro del contexto de la empresa para que la RLS lo permita.
        DB::transaction(function () use ($empresa, $correo, $nombre, $rolEnum): void {
            DB::selectOne('SELECT set_config(?, ?, true)', ['app.tenant_id', $empresa->id]);

            Usuario::create([
                'tenant_id' => $empresa->id,
                'email' => $correo,
                'nombre' => $nombre,
                'rol' => $rolEnum->value,
                'activo' => true,
            ]);
        });

        $this->info(sprintf('Persona dada de alta en %s con el rol %s.', $slug, $rolEnum->value));
        $this->line('La contrasena NO se crea aca: el acceso es con la cuenta corporativa.');

        return self::SUCCESS;
    }
}
