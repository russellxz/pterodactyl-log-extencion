<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Egg;

/**
 * Que tipo de DNS admite cada tipo de servidor (egg).
 *
 * Un Minecraft quiere registros SRV; una pagina web quiere dominio normal con
 * su certificado. Aqui se decide por egg, y el cliente solo ve las opciones
 * que tienen sentido para su servidor.
 *
 * Igual que el limite por servidor, esto vivia en una vista del panel que la
 * version anterior parcheaba a mano y que desaparece al actualizar.
 */
class EggsController extends Controller
{
    public const MODOS = [
        'normal' => 'Normal: dominio propio y subdominios (paginas web, paneles...)',
        'srv' => 'Solo SRV de Minecraft',
        'both' => 'Ambos: web y SRV de Minecraft',
        'disabled' => 'Desactivado: este tipo de servidor no puede crear DNS',
    ];

    public function index(): View
    {
        $disponible = Schema::hasColumn('eggs', 'proxy_mode');

        return view('dnsreverse::admin.eggs', [
            'eggs' => $disponible
                ? Egg::query()->with('nest')->orderBy('name')->get()
                : collect(),
            'modos' => self::MODOS,
            'disponible' => $disponible,
        ]);
    }

    public function update(Request $request, Egg $egg): RedirectResponse
    {
        $datos = $request->validate([
            'proxy_mode' => ['required', 'in:normal,srv,both,disabled'],
        ]);

        if (!Schema::hasColumn('eggs', 'proxy_mode')) {
            return redirect()->back()->with('dnsreverse_error', 'La columna proxy_mode no existe. Ejecuta: php artisan dnsreverse:install');
        }

        // Consulta directa: asi no hace falta que 'proxy_mode' este en el
        // $fillable del modelo Egg del nucleo, que despues de actualizar el
        // panel no lo estara.
        DB::table('eggs')->where('id', $egg->id)->update(['proxy_mode' => $datos['proxy_mode']]);

        DnsEvent::record('info', 'egg.update', 'Modo de DNS de ' . $egg->name . ' puesto en ' . $datos['proxy_mode']);

        return redirect()->route('admin.dnsreverse.eggs')
            ->with('dnsreverse_success', $egg->name . ': ' . self::MODOS[$datos['proxy_mode']]);
    }
}
