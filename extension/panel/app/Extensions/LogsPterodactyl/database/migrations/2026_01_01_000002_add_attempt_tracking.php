<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numeracion de intentos y enlace entre ellos.
 *
 * Sin esto, cada instalacion era una fila suelta y no habia forma de saber si
 * el servidor que el sistema detuvo el martes acabo instalandose bien en el
 * segundo intento. Con el numero de intento y el enlace al anterior se puede
 * seguir la historia completa de cada servidor.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('logspterodactyl_install_events')) {
            return;
        }

        Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
            if (!Schema::hasColumn('logspterodactyl_install_events', 'attempt')) {
                $table->unsignedInteger('attempt')->default(1)->after('is_reinstall');
            }

            if (!Schema::hasColumn('logspterodactyl_install_events', 'previous_id')) {
                $table->unsignedBigInteger('previous_id')->nullable()->after('attempt');
            }

            if (!Schema::hasColumn('logspterodactyl_install_events', 'forced')) {
                $table->boolean('forced')->default(false)->after('resolution');
            }

            if (!Schema::hasColumn('logspterodactyl_install_events', 'wings_deleted')) {
                $table->boolean('wings_deleted')->default(false)->after('forced');
            }
        });

        // Se numeran los intentos que ya hubiera registrados, por servidor y
        // en orden de inicio, para que el historial antiguo tambien cuadre.
        try {
            $rows = DB::table('logspterodactyl_install_events')
                ->orderBy('server_id')
                ->orderBy('started_at')
                ->orderBy('id')
                ->get(['id', 'server_id']);

            $contador = [];
            $anterior = [];

            foreach ($rows as $row) {
                $clave = (string) $row->server_id;
                $contador[$clave] = ($contador[$clave] ?? 0) + 1;

                DB::table('logspterodactyl_install_events')
                    ->where('id', $row->id)
                    ->update([
                        'attempt' => $contador[$clave],
                        'previous_id' => $anterior[$clave] ?? null,
                    ]);

                $anterior[$clave] = $row->id;
            }
        } catch (\Throwable) {
            // Si falla, los intentos quedan a 1: no rompe nada.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('logspterodactyl_install_events')) {
            return;
        }

        Schema::table('logspterodactyl_install_events', function (Blueprint $table) {
            foreach (['attempt', 'previous_id', 'forced', 'wings_deleted'] as $column) {
                if (Schema::hasColumn('logspterodactyl_install_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
