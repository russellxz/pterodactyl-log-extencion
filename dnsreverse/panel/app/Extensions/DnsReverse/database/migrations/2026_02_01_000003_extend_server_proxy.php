<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas nuevas para `server_proxy`.
 *
 * Todas son opcionales y con valor por defecto, asi que las filas que ya
 * existen (los DNS que tus clientes crearon con la version anterior) siguen
 * validas sin tocarlas. Lo unico que se hace con ellas es rellenar `ssl_mode`
 * a partir de `ssl_enabled`, que es informativo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('server_proxy')) {
            return;
        }

        Schema::table('server_proxy', function (Blueprint $table) {
            // none | origin | letsencrypt | legacy
            //
            // "legacy" es lo que se le pone a lo que ya existia: sabemos que
            // tiene SSL pero no con que se genero. La renovacion automatica lo
            // resuelve preguntandole al nodo por el emisor del certificado.
            if (!Schema::hasColumn('server_proxy', 'ssl_mode')) {
                $table->string('ssl_mode', 24)->default('none');
            }

            if (!Schema::hasColumn('server_proxy', 'domain_id')) {
                $table->unsignedBigInteger('domain_id')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'created_by')) {
                $table->unsignedInteger('created_by')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'synced_at')) {
                $table->timestamp('synced_at')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'cert_expires_at')) {
                $table->timestamp('cert_expires_at')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'last_error')) {
                $table->text('last_error')->nullable();
            }
        });

        // Un dominio no puede estar dos veces. Si ya hubiera duplicados de
        // antes, el indice no se crea y la comprobacion se queda en el codigo.
        try {
            $duplicados = DB::table('server_proxy')
                ->select('domain')
                ->groupBy('domain')
                ->havingRaw('COUNT(*) > 1')
                ->count();

            if ($duplicados === 0) {
                Schema::table('server_proxy', function (Blueprint $table) {
                    $table->unique('domain', 'server_proxy_domain_unique');
                });
            }
        } catch (\Throwable) {
            // El indice ya existia o el motor no dejo crearlo: no es critico.
        }

        // Relleno informativo de las filas antiguas.
        try {
            DB::table('server_proxy')->where('ssl_enabled', 1)->where('ssl_mode', 'none')->update(['ssl_mode' => 'legacy']);
        } catch (\Throwable) {
            // Da igual: solo afecta a la etiqueta que se muestra.
        }
    }

    public function down(): void
    {
        // No se quitan columnas: perderiamos informacion de los DNS activos.
    }
};
