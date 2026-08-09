<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\LogsPterodactyl\LogsPterodactylServiceProvider;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;

/**
 * Termina de dejar la extension lista: migraciones y valores por defecto.
 *
 * El registro del proveedor en config/app.php lo hace install.sh (o el script
 * suelto tools/register-provider.php), porque ese paso tiene que ocurrir
 * antes de que artisan sepa siquiera que esta extension existe.
 */
class InstallCommand extends Command
{
    /**
     * Tablas y columnas que tienen que existir para que la extension funcione.
     * Sirve para detectar una base de datos a medias.
     */
    private const ESPERADO = [
        'logspterodactyl_settings' => [],
        'logspterodactyl_events' => [],
        'logspterodactyl_resource_samples' => [],
        'logspterodactyl_update_runs' => [],
        'logspterodactyl_mail_campaigns' => [],
        'logspterodactyl_install_events' => ['attempt', 'previous_id', 'forced', 'wings_deleted', 'unblock_until', 'unblocked_times', 'diagnosis'],
        'logspterodactyl_mail_logs' => ['campaign_id'],
    ];

    protected $signature = 'logspterodactyl:install
                            {--minutes= : Minutos antes de cortar una instalacion colgada}
                            {--enable-watchdog : Deja activado el sistema automatico}';

    protected $description = 'Prepara la base de datos y la configuracion inicial de Logs Pterodactyl';

    public function handle(Settings $settings): int
    {
        $this->line('');
        $this->line('  Logs Pterodactyl v' . LogsPterodactylServiceProvider::VERSION);
        $this->line('  ------------------------------------------');

        $this->info('Aplicando migraciones...');
        Artisan::call('migrate', ['--force' => true], $this->getOutput());

        // Puede pasar que las migraciones figuren aplicadas pero la base de
        // datos no este completa: una desinstalacion antigua que borro las
        // tablas sin borrar todos sus apuntes, alguien que las borro a mano...
        // En ese caso "migrate" dice que no hay nada que hacer y la extension
        // se queda instalada pero coja. Se desmarcan y se vuelven a pasar.
        if (!$this->schemaIsComplete()) {
            $this->warn('La base de datos no esta completa aunque las migraciones figuraban aplicadas. Reintentando...');

            try {
                DB::table('migrations')->whereIn('migration', $this->migrationNames())->delete();
            } catch (\Throwable $e) {
                $this->error('No se pudieron desmarcar las migraciones: ' . $e->getMessage());
            }

            Artisan::call('migrate', ['--force' => true], $this->getOutput());
        }

        if (!Schema::hasTable(Settings::TABLE)) {
            $this->error('Las tablas no se crearon. Revisa la conexion a la base de datos.');

            return self::FAILURE;
        }

        if (!$this->schemaIsComplete()) {
            $this->error('Faltan tablas o columnas. Ejecuta "php artisan migrate --force" y revisa el error que salga.');

            return self::FAILURE;
        }

        // Se escriben los valores por defecto solo para las claves que aun no
        // estan guardadas, para no pisar la configuracion en una reinstalacion
        // o una actualizacion. Se mira lo que hay REALMENTE en la tabla: all()
        // ya viene mezclado con los valores por defecto y siempre daria que
        // todas las claves existen.
        $stored = $settings->stored();
        $written = 0;

        foreach (Settings::DEFAULTS as $key => $value) {
            if (!array_key_exists($key, $stored) || $stored[$key] === null) {
                $settings->set($key, $value);
                $written++;
            }
        }

        if ($written > 0) {
            $this->info($written . ' valor(es) de configuracion inicializados.');
        }

        if ($this->option('minutes')) {
            $minutes = max(1, min(1440, (int) $this->option('minutes')));
            $settings->set('watchdog_minutes', $minutes);
            $settings->set('client_cancel_minutes', $minutes);
            $this->info('Tiempo limite configurado en ' . $minutes . ' minutos.');
        }

        if ($this->option('enable-watchdog')) {
            $settings->set('watchdog_enabled', '1');
            $this->info('Sistema automatico de instalaciones activado.');
        }

        ExtensionEvent::log('info', 'setup', 'Extension instalada o actualizada', [
            'version' => LogsPterodactylServiceProvider::VERSION,
        ]);

        $this->line('');
        $this->info('  Listo. Entra en el panel: /admin/logspterodactyl');
        $this->line('');
        $this->line('  Siguientes pasos recomendados:');
        $this->line('   1. Configuracion -> revisa los minutos del corte automatico.');
        $this->line('   2. Comprueba que el cron del panel esta corriendo:');
        $this->line('      * * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * ¿Estan todas las tablas y columnas que la extension espera?
     */
    private function schemaIsComplete(): bool
    {
        foreach (self::ESPERADO as $tabla => $columnas) {
            if (!Schema::hasTable($tabla)) {
                return false;
            }

            foreach ($columnas as $columna) {
                if (!Schema::hasColumn($tabla, $columna)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Nombres de todas las migraciones de la extension, leidos de la carpeta.
     *
     * Se listan los archivos en lugar de buscar por nombre porque hay
     * migraciones ("add_attempt_tracking", "add_bulk_mail_support") que no
     * llevan el nombre de la extension y se quedaban fuera de la limpieza.
     *
     * @return array<int, string>
     */
    private function migrationNames(): array
    {
        $nombres = [];

        foreach ((array) glob(__DIR__ . '/../../database/migrations/*.php') as $archivo) {
            $nombres[] = basename($archivo, '.php');
        }

        return $nombres;
    }
}
