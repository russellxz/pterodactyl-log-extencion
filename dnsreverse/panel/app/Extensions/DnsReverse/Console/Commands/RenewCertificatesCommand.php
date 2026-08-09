<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\WingsClient;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Models\Node;

/**
 * Renueva los certificados automaticos que estan a punto de caducar.
 *
 * Lo lanza solo el cron del panel todas las madrugadas. Sin esto, los
 * certificados de Let's Encrypt caducan a los 90 dias y las paginas de los
 * clientes empiezan a dar aviso de sitio no seguro. La version anterior de la
 * extension no tenia nada parecido: pedia el certificado una vez y ahi se
 * quedaba.
 */
class RenewCertificatesCommand extends Command
{
    protected $signature = 'dnsreverse:renew
                            {--days= : Renovar los que caduquen en menos de estos dias}
                            {--node= : Solo este nodo (id)}
                            {--force : Ejecutarlo aunque la renovacion automatica este apagada}';

    protected $description = 'Renueva los certificados de Let\'s Encrypt que caducan pronto';

    public function handle(Settings $settings): int
    {
        if (!$settings->bool('letsencrypt_auto_renew') && !$this->option('force')) {
            $this->line('La renovacion automatica esta apagada en la configuracion. Usa --force para forzarla.');

            return self::SUCCESS;
        }

        $dias = $this->option('days') !== null
            ? max(1, min(89, (int) $this->option('days')))
            : $settings->int('letsencrypt_renew_days', 1, 89);

        $consulta = Node::query();

        if ($this->option('node')) {
            $consulta->where('id', (int) $this->option('node'));
        }

        $nodos = $consulta->get();

        if ($nodos->isEmpty()) {
            $this->info('No hay nodos que revisar.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  Revisando certificados que caducan en menos de ' . $dias . ' dias...');
        $this->line('');

        $totalRenovados = 0;
        $totalFallidos = 0;

        foreach ($nodos as $nodo) {
            $resultado = WingsClient::for($nodo)->renewCertificates($dias);

            if (!$resultado['ok']) {
                $this->line('  <fg=yellow>[..]</> ' . $nodo->name . ': ' . $resultado['message']);

                continue;
            }

            $renovados = $resultado['renewed'];
            $fallidos = $resultado['failed'];

            $totalRenovados += count($renovados);
            $totalFallidos += count($fallidos);

            if ($renovados === [] && $fallidos === []) {
                $this->line('  <fg=green>[ok]</> ' . $nodo->name . ': nada que renovar');

                continue;
            }

            foreach ($renovados as $dominio) {
                $this->line('  <fg=green>[ok]</> ' . $nodo->name . ': renovado ' . $dominio);
                $this->marcarRenovado($dominio);
            }

            foreach ($fallidos as $fallo) {
                $this->line('  <fg=red>[!!]</> ' . $nodo->name . ': ' . $fallo);
            }
        }

        if ($totalRenovados > 0 || $totalFallidos > 0) {
            DnsEvent::record($totalFallidos > 0 ? 'warning' : 'info', 'cert.renew', 'Renovacion automatica', [
                'renovados' => $totalRenovados,
                'fallidos' => $totalFallidos,
                'dias' => $dias,
            ]);
        }

        $this->line('');
        $this->info('  Renovados: ' . $totalRenovados . '. Con problemas: ' . $totalFallidos . '.');

        if ($totalFallidos > 0) {
            $this->line('');
            $this->line('  Lo que suele fallar en una renovacion:');
            $this->line('   - el dominio ya no apunta a la IP del nodo (el cliente lo cambio)');
            $this->line('   - el dominio esta con la nube naranja de Cloudflare en vez de gris');
            $this->line('   - el puerto 80 del nodo no esta accesible desde fuera');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Deja constancia en la ficha del DNS de cuando caduca el certificado
     * nuevo, para que se vea en el panel sin tener que preguntar al nodo.
     */
    private function marcarRenovado(string $dominio): void
    {
        try {
            ProxyRecord::query()
                ->where('domain', $dominio)
                ->update([
                    'cert_expires_at' => now()->addDays(90),
                    'ssl_mode' => ProxyRecord::SSL_LETSENCRYPT,
                    'last_error' => null,
                ]);
        } catch (\Throwable) {
            // Es informativo: si falla, la renovacion sigue siendo valida.
        }
    }
}
