<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Traspaso desde la version anterior ("reverse proxy" instalado a mano).
 *
 * La version anterior guardaba UN solo token de Cloudflare y UN solo
 * certificado para todos los dominios, en la tabla `settings` del panel con
 * las claves `proxy::*`. Aqui se convierte eso en una fila por dominio dentro
 * de `dnsreverse_domains`, que es lo que permite tener varias cuentas de
 * Cloudflare y un certificado de origen distinto por dominio.
 *
 * No se borra ni una sola clave `proxy::*`: si en algun momento vuelves a la
 * version antigua, sigue estando todo donde estaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dnsreverse_domains') || !Schema::hasTable('settings')) {
            return;
        }

        // Si ya hay dominios dados de alta, esto ya se hizo (o el
        // administrador los metio a mano). No se toca nada.
        if (DB::table('dnsreverse_domains')->exists()) {
            $this->enlazarProxysConDominios();

            return;
        }

        $dominios = $this->ajuste('proxy::cloudflare_domains');

        if ($dominios === '') {
            return;
        }

        $token = $this->descifrar($this->ajuste('proxy::cloudflare_token'));
        $cert = $this->ajuste('proxy::subdomain_ssl_cert');
        $key = $this->ajuste('proxy::subdomain_ssl_key');
        $usaSsl = $this->ajuste('proxy::subdomain_use_ssl') === '1';
        $ahora = now();

        foreach (array_filter(array_map('trim', explode(',', $dominios))) as $dominio) {
            $dominio = strtolower($dominio);

            if ($dominio === '') {
                continue;
            }

            DB::table('dnsreverse_domains')->insert([
                'domain' => $dominio,
                'label' => null,
                'cf_token' => $token !== '' ? Crypt::encryptString($token) : null,
                'cf_zone_id' => null,
                'ssl_cert' => $usaSsl ? ($cert ?: null) : null,
                'ssl_key' => $usaSsl ? ($key ?: null) : null,
                'proxied_mode' => 'auto',
                'allow_subdomain' => true,
                'allow_srv' => true,
                'allow_letsencrypt' => true,
                'active' => true,
                'reserved' => 'www,panel,admin,mail,ns1,ns2,cpanel,webmail,node,nodo',
                'notes' => 'Importado de la configuracion anterior al instalar DNS Reverse.',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        // Las instrucciones de DNS que el administrador ya tenia escritas se
        // conservan tal cual en la configuracion nueva.
        $instrucciones = $this->ajuste('proxy::dns_instructions');
        $origen = $this->ajuste('proxy::dns_instruction_node');

        if (Schema::hasTable('dnsreverse_settings')) {
            if ($instrucciones !== '') {
                DB::table('dnsreverse_settings')->updateOrInsert(
                    ['key' => 'dns_instructions'],
                    ['value' => $instrucciones, 'updated_at' => $ahora]
                );
            }

            if ($origen !== '') {
                DB::table('dnsreverse_settings')->updateOrInsert(
                    ['key' => 'dns_instruction_source'],
                    ['value' => $origen, 'updated_at' => $ahora]
                );
            }
        }

        $this->enlazarProxysConDominios();
    }

    public function down(): void
    {
        // Nada que deshacer: no se borro nada.
    }

    /**
     * Cada DNS ya creado que sea subdominio de uno de los dominios dados de
     * alta se enlaza con su dominio para que aparezca bien agrupado.
     */
    private function enlazarProxysConDominios(): void
    {
        if (!Schema::hasTable('server_proxy') || !Schema::hasColumn('server_proxy', 'domain_id')) {
            return;
        }

        foreach (DB::table('dnsreverse_domains')->get(['id', 'domain']) as $dominio) {
            try {
                DB::table('server_proxy')
                    ->whereNull('domain_id')
                    ->where('base_domain', $dominio->domain)
                    ->update(['domain_id' => $dominio->id]);
            } catch (\Throwable) {
                // Sin base_domain guardado no hay forma de enlazarlo: se queda
                // suelto y se muestra igual en la lista.
            }
        }
    }

    private function ajuste(string $clave): string
    {
        try {
            return (string) (DB::table('settings')->where('key', $clave)->value('value') ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function descifrar(string $valor): string
    {
        if ($valor === '') {
            return '';
        }

        try {
            return Crypt::decryptString($valor);
        } catch (\Throwable) {
            // Guardado en claro o cifrado con otra APP_KEY.
            return str_starts_with($valor, 'eyJpdiI6') ? '' : $valor;
        }
    }
};
