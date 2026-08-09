<?php

namespace Pterodactyl\Extensions\ArixLog\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\ArixLog\ArixLogServiceProvider;
use Pterodactyl\Extensions\ArixLog\Services\PanelUpdater;
use Pterodactyl\Extensions\ArixLog\Support\Settings;

/**
 * Diagnostico: dice de un vistazo si todo esta en su sitio.
 *
 * Es lo primero que hay que ejecutar cuando algo no funciona, sobre todo
 * despues de actualizar el panel o el tema Arix, porque los dos reescriben
 * config/app.php y se llevan por delante el registro del proveedor.
 */
class DoctorCommand extends Command
{
    protected $signature = 'arixlog:doctor';

    protected $description = 'Comprueba que la extension ArixLog esta bien instalada';

    public function handle(Settings $settings, PanelUpdater $updater): int
    {
        $this->line('');
        $this->line('  Diagnostico de ArixLog v' . ArixLogServiceProvider::VERSION);
        $this->line('  ------------------------------------------');

        $problems = 0;

        // 1. Proveedor registrado.
        $config = @file_get_contents(base_path('config/app.php'));
        $registered = is_string($config) && str_contains($config, 'ArixLogServiceProvider');
        $problems += $this->check(
            $registered,
            'Proveedor registrado en config/app.php',
            'Ejecuta: php ' . base_path('app/Extensions/ArixLog/tools/register-provider.php') . ' ' . base_path()
        );

        // 2. Tablas.
        foreach (['arixlog_settings', 'arixlog_events', 'arixlog_install_events', 'arixlog_mail_logs', 'arixlog_resource_samples', 'arixlog_update_runs'] as $table) {
            $problems += $this->check(Schema::hasTable($table), 'Tabla ' . $table, 'Ejecuta: php artisan arixlog:install');
        }

        // 3. Archivos publicos.
        foreach (['client.js', 'client.css', 'admin.js', 'admin.css'] as $asset) {
            $path = public_path('extensions/arixlog/' . $asset);
            $problems += $this->check(is_file($path), 'Recurso publico ' . $asset, 'Falta ' . $path . '. Vuelve a ejecutar install.sh');
        }

        // 4. Carpeta de logs legible.
        $problems += $this->check(
            is_readable(storage_path('logs')),
            'storage/logs se puede leer',
            'Revisa los permisos de ' . storage_path('logs')
        );

        // 5. Cron del panel. Solo se considera un problema si ya ha pasado
        //    tiempo suficiente desde la instalacion: recien instalada es
        //    normal que todavia no haya nada.
        if ($settings->bool('resources_enabled') && Schema::hasTable('arixlog_resource_samples')) {
            $lastSample = \Pterodactyl\Extensions\ArixLog\Models\ResourceSample::query()->max('sampled_at');
            $installedAt = Schema::hasTable('arixlog_settings')
                ? \Illuminate\Support\Facades\DB::table('arixlog_settings')->min('updated_at')
                : null;

            $reciente = $installedAt !== null
                && \Carbon\CarbonImmutable::parse($installedAt)->greaterThan(now()->subMinutes(15));

            if ($lastSample === null && $reciente) {
                $this->line('  <fg=yellow>[--]</> El cron del panel todavia no ha corrido');
                $this->line('       Normal si acabas de instalar. Vuelve a mirar en unos minutos.');
            } else {
                $fresh = $lastSample !== null
                    && \Carbon\CarbonImmutable::parse($lastSample)->greaterThan(now()->subMinutes(10));

                $problems += $this->check(
                    $fresh,
                    'El cron del panel esta ejecutando las tareas',
                    'No hay muestras recientes. Comprueba el cron: * * * * * php ' . base_path('artisan') . ' schedule:run'
                );
            }
        }

        // 6. Carpeta de respaldos.
        $backup = (string) $settings->get('backup_path');
        $parent = is_dir($backup) ? $backup : dirname($backup);
        $problems += $this->check(
            is_dir($parent) && is_writable($parent),
            'Carpeta de respaldos escribible',
            'No se puede escribir en ' . $parent . '. Crea la carpeta o cambiala en la configuracion.'
        );

        // 7. Tema Arix.
        $theme = $updater->themeInfo();
        $this->line(sprintf(
            '  %s  Tema Arix: %s',
            $theme['installed'] ? '[ok]' : '[--]',
            $theme['installed']
                ? 'detectado' . ($theme['staged_path'] ? ', restaurable desde ' . basename($theme['staged_path']) : ', SIN carpeta arix/<version> para restaurar')
                : 'no detectado (la extension funciona igual)'
        ));

        $this->line('');

        if ($problems === 0) {
            $this->info('  Todo correcto.');
        } else {
            $this->error(sprintf('  %d problema(s) encontrados. Revisa las lineas marcadas arriba.', $problems));
        }

        $this->line('');

        return $problems === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $ok, string $label, string $fix): int
    {
        if ($ok) {
            $this->line('  <fg=green>[ok]</> ' . $label);

            return 0;
        }

        $this->line('  <fg=red>[!!]</> ' . $label);
        $this->line('       ' . $fix);

        return 1;
    }
}
