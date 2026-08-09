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
 *
 * IMPORTANTE: al dibujar la pagina NO se pregunta a ningun nodo. Se enseña lo
 * ultimo que se sabe (guardado en cache) y el navegador va pidiendo el estado
 * de cada uno por separado. Antes se consultaban todos aqui mismo y un solo
 * nodo apagado agotaba el tiempo maximo de PHP: la pantalla moria con un error
 * 500 en vez de decir "este nodo no responde".
 */
class NodesController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(): View
    {
        $nodos = [];

        // Cuantos DNS hay por nodo, de una sola consulta.
        $porNodo = [];

        try {
            $porNodo = ProxyRecord::query()
                ->join('allocations', 'allocations.id', '=', 'server_proxy.allocation_id')
                ->selectRaw('allocations.node_id as node_id, COUNT(*) as total')
                ->groupBy('allocations.node_id')
                ->pluck('total', 'node_id')
                ->all();
        } catch (\Throwable) {
            // Sin este dato la pantalla se ve igual, solo sin el contador.
        }

        foreach (Node::query()->orderBy('name')->get() as $nodo) {
            $nodos[] = [
                'modelo' => $nodo,
                // Puede ser null: significa "todavia no se ha comprobado".
                'estado' => WingsClient::for($nodo)->cachedStatus(),
                'dns' => (int) ($porNodo[$nodo->id] ?? 0),
            ];
        }

        return view('dnsreverse::admin.nodes', [
            'nodos' => $nodos,
            'esperada' => WingsClient::VERSION_ESPERADA,
            'renovarDias' => $this->settings->int('letsencrypt_renew_days', 1, 89),
        ]);
    }

    /**
     * Pregunta de verdad a un nodo. Lo llama el navegador, uno por uno, asi
     * que un nodo lento solo retrasa su propia fila.
     */
    public function check(Node $node): JsonResponse
    {
        $estado = WingsClient::for($node)->status(true);

        return response()->json($estado + [
            'esperada' => WingsClient::VERSION_ESPERADA,
            'node_id' => (int) $node->id,
        ]);
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
