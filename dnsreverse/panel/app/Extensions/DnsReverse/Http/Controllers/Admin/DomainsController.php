<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;
use Pterodactyl\Extensions\DnsReverse\Models\DnsEvent;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\CloudflareClient;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Alta y edicion de los dominios de la casa.
 *
 * Esta es la mejora grande frente a la version anterior: alli habia un unico
 * token de Cloudflare y un unico certificado para TODOS los dominios, metidos
 * en el mismo formulario separados por comas. Aqui cada dominio es una ficha
 * con su token, su certificado de origen y sus reglas, asi que puedes mezclar
 * dominios de cuentas de Cloudflare distintas sin problema.
 */
class DomainsController extends Controller
{
    private const RE_DOMINIO = '/^(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.(?!-)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/';

    public function index(): View
    {
        return view('dnsreverse::admin.domains', [
            'domains' => DnsDomain::query()->withCount('proxies')->orderBy('domain')->get(),
        ]);
    }

    public function create(): View
    {
        return view('dnsreverse::admin.domain-form', [
            'domain' => new DnsDomain([
                'proxied_mode' => 'auto',
                'allow_subdomain' => true,
                'allow_srv' => true,
                'allow_letsencrypt' => true,
                'active' => true,
                'reserved' => 'www,panel,admin,mail,ns1,ns2,cpanel,webmail,node,nodo,api,billing',
            ]),
            'nuevo' => true,
        ]);
    }

