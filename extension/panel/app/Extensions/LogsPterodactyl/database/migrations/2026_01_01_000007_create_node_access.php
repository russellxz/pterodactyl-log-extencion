<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceso SSH a los nodos, para poder soltar el bloqueo de instalacion de wings
 * sin tener que entrar a mano a cada maquina.
 *
 * La contrasena (o la clave privada) se guarda cifrada con la APP_KEY del
 * panel, nunca en claro, y no se devuelve jamas a la pantalla.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('logspterodactyl_node_access')) {
            return;
        }

        Schema::create('logspterodactyl_node_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('node_id')->unique();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('username')->default('root');

            // password | key
            $table->string('auth_type', 20)->default('password');
            $table->text('secret');
            $table->text('passphrase')->nullable();

            // Huella de la maquina. Si cambia es que han reinstalado la VPS (o
            // que alguien se esta haciendo pasar por ella), asi que no se
            // conecta hasta que un administrador lo confirma.
            $table->string('fingerprint')->nullable();

            $table->boolean('enabled')->default(true);
            $table->boolean('auto_fix')->default(false);

            $table->timestamp('last_ok_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logspterodactyl_node_access');
    }
};
