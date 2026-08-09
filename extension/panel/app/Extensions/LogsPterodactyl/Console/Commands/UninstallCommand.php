<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borra las tablas de la extension.
 *
 * Los archivos y el registro del proveedor los quita uninstall.sh. Este
 * comando existe aparte para poder limpiar la base de datos ANTES de borrar
 * los archivos, que es el orden correcto.
 */
class UninstallCommand extends Command
{
    protected $signature = 'logspterodactyl:uninstall {--force : No preguntar}';

    protected $description = 'Borra las tablas de la extension LogsPterodactyl de la base de datos';

    private const TABLES = [
        // El acceso a los nodos va primero: lleva credenciales cifradas y es
        // lo que menos gracia hace dejarse olvidado en la base de datos.
        'logspterodactyl_node_access',
        'logspterodactyl_mail_campaigns',
        'logspterodactyl_update_runs',
        'logspterodactyl_resource_samples',
        'logspterodactyl_mail_logs',
        'logspterodactyl_install_events',
        'logspterodactyl_events',
        'logspterodactyl_settings',
    ];

    public function handle(): int
    {
        $this->warn('Se borraran las tablas de LogsPterodactyl y todo su contenido:');

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table)) {
                $this->line('  - ' . $table);
            }
        }

        $this->line('');
        $this->line('Esto NO toca los servidores, los usuarios ni nada del panel.');

        if (!$this->option('force') && !$this->confirm('¿Continuar?', false)) {
            $this->info('Cancelado. No se ha borrado nada.');

            return self::SUCCESS;
        }

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        // Y se borra el apunte de la migracion. Sin esto, una reinstalacion
        // posterior veria la migracion como "ya aplicada", no volveria a crear
        // las tablas y la extension quedaria instalada pero rota.
        $removed = $this->forgetMigrations();

        $this->info('Tablas borradas.' . ($removed > 0 ? ' Migracion desmarcada para poder reinstalar.' : ''));
        $this->line('Ahora ya puedes ejecutar uninstall.sh para quitar los archivos.');

        return self::SUCCESS;
    }

    private function forgetMigrations(): int
    {
        try {
            if (!Schema::hasTable('migrations')) {
                return 0;
            }

            // Se listan los archivos de la carpeta de migraciones: es lo unico
            // fiable. Buscar por nombre dejaba fuera las que no lo llevan
            // ("add_attempt_tracking", "add_bulk_mail_support"), y al
            // reinstalar quedaban dadas por aplicadas sin estarlo, con lo que
            // faltaban columnas y la extension se quedaba coja.
            $nombres = [];

            foreach ((array) glob(__DIR__ . '/../../database/migrations/*.php') as $archivo) {
                $nombres[] = basename($archivo, '.php');
            }

            // Y la de la version anterior, que se llamaba ArixLog.
            $nombres[] = '2026_01_01_000001_create_arixlog_tables';

            return DB::table('migrations')->whereIn('migration', $nombres)->delete();
        } catch (\Throwable $e) {
            $this->warn('No se pudo desmarcar la migracion: ' . $e->getMessage());
            $this->warn('Si reinstalas y faltan tablas, borra a mano su fila de la tabla "migrations".');

            return 0;
        }
    }
}
