<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\ProxyManager;
use Pterodactyl\Extensions\DnsReverse\Services\WingsClient;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;

/**
 * Pantalla de resumen: cuantos DNS hay, que dominios estan dados de alta y
 * que le falta al sistema para funcionar del todo.
 */
class DashboardController extends Controller
{
    public function __construct(
        private ProxyManager $proxies,
        private Settings $settings
    ) {
    }

    public function index(): View
    {
        return view('dnsreverse::admin.dashboard', [
            'stats' => $this->proxies->stats(),
            'domains' => DnsDomain::query()->withCount('proxies')->orderBy('domain')->get(),
            'recientes' => ProxyRecord::query()
                ->with(['server', 'allocation'])
                ->orderByDesc('id')
                ->limit(10)
                ->get(),
            'eventos' => DnsEvent::query()->orderByDesc('id')->limit(8)->get(),
            'avisos' => $this->avisos(),
        ]);
    }

    /**
     * Lo que hay que revisar antes de decirle a los clientes que ya pueden
     * crear DNS. Cada aviso lleva su explicacion y como se arregla.
     *
     * @return array<int, array{nivel: string, titulo: string, texto: string, enlace: ?string, enlace_texto: ?string}>
     */
    private function avisos(): array
    {
        $avisos = [];

        // --- 1. Dominios dados de alta ---
        if (DnsDomain::count() === 0) {
            $avisos[] = [
                'nivel' => 'error',
                'titulo' => 'No hay ningun dominio dado de alta',
                'texto' => 'Sin dominios, tus clientes solo podran usar dominios propios suyos. Da de alta al menos uno con su token de Cloudflare.',
                'enlace' => route('admin.dnsreverse.domains.new'),
                'enlace_texto' => 'Anadir dominio',
            ];
        } else {
            $sinToken = DnsDomain::query()->where('active', true)
                ->where(function ($consulta) {
                    $consulta->whereNull('cf_token')->orWhere('cf_token', '');
                })->count();

            if ($sinToken > 0) {
                $avisos[] = [
                    'nivel' => 'aviso',
                    'titulo' => $sinToken . ' dominio(s) sin token de Cloudflare',
                    'texto' => 'Los clientes podran pedir el subdominio, pero el registro DNS no se creara solo: lo tendras que crear tu a mano en Cloudflare.',
                    'enlace' => route('admin.dnsreverse.domains'),
                    'enlace_texto' => 'Ver dominios',
                ];
            }
        }

        // --- 2. Complemento de wings en los nodos ---
        $nodosSinComplemento = [];

        try {
            foreach (Node::all() as $nodo) {
                $estado = WingsClient::for($nodo)->status();

                if (!$estado['online'] || $estado['version'] < WingsClient::VERSION_ESPERADA) {
                    $nodosSinComplemento[] = $nodo->name . ' (' . ($estado['message'] ?: 'sin respuesta') . ')';
                }
            }
        } catch (\Throwable) {
            // Sin acceso a los nodos no se puede avisar de esto.
        }

        if ($nodosSinComplemento !== []) {
            $avisos[] = [
                'nivel' => count($nodosSinComplemento) > 0 ? 'aviso' : 'ok',
                'titulo' => 'Hay nodos con wings sin actualizar',
                'texto' => 'Nodos afectados: ' . implode(' | ', $nodosSinComplemento)
                    . '. En la pestana Nodos tienes el comando exacto para dejarlos al dia.',
                'enlace' => route('admin.dnsreverse.nodes'),
                'enlace_texto' => 'Ver nodos',
            ];
        }

        // --- 3. Servidores bloqueados a 0 ---
        try {
            if (Schema::hasColumn('servers', 'proxy_limit')) {
                $bloqueados = DB::table('servers')->where('proxy_limit', 0)->count();

                if ($bloqueados > 0) {
                    $avisos[] = [
                        'nivel' => 'info',
                        'titulo' => $bloqueados . ' servidor(es) con el limite a 0',
                        'texto' => 'Esos clientes no pueden crear ningun DNS. Puedes subirles el limite de golpe desde la pestana Servidores.',
                        'enlace' => route('admin.dnsreverse.servers'),
                        'enlace_texto' => 'Ver servidores',
                    ];
                }
            }
        } catch (\Throwable) {
            // Columna sin migrar: lo detecta el comando dnsreverse:doctor.
        }

        // --- 4. Certificados automaticos ---
        if (!$this->settings->bool('letsencrypt_auto_renew')) {
            $avisos[] = [
                'nivel' => 'aviso',
                'titulo' => 'La renovacion automatica esta apagada',
                'texto' => 'Los certificados de Let\'s Encrypt caducan a los 90 dias. Con esto apagado, las paginas de tus clientes dejaran de cargar cuando caduquen.',
                'enlace' => route('admin.dnsreverse.settings'),
                'enlace_texto' => 'Configuracion',
            ];
        }

        return $avisos;
    }
}
