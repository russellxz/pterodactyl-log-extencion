<?php

namespace Pterodactyl\Extensions\ArixLog\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\ArixLog\ArixLogServiceProvider;
use Pterodactyl\Extensions\ArixLog\Models\ExtensionEvent;
use Pterodactyl\Extensions\ArixLog\Support\Settings;

/**
 * Termina de dejar la extension lista: migraciones y valores por defecto.
 *
 * El registro del proveedor en config/app.php lo hace install.sh (o el script
 * suelto tools/register-provider.php), porque ese paso tiene que ocurrir
 * antes de que artisan sepa siquiera que esta extension existe.
 */
class InstallCommand extends Command
{
    protected $signature = 'arixlog:install
                            {--minutes= : Minutos antes de cortar una instalacion colgada}
                            {--enable-watchdog : Deja activado el sistema automatico}';

    protected $description = 'Prepara la base de datos y la configuracion inicial de ArixLog';

    public function handle(Settings $settings): int
    {
        $this->line('');
        $this->line('  ArixLog v' . ArixLogServiceProvider::VERSION);
        $this->line('  ------------------------------------------');

        $this->info('Aplicando migraciones...');
        Artisan::call('migrate', ['--force' => true], $this->getOutput());

        // Puede darse el caso de que la migracion figure como aplicada pero
        // las tablas no esten (una desinstalacion antigua, alguien que las
        // borro a mano...). En ese caso migrate dice "nothing to migrate" y la
        // extension quedaria instalada pero sin base de datos, asi que se
        // desmarca el apunte y se vuelve a intentar una vez.
        if (!Schema::hasTable(Settings::TABLE)) {
            $this->warn('Las tablas no estaban aunque la migracion figuraba aplicada. Reintentando...');

            try {
                DB::table('migrations')->where('migration', 'like', '%_arixlog_%')->delete();
            } catch (\Throwable $e) {
                $this->error('No se pudo desmarcar la migracion: ' . $e->getMessage());
            }

            Artisan::call('migrate', ['--force' => true], $this->getOutput());
        }

        if (!Schema::hasTable(Settings::TABLE)) {
            $this->error('Las tablas no se crearon. Revisa la conexion a la base de datos.');

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
            'version' => ArixLogServiceProvider::VERSION,
        ]);

        $this->line('');
        $this->info('  Listo. Entra en el panel: /admin/arixlog');
        $this->line('');
        $this->line('  Siguientes pasos recomendados:');
        $this->line('   1. Configuracion -> activa el sistema automatico y ajusta los minutos.');
        $this->line('   2. Comprueba que el cron del panel esta corriendo:');
        $this->line('      * * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1');
        $this->line('');

        return self::SUCCESS;
    }
}
