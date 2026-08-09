<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostico de por que fallo un intento de instalacion.
 *
 * Sirve para distinguir "el cliente puso mal el token" de "el nodo sigue
 * ocupado con la instalacion anterior y ha rechazado esta", que son dos
 * problemas completamente distintos y se arreglan en sitios distintos.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('logspterodactyl_install_events')) {
            return;
        }

        Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
            if (!Schema::hasColumn('logspterodactyl_install_events', 'diagnosis')) {
                $table->string('diagnosis', 40)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('logspterodactyl_install_events')) {
            return;
        }

        Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
            if (Schema::hasColumn('logspterodactyl_install_events', 'diagnosis')) {
                $table->dropColumn('diagnosis');
            }
        });
    }
};
