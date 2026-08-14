<?php

namespace Pterodactyl\Extensions\DnsReverse\Services;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;

/**
 * Cliente de la API de Cloudflare atado a UN dominio.
 *
 * El token no es global, viene del dominio. Asi puedes tener ultraplus.click en
 * una cuenta de Cloudflare y otrodominio.com en otra cuenta distinta, cada uno
 * con su token.
 *
 * POR QUE ESTA CLASE COMPRUEBA TANTO
 * ----------------------------------
 *
 * Un registro creado en Cloudflare puede devolver "200 OK" y aun asi NO
 * funcionar. Cuando eso pasa, el cliente entra a su pagina y el navegador le
 * dice "no se puede acceder a este sitio, revisa que no haya errores de
 * ortografia" (DNS_PROBE_FINISHED_NXDOMAIN), y en el panel todo se ve verde.
 *
 * Los tres motivos, todos reales:
 *
 *   1. La zona esta en Cloudflare pero en estado "pending": los nameservers
 *      del dominio todavia no apuntan a Cloudflare. Cloudflare guarda el
 *      registro tan contento, pero nadie en internet le pregunta a Cloudflare
 *      por ese dominio, asi que el nombre no existe para el mundo.
 *
 *   2. El identificador de zona guardado es de otra zona (el dominio se cambio
 *      de nombre, o se movio de cuenta de Cloudflare). El registro se crea de
 *      verdad, pero dentro del dominio equivocado.
 *
 *   3. El registro ya existia apuntando a otro sitio. Cloudflare devuelve
 *      error 81057/81058 y antes eso tumbaba la creacion entera.
 *
 * Aqui se comprueban los tres, y ademas se vuelve a leer el registro despues
 * de crearlo para confirmar que quedo como se pidio.
 */
