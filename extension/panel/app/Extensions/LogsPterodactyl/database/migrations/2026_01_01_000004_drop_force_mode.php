<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retira el modo "forzado" de la configuracion.
 *
 * Ese modo borraba el servidor en el nodo para matar el contenedor de
 * instalacion. Se ha quitado: borrar el servidor borra tambien sus archivos y
 * no compensa. Ahora la parada deja el servidor en su sitio con un puerto
 * nuevo, para que el cliente revise sus datos y reinstale el mismo.
 *
 * A quien lo tuviera puesto se le pasa al modo equivalente sin borrado.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('logspterodactyl_settings')) {
            return;
        }

        DB::table('logspterodactyl_settings')
            ->where('key', 'watchdog_action')
            ->where('value', 'force_rotate')
            ->update(['value' => 'fail_rotate', 'updated_at' => now()]);

        // Ajustes que ya no existen.
        DB::table('logspterodactyl_settings')
            ->whereIn('key', ['client_cancel_force', 'client_can_reinstall'])
            ->delete();
    }

    public function down(): void
    {
        // No hay vuelta atras: el modo forzado ya no existe en el codigo.
    }
};
