<?php

namespace Pterodactyl\Extensions\DnsReverse\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Node;

/**
 * Habla directamente con wings (el demonio del nodo).
 *
 * Se hace aqui, y no a traves de DaemonServerRepository del panel, porque ese
 * archivo es del nucleo: la version anterior lo parcheaba a mano y por eso
 * dejaba de funcionar cada vez que se actualizaba el panel. Esta clase solo
 * usa el modelo Node de serie, que ninguna actualizacion se lleva por delante.
 *
 * Ademas se entiende con las DOS versiones del complemento de wings:
 *
 *   - v1 (la que instalaba la version anterior): rutas por servidor
 *     /api/servers/<uuid>/proxy/create y /proxy/delete
 *   - v2 (la de este repositorio): rutas de nodo /api/dns-reverse/*, que
 *     ademas permiten limpiar dominios de servidores ya borrados y renovar
 *     los certificados automaticos.
 *
 * Si el nodo va con v1 todo sigue funcionando; simplemente el panel avisa de
 * que conviene actualizar wings.
 */
class WingsClient
{
    /** Version del complemento que trae este repositorio. */
    public const VERSION_ESPERADA = 2;

    private const CACHE_MINUTOS = 5;

    public function __construct(private Node $node)
    {
    }

    public static function for(Node $node): self
    {
        return new self($node);
    }

    /**
     * Estado del complemento en el nodo.
     *
     * @return array{
     *     online: bool,
     *     version: int,
     *     nginx: bool,
     *     message: string,
     *     certs: array<int, array<string, mixed>>,
     *     needs_update: bool
     * }
     */
    public function status(bool $refrescar = false): array
    {
        $clave = 'dnsreverse:wings:' . $this->node->id;

        if ($refrescar) {
            Cache::forget($clave);
        }

        return Cache::remember($clave, now()->addMinutes(self::CACHE_MINUTOS), function () {
            return $this->consultarEstado();
        });
    }

    private function consultarEstado(): array
    {
        $base = [
            'online' => false,
            'version' => 0,
            'nginx' => false,
            'message' => '',
            'certs' => [],
            'needs_update' => true,
        ];

        try {
            $respuesta = $this->request()->get($this->url('/api/dns-reverse/status'));
        } catch (\Throwable $e) {
            return array_merge($base, ['message' => 'No se pudo conectar con el nodo: ' . $e->getMessage()]);
        }

        if ($respuesta->status() === 404) {
            // wings responde pero no conoce la ruta: o es wings sin modificar
            // o es la version anterior del complemento. Se distingue mirando
            // si contesta a la ruta antigua.
            $antigua = $this->tieneRutaAntigua();

            return array_merge($base, [
                'online' => true,
                'version' => $antigua ? 1 : 0,
                'message' => $antigua
                    ? 'Este nodo tiene la version antigua del complemento. Funciona, pero conviene actualizarlo.'
                    : 'Este nodo tiene wings sin modificar: no puede crear DNS hasta que se instale el complemento.',
            ]);
        }

        if ($respuesta->status() === 401 || $respuesta->status() === 403) {
            return array_merge($base, [
                'online' => true,
                'message' => 'El nodo rechaza el token del panel. Revisa la configuracion de wings.',
            ]);
        }

        if (!$respuesta->successful()) {
            return array_merge($base, [
                'online' => true,
                'message' => 'El nodo respondio con un error ' . $respuesta->status() . '.',
            ]);
        }

        $version = (int) $respuesta->json('version', 0);

        return [
            'online' => true,
            'version' => $version,
            'nginx' => (bool) $respuesta->json('nginx', false),
            'message' => (string) $respuesta->json('message', ''),
            'certs' => (array) $respuesta->json('certs', []),
            'needs_update' => $version < self::VERSION_ESPERADA,
        ];
    }

    /**
     * ¿El nodo lleva la version antigua del complemento?
     *
     * Se sondea la ruta antigua con un identificador de servidor que no
     * existe. No tiene ningun efecto (el middleware de wings corta antes de
     * llegar al codigo que escribe nada) y las dos respuestas se distinguen:
     *
     *   - ruta registrada  -> 404 en JSON con {"error": "The requested ..."}
     *   - ruta inexistente -> 404 en texto plano "404 page not found"
     */
    private function tieneRutaAntigua(): bool
    {
        try {
            $respuesta = $this->request(15)->post(
                $this->url('/api/servers/00000000-0000-0000-0000-000000000000/proxy/delete'),
                ['domain' => '', 'port' => '0']
            );
        } catch (\Throwable) {
            return false;
        }

        if ($respuesta->status() !== 404) {
            // Cualquier otra respuesta significa que la ruta existe.
            return true;
        }

        return str_contains((string) $respuesta->body(), 'does not exist on this instance');
    }

