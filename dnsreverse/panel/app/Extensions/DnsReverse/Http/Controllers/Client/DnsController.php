<?php

namespace Pterodactyl\Extensions\DnsReverse\Http\Controllers\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Extensions\DnsReverse\Models\ProxyRecord;
use Pterodactyl\Extensions\DnsReverse\Services\ProxyManager;
use Pterodactyl\Extensions\DnsReverse\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Lo que usa el area de cliente.
 *
 * Devuelve y recibe JSON; la pantalla la dibuja client.js. Se hace asi para no
 * tener que tocar ni recompilar el React del panel, que es exactamente lo que
 * hacia la version anterior y lo que provocaba que la extension desapareciera
 * en cada actualizacion del panel o del tema.
 */
class DnsController extends Controller
{
    public function __construct(
        private ProxyManager $proxies,
        private Settings $settings
    ) {
    }

    /**
     * Todo lo que la pantalla del cliente necesita para dibujarse.
     */
    public function index(Request $request, string $server): JsonResponse
    {
        $servidor = $this->servidor($server);
        $this->comprobarAcceso($request, $servidor, 'ver');

        $tipos = $this->proxies->allowedTypes($servidor);
        $limite = $this->proxies->limitFor($servidor);
        $registros = $this->proxies->forServer($servidor);

        $dominios = [];

        foreach ($this->proxies->availableDomains() as $dominio) {
            if (!$dominio->allow_subdomain && !$dominio->allow_srv) {
                continue;
            }

            $dominios[] = [
                'id' => (int) $dominio->id,
                'domain' => (string) $dominio->domain,
                'label' => $dominio->label ?: null,
                'allow_subdomain' => (bool) $dominio->allow_subdomain,
                'allow_srv' => (bool) $dominio->allow_srv,
                // Si el dominio tiene certificado de origen puesto, el cliente
                // no tiene que pegar nada: se le da hecho.
                'has_origin_cert' => $dominio->hasOriginCertificate(),
                'allow_letsencrypt' => (bool) $dominio->allow_letsencrypt && $this->settings->bool('letsencrypt_enabled'),
                'automatic_dns' => $dominio->hasToken(),
            ];
        }

        $allocations = [];

        foreach ($servidor->allocations()->get() as $allocation) {
            $allocations[] = [
                'id' => (int) $allocation->id,
                'label' => ($allocation->alias ?: $allocation->ip) . ':' . $allocation->port,
                'port' => (int) $allocation->port,
                'default' => (int) $allocation->id === (int) $servidor->allocation_id,
            ];
        }

        return response()->json([
            'records' => $registros->map(fn (ProxyRecord $registro) => $registro->toClientArray())->values(),
            'config' => [
                'types' => $tipos,
                'limit' => $limite,
                'used' => $registros->count(),
                'remaining' => $this->proxies->remainingFor($servidor),
                'domains' => $dominios,
                'allocations' => $allocations,
                'allow_custom_domains' => $this->settings->bool('allow_custom_domains'),
                'letsencrypt' => $this->settings->bool('letsencrypt_enabled'),
                'can_manage' => $this->puede($request, $servidor, 'gestionar'),
                'node_target' => $this->objetivoDns($servidor),
                'dns_instructions' => $this->instrucciones($servidor),
            ],
        ]);
    }

    public function store(Request $request, string $server): JsonResponse
    {
        $servidor = $this->servidor($server);
        $this->comprobarAcceso($request, $servidor, 'gestionar');

        try {
            $registro = $this->proxies->create($servidor, $request->user(), [
                'type' => (string) $request->input('type'),
                'name' => (string) $request->input('name'),
                'domain_id' => (int) $request->input('domain_id'),
                'allocation_id' => (int) $request->input('allocation_id'),
                'ssl_mode' => (string) $request->input('ssl_mode'),
                'ssl_cert' => (string) $request->input('ssl_cert'),
                'ssl_key' => (string) $request->input('ssl_key'),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'record' => $registro->toClientArray()]);
    }

    public function destroy(Request $request, string $server, int $record): JsonResponse
    {
        $servidor = $this->servidor($server);
        $this->comprobarAcceso($request, $servidor, 'gestionar');

        $registro = ProxyRecord::query()
            ->where('id', $record)
            ->where('server_id', $servidor->id)
            ->first();

        if ($registro === null) {
            return response()->json(['ok' => false, 'error' => 'Ese DNS ya no existe.'], 404);
        }

        try {
            $avisos = $this->proxies->delete($registro, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'warnings' => $avisos]);
    }

    // -----------------------------------------------------------------------

    private function servidor(string $identificador): Server
    {
        $servidor = Server::query()
            ->where('uuid', $identificador)
            ->orWhere('uuidShort', $identificador)
            ->first();

        if ($servidor === null) {
            throw new NotFoundHttpException('Servidor no encontrado.');
        }

        return $servidor;
    }

    private function comprobarAcceso(Request $request, Server $servidor, string $accion): void
    {
        if (!$this->puede($request, $servidor, $accion)) {
            throw new AccessDeniedHttpException('No tienes permiso para gestionar los DNS de este servidor.');
        }
    }

    /**
     * Quien puede tocar los DNS de un servidor:
     *
     *  - el administrador del panel
     *  - el dueño del servidor
     *  - los subusuarios: ver siempre, y crear o borrar solo si tienen el
     *    permiso correspondiente
     */
    private function puede(Request $request, Server $servidor, string $accion): bool
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return false;
        }

        if ((bool) ($usuario->root_admin ?? false)) {
            return true;
        }

        if ((int) $servidor->owner_id === (int) $usuario->id) {
            return true;
        }

        $permisos = $this->permisosDeSubusuario($servidor, (int) $usuario->id);

        if ($permisos === null) {
            return false;
        }

        if ($accion === 'ver') {
            return true;
        }

        foreach (['proxy.create', 'proxy.delete', 'proxy.*', 'dnsreverse.*'] as $permiso) {
            if (in_array($permiso, $permisos, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>|null null si no es subusuario
     */
    private function permisosDeSubusuario(Server $servidor, int $usuario): ?array
    {
        try {
            $fila = DB::table('subusers')
                ->where('server_id', $servidor->id)
                ->where('user_id', $usuario)
                ->first();
        } catch (\Throwable) {
            return null;
        }

        if ($fila === null) {
            return null;
        }

        $permisos = json_decode((string) ($fila->permissions ?? '[]'), true);

        return is_array($permisos) ? array_map('strval', $permisos) : [];
    }

    /**
     * A donde tiene que apuntar el cliente su registro A cuando trae su
     * propio dominio.
     */
    private function objetivoDns(Server $servidor): ?string
    {
        $allocation = $servidor->allocation ?? $servidor->allocations()->first();

        if ($allocation === null) {
            return null;
        }

        if ($this->settings->get('dns_instruction_source') === 'alias' && !empty($allocation->alias)) {
            return (string) $allocation->alias;
        }

        return (string) $allocation->ip;
    }

    private function instrucciones(Server $servidor): string
    {
        $texto = (string) $this->settings->get('dns_instructions');
        $destino = $this->objetivoDns($servidor) ?? 'la IP de tu nodo';

        return str_replace('[ip]', $destino, $texto);
    }
}
