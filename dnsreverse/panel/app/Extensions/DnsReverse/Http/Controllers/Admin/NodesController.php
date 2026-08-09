<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\WingsClient;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;

/**
 * Estado del complemento de wings en cada nodo.
 *
 * wings de serie no sabe montar nginx ni pedir certificados: hace falta el
 * complemento de este repositorio. Esta pantalla dice, nodo por nodo, si esta
 * puesto, con que version, y da el comando exacto para instalarlo.
 */
class NodesController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(): View
    {
        $nodos = [];

        foreach (Node::query()->orderBy('name')->get() as $nodo) {
            $estado = WingsClient::for($nodo)->status();

            $nodos[] = [
                'modelo' => $nodo,
                'estado' => $estado,
                'dns' => ProxyRecord::query()
                    ->whereHas('allocation', fn ($sub) => $sub->where('node_id', $nodo->id))
                    ->count(),
            ];
        }

        return view('dnsreverse::admin.nodes', [
            'nodos' => $nodos,
            'esperada' => WingsClient::VERSION_ESPERADA,
            'renovarDias' => $this->settings->int('letsencrypt_renew_days', 1, 89),
        ]);
    }

    /**
     * Vuelve a preguntar al nodo saltandose la cache de 5 minutos.
     */
    public function check(Node $node): JsonResponse
    {
        return response()->json(WingsClient::for($node)->status(true));
    }

    /**
     * Renueva a mano los certificados automaticos de un nodo. Normalmente lo
     * hace solo la tarea programada de todas las madrugadas.
     */
    public function renew(Node $node): JsonResponse
    {
        $dias = $this->settings->int('letsencrypt_renew_days', 1, 89);
        $resultado = WingsClient::for($node)->renewCertificates($dias);

        DnsEvent::record($resultado['ok'] ? 'info' : 'error', 'cert.renew', 'Renovacion manual en ' . $node->name, [
            'renovados' => $resultado['renewed'],
            'fallidos' => $resultado['failed'],
            'mensaje' => $resultado['message'],
        ]);

        return response()->json($resultado);
    }
}
