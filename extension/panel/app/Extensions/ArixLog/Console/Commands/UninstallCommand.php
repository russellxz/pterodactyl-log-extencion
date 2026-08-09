<?php

namespace Pterodactyl\Extensions\ArixLog\Console\Commands;

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
    protected $signature = 'arixlog:uninstall {--force : No preguntar}';

    protected $description = 'Borra las tablas de la extension ArixLog de la base de datos';

    private const TABLES = [
        'arixlog_update_runs',
        'arixlog_resource_samples',
        'arixlog_mail_logs',
        'arixlog_install_events',
        'arixlog_events',
        'arixlog_settings',
    ];

    public function handle(): int
    {
        $this->warn('Se borraran las tablas de ArixLog y todo su contenido:');

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

            return DB::table('migrations')
                ->where('migration', 'like', '%create_arixlog_tables')
                ->orWhere('migration', 'like', '%_arixlog_%')
                ->delete();
        } catch (\Throwable $e) {
            $this->warn('No se pudo desmarcar la migracion: ' . $e->getMessage());
            $this->warn('Si reinstalas y faltan tablas, borra a mano su fila de la tabla "migrations".');

            return 0;
        }
    }
}
