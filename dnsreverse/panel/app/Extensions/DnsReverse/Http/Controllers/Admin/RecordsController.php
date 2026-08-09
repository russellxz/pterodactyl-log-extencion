<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\DomainChecker;
use Pterodactyl\Extensions\DnsReverse\Services\ProxyManager;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Todos los DNS que han creado los clientes.
 *
 * Aqui el administrador ve el dominio, a que servidor pertenece, de quien es,
 * en que nodo esta y puede abrir la pagina en una pestana nueva para
 * comprobar que responde. Tambien puede resincronizar (volver a mandar la
 * configuracion al nodo) o purgar el dominio.
 */
class RecordsController extends Controller
{
    public function __construct(private ProxyManager $proxies)
    {
    }

    public function index(Request $request): View
    {
        $consulta = ProxyRecord::query()->with(['server.user', 'allocation.node', 'dnsDomain']);

        $buscar = trim((string) $request->input('q'));

        if ($buscar !== '') {
            $consulta->where(function ($sub) use ($buscar) {
                $sub->where('domain', 'like', '%' . $buscar . '%')
                    ->orWhereHas('server', function ($servidor) use ($buscar) {
                        $servidor->where('name', 'like', '%' . $buscar . '%')
                            ->orWhere('uuidShort', 'like', '%' . $buscar . '%');
                    });
            });
        }

        $tipo = (string) $request->input('type');

        if (in_array($tipo, [ProxyRecord::TYPE_DOMAIN, ProxyRecord::TYPE_SUBDOMAIN, ProxyRecord::TYPE_SRV], true)) {
            $consulta->where('proxy_type', $tipo);
        }

        $dominio = (int) $request->input('domain_id');

        if ($dominio > 0) {
            $consulta->where('domain_id', $dominio);
        }

        if ($request->input('filter') === 'orphans') {
            $consulta = $this->proxies->orphanQuery()->with(['allocation.node', 'dnsDomain']);
        }

        return view('dnsreverse::admin.records', [
            'records' => $consulta->orderByDesc('id')->paginate(40)->withQueryString(),
            'domains' => DnsDomain::orderBy('domain')->get(),
            'stats' => $this->proxies->stats(),
            'filtros' => [
                'q' => $buscar,
                'type' => $tipo,
                'domain_id' => $dominio,
                'filter' => (string) $request->input('filter'),
            ],
        ]);
    }

    /**
     * Diagnostico de un dominio: si existe en DNS, a donde apunta, si responde
     * y si el certificado vale. Es lo que contesta a "mi pagina no carga".
     */
    public function check(ProxyRecord $record): JsonResponse
    {
        return response()->json(
            DomainChecker::revisar($record->load(['allocation', 'server']))
        );
    }

    /**
     * Vuelve a mandar la configuracion de este DNS al nodo. Util cuando se
     * reinstala un nodo o cuando la configuracion de nginx se perdio.
     */
    public function sync(ProxyRecord $record): RedirectResponse
    {
        $resultado = $this->proxies->sync($record);

        return redirect()->back()->with(
            $resultado['ok'] ? 'dnsreverse_success' : 'dnsreverse_error',
            $record->domain . ': ' . $resultado['message']
        );
    }

    /**
     * Borra un DNS concreto: Cloudflare, nodo y fila.
     */
    public function destroy(Request $request, ProxyRecord $record): RedirectResponse
    {
        $dominio = (string) $record->domain;
        $avisos = $this->proxies->delete($record, $request->user());

        $mensaje = 'DNS ' . $dominio . ' borrado.';

        if ($avisos !== []) {
            $mensaje .= ' Avisos: ' . implode(' ', $avisos);
        }

        return redirect()->back()->with('dnsreverse_success', $mensaje);
    }

    /**
     * Purga en bloque (varios a la vez), pensada sobre todo para los
     * huerfanos: DNS cuyo servidor ya no existe en el panel.
     */
    public function purge(Request $request): JsonResponse
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));

        if ($ids === []) {
            return response()->json(['ok' => false, 'error' => 'No has seleccionado ningun DNS.']);
        }

        $borrados = 0;
        $fallos = 0;
        $avisos = [];

        foreach (ProxyRecord::query()->whereIn('id', $ids)->get() as $registro) {
            try {
                $avisos = array_merge($avisos, $this->proxies->delete($registro, $request->user()));
                $borrados++;
            } catch (\Throwable $e) {
                $fallos++;
                DnsEvent::record('error', 'purge', $e->getMessage(), [], (string) $registro->domain, $registro->server_id);
            }
        }

        return response()->json([
            'ok' => true,
            'purged' => $borrados,
            'failed' => $fallos,
            'warnings' => array_values(array_unique($avisos)),
        ]);
    }

    /**
     * Resincroniza TODO de golpe. Es lo que hay que pulsar despues de
     * actualizar el panel, de reinstalar un nodo o de reconstruir wings: la
     * base de datos manda y los nodos se ponen al dia solos.
     */
    public function syncAll(Request $request): JsonResponse
    {
        $consulta = ProxyRecord::query()->with(['server', 'allocation.node']);

        if ((int) $request->input('node_id') > 0) {
            $nodo = (int) $request->input('node_id');
            $consulta->whereHas('allocation', fn ($sub) => $sub->where('node_id', $nodo));
        }

        $bien = 0;
        $mal = [];

        foreach ($consulta->get() as $registro) {
            $resultado = $this->proxies->sync($registro);

            if ($resultado['ok']) {
                $bien++;
            } else {
                $mal[] = $registro->domain . ': ' . $resultado['message'];
            }
        }

        DnsEvent::record('info', 'sync.all', 'Resincronizacion completa', [
            'ok' => $bien,
            'errores' => count($mal),
        ]);

        return response()->json([
            'ok' => true,
            'synced' => $bien,
            'failed' => $mal,
        ]);
    }
}
