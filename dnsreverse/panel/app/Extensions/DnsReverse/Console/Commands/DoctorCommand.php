<?php

namespace Pterodactyl\Extensions\DnsReverse\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Extensions\DnsReverse\DnsReverseServiceProvider;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\CloudflareClient;
use Pterodactyl\Extensions\DnsReverse\Services\WingsClient;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Models\Node;

/**
 * Revision completa: dice que esta bien, que esta mal y como se arregla.
 *
 * Es lo primero que hay que ejecutar cuando algo no va.
 */
class DoctorCommand extends Command
{
    protected $signature = 'dnsreverse:doctor {--cloudflare : Probar tambien los tokens de Cloudflare}';

    protected $description = 'Comprueba que DNS Reverse esta bien montado';

    private int $problemas = 0;

    public function handle(Settings $settings): int
    {
        $this->line('');
        $this->line('  DNS Reverse v' . DnsReverseServiceProvider::VERSION . ' - revision');
        $this->line('  ---------------------------------------------------------------');

        $this->baseDeDatos();
        $this->configuracion($settings);
        $this->dominios();
        $this->nodos();
        $this->huerfanos();
        $this->cron();

        $this->line('');

        if ($this->problemas === 0) {
            $this->info('  Todo correcto.');
            $this->line('');

            return self::SUCCESS;
        }

        $this->warn('  ' . $this->problemas . ' cosa(s) que revisar. Arriba tienes el detalle.');
        $this->line('');

        return self::FAILURE;
    }

    // -----------------------------------------------------------------------

    private function baseDeDatos(): void
    {
        $this->seccion('Base de datos');

        $tablas = [
            'dnsreverse_settings' => 'php artisan dnsreverse:install',
            'dnsreverse_domains' => 'php artisan dnsreverse:install',
            'dnsreverse_events' => 'php artisan dnsreverse:install',
            'server_proxy' => 'php artisan dnsreverse:install',
        ];

        foreach ($tablas as $tabla => $arreglo) {
            if (Schema::hasTable($tabla)) {
                $this->bien('Tabla ' . $tabla);
            } else {
                $this->mal('Falta la tabla ' . $tabla, $arreglo);
            }
        }

        $columnas = [
            ['servers', 'proxy_limit', 'Sin ella nadie puede crear DNS.'],
            ['eggs', 'proxy_mode', 'Sin ella no se puede limitar por tipo de servidor.'],
            ['server_proxy', 'ssl_mode', 'Sin ella no se sabe que certificado usa cada dominio.'],
            ['server_proxy', 'domain_id', 'Sin ella los DNS no se agrupan por dominio.'],
        ];

        foreach ($columnas as [$tabla, $columna, $porque]) {
            if (!Schema::hasTable($tabla)) {
                continue;
            }

            if (Schema::hasColumn($tabla, $columna)) {
                $this->bien($tabla . '.' . $columna);
            } else {
                $this->mal('Falta ' . $tabla . '.' . $columna . '. ' . $porque, 'php artisan dnsreverse:install');
            }
        }
    }

    private function configuracion(Settings $settings): void
    {
        $this->seccion('Configuracion');

        $porDefecto = $settings->int('default_proxy_limit', 0, 100);

        if ($porDefecto > 0) {
            $this->bien('Los servidores nuevos nacen con ' . $porDefecto . ' DNS disponibles');
        } else {
            $this->aviso('Los servidores nuevos nacen con 0: nadie podra crear DNS sin que tu lo permitas.');
        }

        if ($settings->bool('letsencrypt_enabled')) {
            $this->bien('Certificados automaticos activados');

            if ($settings->bool('letsencrypt_auto_renew')) {
                $this->bien('Renovacion automatica activada (' . $settings->int('letsencrypt_renew_days', 1, 89) . ' dias de margen)');
            } else {
                $this->mal(
                    'La renovacion automatica esta apagada: las paginas dejaran de cargar a los 90 dias.',
                    'Activala en /admin/dnsreverse/settings'
                );
            }
        } else {
            $this->aviso('Los certificados automaticos estan desactivados.');
        }

        if (Schema::hasColumn('servers', 'proxy_limit')) {
            $bloqueados = DB::table('servers')->where('proxy_limit', 0)->count();

            if ($bloqueados > 0) {
                $this->aviso($bloqueados . ' servidor(es) con el limite a 0 (no pueden crear DNS).');
            }
        }
    }