    public function edit(DnsDomain $domain): View
    {
        return view('dnsreverse::admin.domain-form', [
            'domain' => $domain,
            'nuevo' => false,
            'usos' => ProxyRecord::query()->where('domain_id', $domain->id)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $dominio = strtolower(trim((string) $request->input('domain')));

        if (DnsDomain::query()->where('domain', $dominio)->exists()) {
            return redirect()->route('admin.dnsreverse.domains.new')
                ->withInput()
                ->with('dnsreverse_error', 'Ese dominio ya estaba dado de alta.');
        }

        $modelo = new DnsDomain();
        $this->rellenar($modelo, $datos, $request);
        $modelo->save();

        // Si ya habia DNS de clientes colgando de este dominio (creados con la
        // version anterior, o antes de darlo de alta aqui), se enganchan ahora
        // para que aparezcan agrupados y cuenten bien.
        $enganchados = DnsDomain::vincularProxysSueltos($modelo);

        DnsEvent::record('info', 'domain.create', 'Dominio dado de alta', [
            'dns_enganchados' => $enganchados,
        ], $modelo->domain);

        $mensaje = 'Dominio ' . $modelo->domain . ' dado de alta. Prueba la conexion con Cloudflare antes de anunciarlo a los clientes.';

        if ($enganchados > 0) {
            $mensaje .= ' Se han enganchado ' . $enganchados . ' DNS que ya existian con este dominio.';
        }

        return redirect()->route('admin.dnsreverse.domains.edit', $modelo->id)
            ->with('dnsreverse_success', $mensaje);
    }

    public function update(Request $request, DnsDomain $domain): RedirectResponse
    {
        $datos = $this->validar($request, $domain);

        $nuevo = strtolower(trim((string) $request->input('domain')));

        if ($nuevo !== $domain->domain && DnsDomain::query()->where('domain', $nuevo)->exists()) {
            return redirect()->route('admin.dnsreverse.domains.edit', $domain->id)
                ->with('dnsreverse_error', 'Ya hay otro dominio dado de alta con ese nombre.');
        }

        // Si cambia el dominio, la zona cacheada de Cloudflare deja de valer.
        if ($nuevo !== $domain->domain) {
            $domain->cf_zone_id = null;
        }

        $this->rellenar($domain, $datos, $request);
        $domain->save();

        $enganchados = DnsDomain::vincularProxysSueltos($domain);

        DnsEvent::record('info', 'domain.update', 'Dominio actualizado', [], $domain->domain);

        return redirect()->route('admin.dnsreverse.domains.edit', $domain->id)
            ->with('dnsreverse_success', 'Cambios guardados.'
                . ($enganchados > 0 ? ' Se han enganchado ' . $enganchados . ' DNS que estaban sueltos.' : ''));
    }

    /**
     * Prueba el token y busca la zona. Se llama desde el boton "Probar
     * conexion", asi el administrador sabe si el token vale ANTES de que un
     * cliente se lleve el error.
     */
    public function test(Request $request, DnsDomain $domain): JsonResponse
    {
        // Se puede probar un token que aun no se ha guardado: asi no hace
        // falta guardar para descubrir que estaba mal escrito.
        $temporal = clone $domain;
        $token = trim((string) $request->input('cf_token'));

        if ($token !== '' && !$this->esRelleno($token)) {
            $temporal->setToken($token);
            $temporal->cf_zone_id = null;
        }

        $nombre = strtolower(trim((string) $request->input('domain')));

        if ($nombre !== '' && preg_match(self::RE_DOMINIO, $nombre)) {
            $temporal->domain = $nombre;
            $temporal->cf_zone_id = null;
        }

        // El objeto temporal no debe escribir en la base de datos al cachear
        // la zona, asi que se le quita la clave primaria.
        $temporal->exists = false;

        $resultado = CloudflareClient::for($temporal)->check();

        // Si la comprobacion fue bien y el dominio ya existe, se guarda la
        // zona encontrada para no volver a buscarla en cada creacion.
        if ($resultado['ok'] && $domain->exists && $resultado['zone']) {
            $domain->forceFill(['cf_zone_id' => $resultado['zone']])->save();
        }

        return response()->json($resultado);
    }

    /**
     * Dar de baja un dominio NO borra los DNS que los clientes ya tienen
     * creados con el: seguirian funcionando y desaparecerian de la lista sin
     * que nadie pudiera gestionarlos. Si hay DNS colgando, hay que purgarlos
     * antes desde la pestana de DNS de clientes.
     */
    public function destroy(DnsDomain $domain): RedirectResponse
    {
        $usos = ProxyRecord::query()->where('domain_id', $domain->id)->count();

        if ($usos > 0) {
            return redirect()->route('admin.dnsreverse.domains.edit', $domain->id)
                ->with('dnsreverse_error', 'No se puede borrar: hay ' . $usos . ' DNS de clientes usando este dominio. '
                    . 'Si lo que quieres es que nadie cree mas, desmarca "Activo" y quedate tranquilo, que lo ya creado sigue funcionando.');
        }

        $nombre = $domain->domain;
        $domain->delete();

        DnsEvent::record('info', 'domain.delete', 'Dominio dado de baja', [], $nombre);

        return redirect()->route('admin.dnsreverse.domains')
            ->with('dnsreverse_success', 'Dominio ' . $nombre . ' dado de baja.');
    }

    // -----------------------------------------------------------------------

    private function validar(Request $request, ?DnsDomain $actual = null): array
    {
        return $request->validate([
            'domain' => ['required', 'string', 'max:190', 'regex:' . self::RE_DOMINIO],
            'label' => ['nullable', 'string', 'max:120'],
            'cf_token' => ['nullable', 'string', 'max:2000'],
            'ssl_cert' => ['nullable', 'string', 'max:20000'],
            'ssl_key' => ['nullable', 'string', 'max:20000'],
            'proxied_mode' => ['required', 'in:auto,always,never'],
            'reserved' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'domain.regex' => 'Escribe el dominio en minusculas y sin http:// ni barras (por ejemplo: midominio.com).',
        ]);
    }

    private function rellenar(DnsDomain $modelo, array $datos, Request $request): void
    {
        $modelo->domain = strtolower(trim((string) $datos['domain']));
        $modelo->label = $datos['label'] ?? null;
        $modelo->proxied_mode = $datos['proxied_mode'];
        $modelo->reserved = $datos['reserved'] ?? null;
        $modelo->notes = $datos['notes'] ?? null;

        $modelo->allow_subdomain = $request->boolean('allow_subdomain');
        $modelo->allow_srv = $request->boolean('allow_srv');
        $modelo->allow_letsencrypt = $request->boolean('allow_letsencrypt');
        $modelo->active = $request->boolean('active');

        // El token solo se toca si escriben uno nuevo. El formulario nunca
        // muestra el token guardado, asi que dejarlo en blanco significa
        // "dejalo como estaba" y no "borralo".
        $token = trim((string) ($datos['cf_token'] ?? ''));

        if ($token !== '' && !$this->esRelleno($token)) {
            $modelo->setToken($token);
            $modelo->cf_zone_id = null;
        } elseif ($request->boolean('clear_token')) {
            $modelo->setToken(null);
            $modelo->cf_zone_id = null;
        }

        // El certificado si se muestra entero (lo necesita el administrador
        // para revisarlo), asi que se guarda tal cual llegue.
        $modelo->ssl_cert = $this->limpiarPem($datos['ssl_cert'] ?? '');
        $modelo->ssl_key = $this->limpiarPem($datos['ssl_key'] ?? '');
    }

    private function limpiarPem(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : str_replace("\r\n", "\n", $valor);
    }

    /**
     * El formulario pinta puntos donde iria el token guardado. Si el
     * administrador no lo toca, llega ese relleno y no hay que guardarlo.
     */
    private function esRelleno(string $valor): bool
    {
        return trim($valor, '*• ') === '';
    }
}
