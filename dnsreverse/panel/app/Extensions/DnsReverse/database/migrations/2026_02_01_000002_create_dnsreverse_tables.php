<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas propias de la extension.
 *
 * Van con prefijo `dnsreverse_` y separadas de las del panel para que
 * desinstalar sea limpio. La tabla de dominios es la novedad grande: cada
 * dominio base tiene SU PROPIO token de Cloudflare y SU PROPIO certificado de
 * origen, en vez de un token unico para todo como en la version anterior.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dnsreverse_settings')) {
            Schema::create('dnsreverse_settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('dnsreverse_domains')) {
            Schema::create('dnsreverse_domains', function (Blueprint $table) {
                $table->id();
                $table->string('domain')->unique();
                $table->string('label')->nullable();

                // Token de Cloudflare de ESTE dominio, cifrado. Puede estar
                // vacio: un dominio sin token sigue sirviendo para que el
                // cliente cree subdominios, pero el registro DNS lo tendra que
                // crear el administrador a mano.
                $table->text('cf_token')->nullable();
                $table->string('cf_zone_id')->nullable();

                // Certificado de origen de ESTE dominio (Cloudflare: SSL/TLS
                // -> Origin Server). Lo normal es uno comodin *.dominio.com.
                $table->text('ssl_cert')->nullable();
                $table->text('ssl_key')->nullable();

                // auto   -> nube naranja con certificado de origen, gris con Let's Encrypt
                // always -> siempre nube naranja
                // never  -> siempre nube gris
                $table->string('proxied_mode')->default('auto');

                $table->boolean('allow_subdomain')->default(true);
                $table->boolean('allow_srv')->default(true);
                $table->boolean('allow_letsencrypt')->default(true);
                $table->boolean('active')->default(true);

                // Palabras que los clientes no pueden usar como subdominio,
                // separadas por comas (panel, admin, www, mail...).
                $table->text('reserved')->nullable();

                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('dnsreverse_events')) {
            Schema::create('dnsreverse_events', function (Blueprint $table) {
                $table->id();
                $table->string('level', 16)->default('info');
                $table->string('action', 64);
                $table->string('domain')->nullable();
                $table->unsignedInteger('server_id')->nullable();
                $table->unsignedInteger('user_id')->nullable();
                $table->text('message')->nullable();
                $table->text('context')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('created_at');
                $table->index('action');
            });
        }
    }

    public function down(): void
    {
        // Las tablas propias si se pueden tirar al desinstalar, pero eso lo
        // decide el comando dnsreverse:uninstall (que pregunta), no una
        // migracion que se podria ejecutar sin querer con migrate:rollback.
    }
};
