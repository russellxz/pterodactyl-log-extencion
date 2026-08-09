<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base compartida con la extension antigua de "reverse proxy".
 *
 * REGLA DE ORO DE ESTE ARCHIVO: aqui NO se borra nada. Si las tablas o las
 * columnas ya existen (porque el panel venia con la version que se instalaba
 * a mano, tocando archivos del nucleo) se dejan tal cual y se reutilizan. Asi
 * los DNS que tus clientes ya tenian creados siguen apareciendo despues de
 * instalar esta extension, y siguen funcionando en el nodo sin tocarlos.
 *
 * El metodo down() tampoco borra: desinstalar la extension no puede llevarse
 * por delante los dominios de los clientes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->tablaDeProxys();
        $this->limiteEnServidores();
        $this->modoEnEggs();
    }

    public function down(): void
    {
        // A proposito vacio. Ver la nota de arriba.
    }

    /**
     * La tabla `server_proxy` es la misma que usaba la version anterior.
     * Se crea solo si no existe; si existe, se le anaden las columnas que
     * falten.
     */
    private function tablaDeProxys(): void
    {
        if (!Schema::hasTable('server_proxy')) {
            Schema::create('server_proxy', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('server_id');
                $table->string('domain');
                $table->string('proxy_type')->default('domain');
                $table->string('base_domain')->nullable();
                $table->string('cf_record_id')->nullable();
                $table->unsignedInteger('allocation_id');
                $table->boolean('ssl_enabled')->default(false);
            });

            // Las claves foraneas van aparte y con red de seguridad: si el
            // panel tiene tipos distintos en servers.id (unsigned int frente a
            // bigint) la creacion falla y no queremos parar la instalacion por
            // eso. La integridad se comprueba igualmente desde el codigo.
            try {
                Schema::table('server_proxy', function (Blueprint $table) {
                    $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
                    $table->foreign('allocation_id')->references('id')->on('allocations')->onDelete('cascade');
                });
            } catch (\Throwable) {
                // Sin claves foraneas: la extension detecta y limpia huerfanos.
            }

            return;
        }

        // La tabla ya estaba. Solo se anade lo que falte.
        Schema::table('server_proxy', function (Blueprint $table) {
            if (!Schema::hasColumn('server_proxy', 'proxy_type')) {
                $table->string('proxy_type')->default('domain')->after('domain');
            }

            if (!Schema::hasColumn('server_proxy', 'base_domain')) {
                $table->string('base_domain')->nullable()->after('proxy_type');
            }

            if (!Schema::hasColumn('server_proxy', 'cf_record_id')) {
                $table->string('cf_record_id')->nullable()->after('base_domain');
            }
        });
    }

    /**
     * `servers.proxy_limit` es de la version anterior. Si no esta se crea, y
     * en los dos casos el valor por defecto pasa a 1 para que cada servidor
     * nuevo pueda crear su DNS sin que el administrador tenga que entrar a
     * mano. Los servidores que ya existen no se tocan aqui: de eso se encarga
     * la migracion de arranque, que solo sube los que estan a 0 si el
     * administrador lo pide desde la configuracion.
     */
    private function limiteEnServidores(): void
    {
        if (!Schema::hasTable('servers')) {
            return;
        }

        if (!Schema::hasColumn('servers', 'proxy_limit')) {
            Schema::table('servers', function (Blueprint $table) {
                if (Schema::hasColumn('servers', 'backup_limit')) {
                    $table->unsignedInteger('proxy_limit')->default(1)->after('backup_limit');
                } else {
                    $table->unsignedInteger('proxy_limit')->default(1);
                }
            });

            return;
        }

        // Cambiar solo el valor por defecto sin doctrine/dbal: ALTER COLUMN
        // ... SET DEFAULT existe en MySQL 5.7, MySQL 8 y MariaDB.
        try {
            DB::statement('ALTER TABLE `servers` ALTER COLUMN `proxy_limit` SET DEFAULT 1');
        } catch (\Throwable) {
            // Si el motor no lo admite, el listener de servidores nuevos se
            // encarga igualmente de poner el limite.
        }
    }

    /**
     * `eggs.proxy_mode` decide que tipos de DNS admite cada tipo de servidor
     * (normal / srv / ambos / desactivado).
     */
    private function modoEnEggs(): void
    {
        if (!Schema::hasTable('eggs') || Schema::hasColumn('eggs', 'proxy_mode')) {
            return;
        }

        Schema::table('eggs', function (Blueprint $table) {
            $table->string('proxy_mode')->default('normal');
        });
    }
};