class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    /** Zona ya consultada en esta misma peticion, para no repetir llamadas. */
    private ?array $zonaEnMemoria = null;

    public function __construct(private DnsDomain $domain)
    {
    }

    public static function for(DnsDomain $domain): self
    {
        return new self($domain);
    }

    public function usable(): bool
    {
        return $this->domain->hasToken();
    }

    /**
     * Comprueba que el token sirve, que la zona existe y que ESTA ACTIVA.
     *
     * @return array{ok: bool, message: string, zone: ?string, status: ?string, nameservers: array<int, string>}
     */
    public function check(): array
    {
        $vacio = ['zone' => null, 'status' => null, 'nameservers' => []];

        if (!$this->usable()) {
            return array_merge($vacio, [
                'ok' => false,
                'message' => 'Este dominio no tiene token de Cloudflare guardado.',
            ]);
        }

        try {
            $respuesta = $this->client()->get(self::BASE . '/user/tokens/verify');
        } catch (\Throwable $e) {
            return array_merge($vacio, [
                'ok' => false,
                'message' => 'No se pudo conectar con Cloudflare: ' . $e->getMessage(),
            ]);
        }

        $estado = $respuesta->json('result.status');

        if (!$respuesta->successful() || $estado !== 'active') {
            return array_merge($vacio, [
                'ok' => false,
                'message' => 'Cloudflare rechaza el token: ' . $this->primerError($respuesta, 'estado ' . (string) $estado),
            ]);
        }

        try {
            $zona = $this->zone(true);
        } catch (\Throwable $e) {
            return array_merge($vacio, [
                'ok' => false,
                'message' => 'El token es valido pero no encuentra la zona del dominio: ' . $e->getMessage(),
            ]);
        }

        $datos = [
            'zone' => $zona['id'],
            'status' => $zona['status'],
            'nameservers' => $zona['nameservers'],
        ];

        // Una zona que no esta activa es el fallo silencioso mas gordo de
        // todos: se pueden crear registros sin error y ninguno resuelve.
        if ($zona['status'] !== 'active') {
            return array_merge($datos, [
                'ok' => false,
                'message' => $this->explicarZonaInactiva($zona),
            ]);
        }

        return array_merge($datos, [
            'ok' => true,
            'message' => 'Token correcto y zona activa. Ya se pueden crear registros para ' . $this->domain->domain . '.',
        ]);
    }

    /**
     * Identificador de zona. Se cachea en el propio dominio para no gastar una
     * llamada a la API en cada creacion de DNS.
     */
    public function zoneId(bool $forzar = false): string
    {
        return $this->zone($forzar)['id'];
    }

    /**
     * La zona entera: identificador, estado y nameservers.
     *
     * Con el identificador guardado se pregunta por el (una sola llamada) y se
     * comprueba que el nombre siga siendo el de este dominio. Si no coincide,
     * el identificador guardado es de otra zona y se vuelve a buscar por
     * nombre: es lo que pasa cuando un dominio se mueve de cuenta de Cloudflare
     * o cuando se le cambia el nombre en el panel.
     *
     * @return array{id: string, status: string, nameservers: array<int, string>, name: string}
     */
    public function zone(bool $forzar = false): array
    {
        if (!$forzar && $this->zonaEnMemoria !== null) {
            return $this->zonaEnMemoria;
        }

        $nombre = strtolower(trim((string) $this->domain->domain));
        $guardada = (string) $this->domain->cf_zone_id;

        if (!$forzar && $guardada !== '') {
            $zona = $this->pedirZonaPorId($guardada);

            if ($zona !== null && $zona['name'] === $nombre) {
                return $this->zonaEnMemoria = $zona;
            }

            // El identificador guardado no vale: se busca de nuevo por nombre.
        }

        $zona = $this->buscarZonaPorNombre($nombre);

        if ($zona['id'] !== $guardada && $this->domain->exists) {
            $this->domain->cf_zone_id = $zona['id'];

            // Solo se guarda si el dominio esta de verdad en la base de datos.
            // En el boton "Probar conexion" se trabaja sobre una copia en
            // memoria con datos aun sin guardar, y ahi un save() crearia una
            // fila fantasma.
            $this->domain->save();
        } else {
            $this->domain->cf_zone_id = $zona['id'];
        }

        return $this->zonaEnMemoria = $zona;
    }

    /**
     * ¿Esta la zona lista para que los registros resuelvan de verdad?
     *
     * @return array{ok: bool, message: string}
     */
    public function zonaLista(): array
    {
        try {
            $zona = $this->zone();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        if ($zona['status'] !== 'active') {
            return ['ok' => false, 'message' => $this->explicarZonaInactiva($zona)];
        }

        return ['ok' => true, 'message' => 'Zona activa en Cloudflare.'];
    }

    /**
     * Crea el registro A o AAAA, o corrige el que ya hubiera.
     *
     * Antes esto solo creaba. Si el nombre ya existia (un DNS borrado a medias,
     * o creado a mano), Cloudflare devolvia error y el cliente se quedaba sin
     * poder crear su dominio nunca mas.
     *
     * @return array{id: string, creado: bool, aviso: ?string}
     */
    public function ensureAddressRecord(string $name, string $ip, bool $proxied): array
    {
        $tipo = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';
        $name = strtolower(trim($name));

        $datos = [
            'type' => $tipo,
            'name' => $name,
            'content' => $ip,
            'proxied' => $proxied,
            'ttl' => 1,
            'comment' => 'DNS Reverse',
        ];

        $aviso = null;
        $existente = $this->buscarRegistro($name, ['A', 'AAAA', 'CNAME']);

        if ($existente !== null) {
            $id = $this->actualizarRegistro($existente['id'], $datos);
            $aviso = 'Ya habia un registro ' . $existente['type'] . ' para ' . $name
                . ' apuntando a ' . $existente['content'] . '. Se ha corregido para que apunte a ' . $ip . '.';

            return ['id' => $id, 'creado' => false, 'aviso' => $aviso];
        }

        return ['id' => $this->createRecord($datos), 'creado' => true, 'aviso' => null];
    }

    /**
     * Crea un registro A o AAAA. Devuelve el identificador del registro.
     *
     * Se mantiene por compatibilidad con lo que ya llamaba a este metodo.
     */
    public function createAddressRecord(string $name, string $ip, bool $proxied): string
    {
        return $this->ensureAddressRecord($name, $ip, $proxied)['id'];
    }

    /**
     * Registro SRV de Minecraft. Apunta al FQDN del nodo y al puerto real del
     * servidor, que es lo que permite conectarse sin escribir el puerto.
     */
    public function createMinecraftSrv(string $name, string $target, int $port, int $priority = 0, int $weight = 5): string
    {
        $completo = '_minecraft._tcp.' . strtolower(trim($name)) . '.' . strtolower(trim((string) $this->domain->domain));
        $existente = $this->buscarRegistro($completo, ['SRV']);

        $datos = [
            'type' => 'SRV',
            'data' => [
                'service' => '_minecraft',
                'proto' => '_tcp',
                'name' => $name,
                'priority' => $priority,
                'weight' => $weight,
                'port' => $port,
                'target' => $target,
            ],
            'comment' => 'DNS Reverse',
        ];

        if ($existente !== null) {
            return $this->actualizarRegistro($existente['id'], $datos);
        }

        return $this->createRecord($datos);
    }

    /**
     * Vuelve a leer un registro de Cloudflare para confirmar que quedo puesto.
     *
     * @return array{ok: bool, message: string, content: ?string, proxied: ?bool}
     */
    public function verificarRegistro(string $recordId, string $nombreEsperado, string $contenidoEsperado): array
    {
        if ($recordId === '' || !$this->usable()) {
            return ['ok' => false, 'message' => 'Sin registro que comprobar.', 'content' => null, 'proxied' => null];
        }

        try {
            $respuesta = $this->client()->get(self::BASE . '/zones/' . $this->zoneId() . '/dns_records/' . $recordId);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo releer el registro: ' . $e->getMessage(), 'content' => null, 'proxied' => null];
        }

        if (!$respuesta->successful()) {
            return [
                'ok' => false,
                'message' => 'Cloudflare dice que ese registro no existe: ' . $this->primerError($respuesta),
                'content' => null,
                'proxied' => null,
            ];
        }

        $nombre = strtolower((string) $respuesta->json('result.name'));
        $contenido = (string) $respuesta->json('result.content');
        $naranja = (bool) $respuesta->json('result.proxied');

        if ($nombre !== strtolower($nombreEsperado)) {
            return [
                'ok' => false,
                'message' => 'El registro se creo con el nombre ' . $nombre . ' en vez de ' . $nombreEsperado . '.',
                'content' => $contenido,
                'proxied' => $naranja,
            ];
        }

        // Con la nube naranja Cloudflare no cambia el content, asi que esto
        // vale para los dos casos.
        if ($contenido !== '' && $contenido !== $contenidoEsperado) {
            return [
                'ok' => false,
                'message' => 'El registro apunta a ' . $contenido . ' en vez de a ' . $contenidoEsperado . '.',
                'content' => $contenido,
                'proxied' => $naranja,
            ];
        }

        return ['ok' => true, 'message' => 'Registro correcto en Cloudflare.', 'content' => $contenido, 'proxied' => $naranja];
    }

    public function deleteRecord(string $recordId): bool
    {
        if ($recordId === '' || !$this->usable()) {
            return false;
        }

        try {
            $respuesta = $this->client()->delete(self::BASE . '/zones/' . $this->zoneId() . '/dns_records/' . $recordId);
        } catch (\Throwable) {
            return false;
        }

        // Un 404 significa que ya no esta, que es justo lo que queriamos.
        return $respuesta->successful() || $respuesta->status() === 404;
    }

    /**
     * Busca registros por nombre. Sirve para limpiar restos cuando el
     * identificador guardado se perdio (por ejemplo si el DNS se creo con la
     * version anterior y fallo a medias).
     *
     * @return array<int, array{id: string, type: string, name: string, content: string, proxied: bool}>
     */
    public function findRecords(string $name): array
    {
        if (!$this->usable()) {
            return [];
        }

        try {
            $respuesta = $this->client()->get(self::BASE . '/zones/' . $this->zoneId() . '/dns_records', [
                'name' => strtolower(trim($name)),
                'per_page' => 100,
            ]);
        } catch (\Throwable) {
            return [];
        }

        if (!$respuesta->successful()) {
            return [];
        }

        $salida = [];

        foreach ((array) $respuesta->json('result') as $registro) {
            $salida[] = [
                'id' => (string) ($registro['id'] ?? ''),
                'type' => (string) ($registro['type'] ?? ''),
                'name' => strtolower((string) ($registro['name'] ?? '')),
                'content' => (string) ($registro['content'] ?? ''),
                'proxied' => (bool) ($registro['proxied'] ?? false),
            ];
        }

        return $salida;
    }

    // -----------------------------------------------------------------------
    //  Interno
    // -----------------------------------------------------------------------

    /**
     * @param array<int, string> $tipos
     *
     * @return array{id: string, type: string, name: string, content: string, proxied: bool}|null
     */
    private function buscarRegistro(string $nombre, array $tipos): ?array
    {
        $nombre = strtolower(trim($nombre));

        foreach ($this->findRecords($nombre) as $registro) {
            // Cloudflare filtra por nombre, pero se vuelve a comparar: mas
            // vale eso que corregir el registro equivocado.
            if ($registro['name'] === $nombre && in_array($registro['type'], $tipos, true)) {
                return $registro;
            }
        }

        return null;
    }

    private function createRecord(array $datos): string
    {
        $respuesta = $this->llamarConReintento(
            fn (string $zona) => $this->client()->post(self::BASE . '/zones/' . $zona . '/dns_records', $datos)
        );

        $id = $respuesta->json('result.id');

        if (!$respuesta->successful() || !is_string($id)) {
            throw new \RuntimeException('Cloudflare no acepto el registro: ' . $this->primerError($respuesta));
        }

        return $id;
    }

    private function actualizarRegistro(string $id, array $datos): string
    {
        $respuesta = $this->llamarConReintento(
            fn (string $zona) => $this->client()->put(self::BASE . '/zones/' . $zona . '/dns_records/' . $id, $datos)
        );

        $nuevoId = $respuesta->json('result.id');

        if (!$respuesta->successful() || !is_string($nuevoId)) {
            throw new \RuntimeException('Cloudflare no dejo corregir el registro que ya existia: ' . $this->primerError($respuesta));
        }

        return $nuevoId;
    }

    /**
     * Llama a la API y, si el fallo huele a "esa zona no es", vuelve a buscar
     * la zona y lo intenta una sola vez mas.
     *
     * @param callable(string): \Illuminate\Http\Client\Response $llamada
     */
    private function llamarConReintento(callable $llamada): \Illuminate\Http\Client\Response
    {
        $respuesta = $llamada($this->zoneId());

        if ($respuesta->successful()) {
            return $respuesta;
        }

        // 7003 / 7000 = ruta o identificador de zona que no existe.
        // 1049 / 81044 = la zona no es de este token.
        $codigos = [];

        foreach ((array) $respuesta->json('errors') as $error) {
            $codigos[] = (int) ($error['code'] ?? 0);
        }

        $esDeZona = $respuesta->status() === 404
            || array_intersect($codigos, [7000, 7003, 1049, 81044]) !== [];

        if (!$esDeZona) {
            return $respuesta;
        }

        try {
            $zona = $this->zone(true)['id'];
        } catch (\Throwable) {
            return $respuesta;
        }

        return $llamada($zona);
    }

    /**
     * @return array{id: string, status: string, nameservers: array<int, string>, name: string}|null
     */
    private function pedirZonaPorId(string $id): ?array
    {
        try {
            $respuesta = $this->client()->get(self::BASE . '/zones/' . $id);
        } catch (\Throwable) {
            return null;
        }

        if (!$respuesta->successful() || !is_array($respuesta->json('result'))) {
            return null;
        }

        return $this->normalizarZona((array) $respuesta->json('result'));
    }

    /**
     * @return array{id: string, status: string, nameservers: array<int, string>, name: string}
     */
    private function buscarZonaPorNombre(string $nombre): array
    {
        $respuesta = $this->client()->get(self::BASE . '/zones', ['name' => $nombre]);
        $resultado = $respuesta->json('result');

        if (!$respuesta->successful() || !is_array($resultado) || $resultado === []) {
            throw new \RuntimeException(
                'Cloudflare no encuentra la zona de ' . $nombre . '. ' . $this->primerError($respuesta)
                . ' Comprueba que el dominio este dado de alta en ESA cuenta de Cloudflare y que el token'
                . ' tenga permiso sobre el.'
            );
        }

        return $this->normalizarZona((array) $resultado[0]);
    }

    /**
     * @return array{id: string, status: string, nameservers: array<int, string>, name: string}
     */
    private function normalizarZona(array $zona): array
    {
        return [
            'id' => (string) ($zona['id'] ?? ''),
            'name' => strtolower((string) ($zona['name'] ?? '')),
            'status' => strtolower((string) ($zona['status'] ?? 'unknown')),
            'nameservers' => array_values(array_filter(array_map(
                'strval',
                (array) ($zona['name_servers'] ?? [])
            ))),
        ];
    }

    /**
     * @param array{id: string, status: string, nameservers: array<int, string>, name: string} $zona
     */
    private function explicarZonaInactiva(array $zona): string
    {
        $mensaje = 'La zona ' . $zona['name'] . ' esta en Cloudflare pero su estado es "' . $zona['status'] . '", no "active". '
            . 'Mientras siga asi, los registros se crean sin error pero NO resuelven: el navegador dira '
            . '"DNS_PROBE_FINISHED_NXDOMAIN".';

        if ($zona['status'] === 'pending' || $zona['status'] === 'initializing') {
            $mensaje .= ' Falta cambiar los nameservers del dominio en tu registrador (donde compraste el dominio) a los de Cloudflare';

            if ($zona['nameservers'] !== []) {
                $mensaje .= ': ' . implode(' y ', $zona['nameservers']);
            }

            $mensaje .= '. Cuando Cloudflare los detecte, la zona pasa a "active" sola.';
        } elseif ($zona['status'] === 'moved') {
            $mensaje .= ' Cloudflare dice que el dominio ya no le apunta: alguien cambio los nameservers.';
        } elseif ($zona['status'] === 'deactivated') {
            $mensaje .= ' La zona esta desactivada en la cuenta de Cloudflare.';
        }

        return $mensaje;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->domain->token(),
            'Content-Type' => 'application/json',
        ])->timeout(20)->connectTimeout(10);
    }

    private function primerError(\Illuminate\Http\Client\Response $respuesta, string $porDefecto = 'sin detalles'): string
    {
        $errores = $respuesta->json('errors');

        if (is_array($errores) && isset($errores[0]['message'])) {
            $mensaje = (string) $errores[0]['message'];
            $codigo = (int) ($errores[0]['code'] ?? 0);

            // El codigo 10000 casi siempre son permisos del token mal puestos,
            // y el mensaje de Cloudflare no lo dice.
            if ($codigo === 10000) {
                $mensaje .= ' (revisa que el token tenga permiso Zone.DNS -> Edit sobre esta zona)';
            }

            if (in_array($codigo, [81057, 81058, 81053], true)) {
                $mensaje .= ' (ya hay un registro con ese nombre en Cloudflare)';
            }

            return $mensaje;
        }

        return $porDefecto;
    }
}