    private function dominios(): void
    {
        $this->seccion('Dominios');

        $dominios = DnsDomain::all();

        if ($dominios->isEmpty()) {
            $this->aviso('No hay ningun dominio dado de alta. Anade uno en /admin/dnsreverse/domains/new');

            return;
        }

        foreach ($dominios as $dominio) {
            $etiqueta = $dominio->domain . ($dominio->active ? '' : ' (inactivo)');

            if (!$dominio->hasToken()) {
                $this->aviso($etiqueta . ': sin token de Cloudflare, los registros DNS habra que crearlos a mano.');
            } elseif ($this->option('cloudflare')) {
                $resultado = CloudflareClient::for($dominio)->check();

                if ($resultado['ok']) {
                    $this->bien($etiqueta . ': ' . $resultado['message']);
                } else {
                    $this->mal($etiqueta . ': ' . $resultado['message'], 'Revisa el token en /admin/dnsreverse/domains');
                }
            } else {
                $this->bien($etiqueta . ': token guardado');
            }

            if (!$dominio->hasOriginCertificate() && !$dominio->allow_letsencrypt) {
                $this->mal(
                    $etiqueta . ': no tiene certificado de origen ni permite Let\'s Encrypt, asi que nadie podra tener HTTPS.',
                    'Pon un certificado o activa Let\'s Encrypt en la ficha del dominio'
                );
            }
        }
    }

    private function nodos(): void
    {
        $this->seccion('Nodos');

        $nodos = Node::all();

        if ($nodos->isEmpty()) {
            $this->aviso('No hay nodos en el panel.');

            return;
        }

        foreach ($nodos as $nodo) {
            $estado = WingsClient::for($nodo)->status(true);

            if (!$estado['online']) {
                $this->mal($nodo->name . ': no responde. ' . $estado['message'], 'Comprueba que wings esta corriendo');

                continue;
            }

            if ($estado['version'] >= WingsClient::VERSION_ESPERADA) {
                $mensaje = $nodo->name . ': complemento v' . $estado['version'] . ' al dia';

                if (!$estado['nginx']) {
                    $this->mal($mensaje . ', pero nginx no acepta su configuracion. ' . $estado['message'], 'Entra al nodo y ejecuta: nginx -t');

                    continue;
                }

                $this->bien($mensaje . ', ' . count($estado['certs']) . ' certificado(s) guardados');

                continue;
            }

            $this->mal(
                $nodo->name . ': ' . ($estado['message'] ?: 'complemento antiguo o ausente'),
                'sudo bash dnsreverse/wings/install-wings.sh   (en el nodo)'
            );
        }
    }

    private function huerfanos(): void
    {
        $this->seccion('DNS huerfanos');

        if (!Schema::hasTable('server_proxy')) {
            return;
        }

        $huerfanos = ProxyRecord::query()->whereNotExists(function ($consulta) {
            $consulta->select(DB::raw(1))->from('servers')->whereColumn('servers.id', 'server_proxy.server_id');
        })->count();

        if ($huerfanos === 0) {
            $this->bien('Ninguno: todos los DNS tienen su servidor');

            return;
        }

        $this->aviso($huerfanos . ' DNS cuyo servidor ya no existe. Purgalos en /admin/dnsreverse/records?filter=orphans');
    }

    private function cron(): void
    {
        $this->seccion('Tareas programadas');

        $this->line('     El cron del panel tiene que estar puesto o los certificados no se renovaran:');
        $this->line('     * * * * * php ' . base_path('artisan') . ' schedule:run >> /dev/null 2>&1');
    }

    // -----------------------------------------------------------------------

    private function seccion(string $titulo): void
    {
        $this->line('');
        $this->line('  ' . $titulo);
    }

    private function bien(string $texto): void
    {
        $this->line('     <fg=green>[ok]</> ' . $texto);
    }

    private function aviso(string $texto): void
    {
        $this->line('     <fg=yellow>[..]</> ' . $texto);
    }

    private function mal(string $texto, string $arreglo): void
    {
        $this->problemas++;
        $this->line('     <fg=red>[!!]</> ' . $texto);
        $this->line('          arreglo: ' . $arreglo);
    }
}
