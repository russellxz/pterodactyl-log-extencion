<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;

/**
 * Cuantos DNS puede crear cada servidor.
 *
 * Esta pantalla existe por dos motivos. El primero es comodidad: poner el
 * limite servidor por servidor desde la ficha del panel es un suplicio. El
 * segundo es que despues de actualizar el panel, el campo "Proxy Limit" que
 * la version anterior anadia a mano en la pantalla de configuracion del
 * servidor DESAPARECE (el paquete oficial reemplaza esa vista). Desde aqui se
 * sigue pudiendo tocar sin depender de eso.
 */
class ServersController extends Controller
{
    public function __construct(private Settings $settings)
    {
    }

    public function index(Request $request): View
    {
        $disponible = Schema::hasColumn('servers', 'proxy_limit');

        $consulta = Server::query()->with(['user', 'node']);

        $buscar = trim((string) $request->input('q'));

        if ($buscar !== '') {
            $consulta->where(function ($sub) use ($buscar) {
                $sub->where('name', 'like', '%' . $buscar . '%')
                    ->orWhere('uuidShort', 'like', '%' . $buscar . '%')
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%' . $buscar . '%')
                        ->orWhere('username', 'like', '%' . $buscar . '%'));
            });
        }

        if ($disponible) {
            match ((string) $request->input('filter')) {
                'blocked' => $consulta->where('proxy_limit', 0),
                'allowed' => $consulta->where('proxy_limit', '>', 0),
                default => null,
            };
        }

        $servidores = $consulta->orderBy('name')->paginate(40)->withQueryString();

        // Cuantos DNS tiene creados cada servidor de la pagina actual.
        $usados = ProxyRecord::query()
            ->select('server_id', DB::raw('COUNT(*) as total'))
            ->whereIn('server_id', $servidores->pluck('id'))
            ->groupBy('server_id')
            ->pluck('total', 'server_id')
            ->all();

        return view('dnsreverse::admin.servers', [
            'servers' => $servidores,
            'usados' => $usados,
            'disponible' => $disponible,
            'porDefecto' => $this->settings->int('default_proxy_limit', 0, 100),
            'filtros' => [
                'q' => $buscar,
                'filter' => (string) $request->input('filter'),
            ],
        ]);
    }

    /**
     * Cambia el limite de un servidor. Poner 0 es justo lo que hay que hacer
     * para que ese cliente no pueda crear mas DNS: los que ya tiene se quedan
     * funcionando, simplemente no puede anadir nuevos.
     */
    public function limit(Request $request, Server $server): RedirectResponse
    {
        $datos = $request->validate([
            'proxy_limit' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        if (!Schema::hasColumn('servers', 'proxy_limit')) {
            return redirect()->back()->with('dnsreverse_error', 'La columna proxy_limit no existe. Ejecuta: php artisan dnsreverse:install');
        }

        $limite = (int) $datos['proxy_limit'];

        DB::table('servers')->where('id', $server->id)->update(['proxy_limit' => $limite]);

        DnsEvent::record('info', 'server.limit', 'Limite de DNS cambiado a ' . $limite, [
            'servidor' => $server->name,
        ], null, (int) $server->id);

        $mensaje = $limite === 0
            ? 'Servidor ' . $server->name . ' bloqueado: no podra crear mas DNS. Los que ya tenia siguen funcionando.'
            : 'Servidor ' . $server->name . ': limite puesto en ' . $limite . '.';

        return redirect()->back()->with('dnsreverse_success', $mensaje);
    }

    /**
     * Cambio en bloque, para no ir uno a uno cuando hay cientos de servidores.
     */
    public function bulkLimit(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'proxy_limit' => ['required', 'integer', 'min:0', 'max:100'],
            'scope' => ['required', 'in:all,zero'],
        ]);

        if (!Schema::hasColumn('servers', 'proxy_limit')) {
            return redirect()->back()->with('dnsreverse_error', 'La columna proxy_limit no existe. Ejecuta: php artisan dnsreverse:install');
        }

        $limite = (int) $datos['proxy_limit'];
        $consulta = DB::table('servers');

        // "zero" solo toca los que estan bloqueados a 0, que es lo habitual
        // justo despues de instalar: se sube el limite a todos de golpe sin
        // pisar los que el administrador ya habia ajustado a mano.
        if ($datos['scope'] === 'zero') {
            $consulta->where('proxy_limit', 0);
        }

        $afectados = $consulta->update(['proxy_limit' => $limite]);

        DnsEvent::record('info', 'server.bulk-limit', 'Limite cambiado en bloque a ' . $limite, [
            'alcance' => $datos['scope'],
            'servidores' => $afectados,
        ]);

        return redirect()->back()->with(
            'dnsreverse_success',
            $afectados . ' servidor(es) actualizados con limite ' . $limite . '.'
        );
    }
}
