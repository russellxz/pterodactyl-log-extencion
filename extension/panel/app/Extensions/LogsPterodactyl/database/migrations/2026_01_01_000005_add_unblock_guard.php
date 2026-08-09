<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vigilancia para que un servidor detenido no vuelva a quedarse bloqueado.
 *
 * Al parar una instalacion el servidor se marca como instalado, que es lo
 * unico que quita de en medio la pantalla "Running Installer". El problema es
 * que el contenedor de instalacion puede seguir vivo en el nodo un buen rato y,
 * cuando por fin muere, wings avisa al panel y el panel vuelve a poner el
 * servidor en "install_failed": el cliente se lo encuentra bloqueado otra vez
 * sin haber tocado nada.
 *
 * Con estas dos columnas la extension se acuerda de que ese servidor tiene que
 * seguir desbloqueado durante un rato y lo devuelve a su sitio si alguien lo
 * vuelve a bloquear.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('logspterodactyl_install_events')) {
            return;
        }

        Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
            if (!Schema::hasColumn('logspterodactyl_install_events', 'unblock_until')) {
                // Hasta cuando hay que mantener el servidor desbloqueado.
                $table->timestamp('unblock_until')->nullable();
            }

            if (!Schema::hasColumn('logspterodactyl_install_events', 'unblocked_times')) {
                // Cuantas veces hubo que desbloquearlo de nuevo. Si sale alto,
                // es que el nodo esta reportando la instalacion vieja.
                $table->unsignedInteger('unblocked_times')->default(0);
            }
        });

        // El barrido del vigilante busca justo por esta columna.
        try {
            Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
                $table->index('unblock_until', 'logspterodactyl_ie_unblock_idx');
            });
        } catch (\Throwable) {
            // Si el indice ya existe da igual: es solo para ir mas rapido.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('logspterodactyl_install_events')) {
            return;
        }

        try {
            Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
                $table->dropIndex('logspterodactyl_ie_unblock_idx');
            });
        } catch (\Throwable) {
            // Puede no existir.
        }

        Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
            foreach (['unblock_until', 'unblocked_times'] as $columna) {
                if (Schema::hasColumn('logspterodactyl_install_events', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
