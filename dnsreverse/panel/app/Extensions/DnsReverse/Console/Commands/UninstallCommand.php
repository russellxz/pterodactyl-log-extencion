<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;

/**
 * Desinstalacion de la parte de base de datos.
 *
 * POR DEFECTO NO BORRA NADA. Eso es a proposito: lo normal es desinstalar para
 * actualizar el panel, reinstalar el tema o mover el panel de sitio, y en esos
 * casos lo que quieres es que al volver a instalar aparezca TODO tal cual
 * estaba: los dominios de tus clientes, tus tokens de Cloudflare y tus
 * certificados.
 *
 * Hay que pedir expresamente que se borre:
 *
 *   --borrar-config  quita dominios, tokens, certificados y configuracion
 *   --borrar-dns     quita ademas los DNS de los clientes (muy destructivo)
 */
class UninstallCommand extends Command
{
    protected $signature = 'dnsreverse:uninstall
                            {--force : No preguntar}
                            {--borrar-config : Borra dominios, tokens, certificados y configuracion}
                            {--borrar-dns : Borra TAMBIEN los DNS de los clientes (no se recomienda)}';

    protected $description = 'Retira DNS Reverse de la base de datos (por defecto no borra nada)';

    public function handle(): int
    {
        $dns = $this->contar(ProxyRecord::class);
        $dominios = $this->contar(DnsDomain::class);

        $borrarConfig = (bool) $this->option('borrar-config');
        $borrarDns = (bool) $this->option('borrar-dns');

        $this->line('');

        if (!$borrarConfig && !$borrarDns) {
            $this->info('  No hay nada que borrar: los datos se conservan.');
            $this->line('');
            $this->line('  Se quedan tal cual:');
            $this->line('    - ' . $dns . ' DNS de clientes (tabla server_proxy)');
            $this->line('    - ' . $dominios . ' dominio(s) con sus tokens y certificados');
            $this->line('    - la configuracion de la extension');
            $this->line('');
            $this->line('  Cuando vuelvas a instalar, todo eso reaparece solo y ningun');
            $this->line('  cliente tiene que volver a crear su dominio.');
            $this->line('');
            $this->line('  Si de verdad quieres borrarlo:');
            $this->line('    php artisan dnsreverse:uninstall --borrar-config');
            $this->line('');

            return self::SUCCESS;
        }

        $this->line('  Se va a borrar:');

        if ($borrarConfig) {
            $this->line('    - dnsreverse_settings   (configuracion)');
            $this->line('    - dnsreverse_domains    (' . $dominios . ' dominios, con sus tokens y certificados)');
            $this->line('    - dnsreverse_events     (registro)');
        }

        if ($borrarDns) {
            $this->line('');
            $this->error('    - server_proxy: ' . $dns . ' DNS de clientes.');
            $this->error('      Los dominios seguiran montados en los nodos, pero el panel se');
            $this->error('      olvidara de ellos y tus clientes tendran que crearlo todo de nuevo.');
        }

        $this->line('');

        if (!$this->option('force') && !$this->confirm('¿Seguro que quieres continuar?', false)) {
            $this->line('  Cancelado. No se ha tocado nada.');

            return self::SUCCESS;
        }

        $tablas = [];

        if ($borrarConfig) {
            $tablas = ['dnsreverse_events', 'dnsreverse_domains', 'dnsreverse_settings'];
        }

        if ($borrarDns) {
            $tablas[] = 'server_proxy';
        }

        foreach ($tablas as $tabla) {
            try {
                Schema::dropIfExists($tabla);
                $this->line('  Borrada: ' . $tabla);
            } catch (\Throwable $e) {
                $this->error('  No se pudo borrar ' . $tabla . ': ' . $e->getMessage());
            }
        }

        // Las migraciones se desmarcan para que una reinstalacion las vuelva a
        // aplicar desde cero.
        try {
            DB::table('migrations')->where('migration', 'like', '%dnsreverse%')->delete();
        } catch (\Throwable) {
            // Sin tabla de migraciones no hay nada que limpiar.
        }

        $this->line('');

        if (!$borrarDns) {
            $this->info('  Los ' . $dns . ' DNS de tus clientes NO se han tocado.');
        }

        $this->line('  Las columnas proxy_limit y proxy_mode se quedan puestas a proposito,');
        $this->line('  para que reinstalar sea instantaneo y no se pierda ningun ajuste.');
        $this->line('');

        return self::SUCCESS;
    }

    private function contar(string $modelo): int
    {
        try {
            return (int) $modelo::count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