    /**
     * Crea (o rehace) la configuracion de nginx de un dominio en el nodo.
     *
     * @param array{
     *     domain: string, ip: string, port: int, ssl: bool, mode: string,
     *     email: string, cert: string, key: string, uuid: ?string
     * } $datos
     */
    public function createProxy(array $datos): void
    {
        $version = (int) ($this->status()['version'] ?? 0);

        if ($version >= 2) {
            $this->crearConRutaNueva($datos);

            return;
        }

        $this->crearConRutaAntigua($datos);
    }

    private function crearConRutaNueva(array $datos): void
    {
        $respuesta = $this->request(120)->post($this->url('/api/dns-reverse/create'), [
            'domain' => $datos['domain'],
            'ip' => $datos['ip'],
            'port' => (string) $datos['port'],
            'ssl' => (bool) $datos['ssl'],
            // none | origin | letsencrypt
            'mode' => $datos['mode'],
            'client_email' => $datos['email'],
            'ssl_cert' => $datos['cert'],
            'ssl_key' => $datos['key'],
            'websockets' => true,
        ]);

        if ($respuesta->successful()) {
            return;
        }

        throw new \RuntimeException($this->mensajeDeError($respuesta));
    }

    /**
     * Version antigua: la ruta cuelga del servidor y solo entiende el
     * interruptor "usar Let's Encrypt".
     */
    private function crearConRutaAntigua(array $datos): void
    {
        if (empty($datos['uuid'])) {
            throw new \RuntimeException(
                'Este nodo tiene la version antigua del complemento de wings y esta operacion necesita la nueva. '
                . 'Actualiza wings en el nodo ' . $this->node->name . '.'
            );
        }

        $respuesta = $this->request(120)->post(
            $this->url('/api/servers/' . $datos['uuid'] . '/proxy/create'),
            [
                'domain' => $datos['domain'],
                'ip' => $datos['ip'],
                'port' => (string) $datos['port'],
                'ssl' => (bool) $datos['ssl'],
                'use_lets_encrypt' => $datos['mode'] === 'letsencrypt',
                'client_email' => $datos['email'],
                'ssl_cert' => $datos['cert'],
                'ssl_key' => $datos['key'],
            ]
        );

        if ($respuesta->successful()) {
            return;
        }

        throw new \RuntimeException($this->mensajeDeError($respuesta));
    }

    /**
     * Quita la configuracion de nginx de un dominio.
     *
     * Nunca lanza excepcion: si el nodo esta caido igual queremos poder borrar
     * el registro del panel y de Cloudflare. Devuelve si se pudo o no.
     */
    public function deleteProxy(string $domain, int $port, ?string $uuid = null): bool
    {
        $version = (int) ($this->status()['version'] ?? 0);

        try {
            if ($version >= 2) {
                return $this->request(60)->post($this->url('/api/dns-reverse/delete'), [
                    'domain' => $domain,
                    'port' => (string) $port,
                ])->successful();
            }

            if ($uuid === null || $uuid === '') {
                return false;
            }

            return $this->request(60)->post($this->url('/api/servers/' . $uuid . '/proxy/delete'), [
                'domain' => $domain,
                'port' => (string) $port,
            ])->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Pide al nodo que renueve los certificados automaticos que caducan
     * pronto. Solo existe en la version nueva del complemento.
     *
     * @return array{ok: bool, renewed: array<int, string>, failed: array<int, string>, message: string}
     */
    public function renewCertificates(int $dias): array
    {
        if ((int) ($this->status()['version'] ?? 0) < 2) {
            return [
                'ok' => false,
                'renewed' => [],
                'failed' => [],
                'message' => 'El nodo ' . $this->node->name . ' necesita la version nueva del complemento de wings para renovar solo.',
            ];
        }

        try {
            $respuesta = $this->request(600)->post($this->url('/api/dns-reverse/renew'), ['days' => $dias]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'renewed' => [], 'failed' => [], 'message' => $e->getMessage()];
        }

        if (!$respuesta->successful()) {
            return ['ok' => false, 'renewed' => [], 'failed' => [], 'message' => $this->mensajeDeError($respuesta)];
        }

        return [
            'ok' => true,
            'renewed' => (array) $respuesta->json('renewed', []),
            'failed' => (array) $respuesta->json('failed', []),
            'message' => (string) $respuesta->json('message', ''),
        ];
    }

    private function url(string $ruta): string
    {
        return rtrim($this->node->getConnectionAddress(), '/') . $ruta;
    }

    private function request(int $segundos = 30): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->node->getDecryptedKey(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout($segundos)->connectTimeout(10);
    }

    private function mensajeDeError(\Illuminate\Http\Client\Response $respuesta): string
    {
        $error = $respuesta->json('error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return match ($respuesta->status()) {
            404 => 'El nodo ' . $this->node->name . ' no tiene instalado el complemento de wings de DNS Reverse.',
            401, 403 => 'El nodo ' . $this->node->name . ' rechaza el token del panel.',
            default => 'El nodo ' . $this->node->name . ' respondio con un error ' . $respuesta->status() . '.',
        };
    }
}
