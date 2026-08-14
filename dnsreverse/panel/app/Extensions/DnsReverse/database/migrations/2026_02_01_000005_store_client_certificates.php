<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda con cada DNS el certificado de origen que trae el cliente.
 *
 * Antes ese certificado solo vivia en el disco del nodo. Cuando un nodo se
 * reinstalaba o se reconstruia wings, el certificado desaparecia y desde el
 * panel no habia forma de volver a ponerlo: el cliente tenia que borrar su
 * dominio y crearlo otra vez pegando el certificado de nuevo.
 *
 * Se guarda cifrado con la APP_KEY del panel, igual que el token de Cloudflare
 * de cada dominio, asi que en la base de datos no queda ninguna clave privada
 * legible.
 *
 * Las dos columnas son opcionales: los DNS que ya existian siguen valiendo tal
 * cual y usan el certificado comodin de su dominio, como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('server_proxy')) {
            return;
        }

        Schema::table('server_proxy', function (Blueprint $table) {
            if (!Schema::hasColumn('server_proxy', 'ssl_cert')) {
                $table->text('ssl_cert')->nullable();
            }

            if (!Schema::hasColumn('server_proxy', 'ssl_key')) {
                $table->text('ssl_key')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('server_proxy')) {
            return;
        }

        Schema::table('server_proxy', function (Blueprint $table) {
            foreach (['ssl_cert', 'ssl_key'] as $columna) {
                if (Schema::hasColumn('server_proxy', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
