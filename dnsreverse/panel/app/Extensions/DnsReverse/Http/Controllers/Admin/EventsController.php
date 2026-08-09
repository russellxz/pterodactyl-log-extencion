<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\User;

/**
 * Registro propio: quien creo o borro que DNS y cuando.
 *
 * Sirve para saber a quien preguntar cuando un dominio da guerra, y para
 * poder demostrar que la extension no ha borrado nada por su cuenta.
 */
class EventsController extends Controller
{
    public function index(Request $request): View
    {
        $consulta = DnsEvent::query();

        $nivel = (string) $request->input('level');

        if (in_array($nivel, ['info', 'error', 'warning'], true)) {
            $consulta->where('level', $nivel);
        }

        $buscar = trim((string) $request->input('q'));

        if ($buscar !== '') {
            $consulta->where(function ($sub) use ($buscar) {
                $sub->where('domain', 'like', '%' . $buscar . '%')
                    ->orWhere('message', 'like', '%' . $buscar . '%')
                    ->orWhere('action', 'like', '%' . $buscar . '%');
            });
        }

        $eventos = $consulta->orderByDesc('id')->paginate(60)->withQueryString();

        // Los nombres de usuario se resuelven de golpe para no hacer una
        // consulta por fila.
        $usuarios = User::query()
            ->whereIn('id', array_filter($eventos->pluck('user_id')->all()))
            ->pluck('username', 'id')
            ->all();

        return view('dnsreverse::admin.events', [
            'eventos' => $eventos,
            'usuarios' => $usuarios,
            'filtros' => ['q' => $buscar, 'level' => $nivel],
        ]);
    }
}
