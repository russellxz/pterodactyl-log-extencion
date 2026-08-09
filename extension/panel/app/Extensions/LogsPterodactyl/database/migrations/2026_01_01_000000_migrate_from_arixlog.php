<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traspaso desde la version anterior, que se llamaba ArixLog.
 *
 * Renombra las tablas arixlog_* a logspterodactyl_* conservando todo el
 * contenido (historial de instalaciones, correos, muestras de consumo...), asi
 * quien ya tenia la extension puesta no pierde nada ni tiene que desinstalar.
 *
 * Se ejecuta antes que la migracion que crea las tablas, y esa lleva sus
 * comprobaciones "si no existe", de modo que en una instalacion nueva este
 * archivo no hace absolutamente nada.
 */
return new class () extends Migration {
    private const TABLES = [
        'arixlog_settings' => 'logspterodactyl_settings',
        'arixlog_events' => 'logspterodactyl_events',
        'arixlog_install_events' => 'logspterodactyl_install_events',
        'arixlog_mail_logs' => 'logspterodactyl_mail_logs',
        'arixlog_resource_samples' => 'logspterodactyl_resource_samples',
        'arixlog_update_runs' => 'logspterodactyl_update_runs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $antigua => $nueva) {
            if (!Schema::hasTable($antigua)) {
                continue;
            }

            // Si por lo que sea ya existe la nueva, se deja la antigua a un
            // lado en vez de perder datos de ninguna de las dos.
            if (Schema::hasTable($nueva)) {
                Schema::rename($antigua, $antigua . '_reemplazada_' . date('Ymd'));

                continue;
            }

            Schema::rename($antigua, $nueva);
        }

        // La ruta de los respaldos apuntaba a la carpeta con el nombre viejo.
        // Se deja como estaba si el administrador la habia cambiado a mano.
        if (Schema::hasTable('logspterodactyl_settings')) {
            DB::table('logspterodactyl_settings')
                ->where('key', 'backup_path')
                ->where('value', '/var/backups/arixlog')
                ->update(['value' => '/var/backups/logspterodactyl', 'updated_at' => now()]);
        }

        // Y se borra el apunte de la migracion vieja para que no quede
        // colgando de una migracion que ya no existe en el disco.
        try {
            if (Schema::hasTable('migrations')) {
                DB::table('migrations')->where('migration', 'like', '%_create_arixlog_tables')->delete();
            }
        } catch (\Throwable) {
            // No es grave: solo seria una fila huerfana.
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $antigua => $nueva) {
            if (Schema::hasTable($nueva) && !Schema::hasTable($antigua)) {
                Schema::rename($nueva, $antigua);
            }
        }
    }
};
