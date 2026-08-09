<?php

namespace Pterodactyl\Extensions\DnsReverse\Services;

use Illuminate\Support\Facades\Http;
use Pterodactyl\Extensions\DnsReverse\Models\DnsDomain;

/**
 * Cliente de la API de Cloudflare atado a UN dominio.
 *
 * La diferencia con la version anterior es justo esta: el token no es global,
 * viene del dominio. Asi puedes tener ultraplus.click en una cuenta de
 * Cloudflare y otrodominio.com en otra cuenta distinta, cada uno con su token.
 */
class CloudflareClient
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

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
     * Comprueba que el token sirve y que la zona existe.
     *
     * @return array{ok: bool, message: string, zone: ?string}
     */
    public function check(): array
    {
        if (!$this->usable()) {
            return ['ok' => false, 'message' => 'Este dominio no tiene token de Cloudflare guardado.', 'zone' => null];
        }

        try {
            $respuesta = $this->client()->get(self::BASE . '/user/tokens/verify');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con Cloudflare: ' . $e->getMessage(), 'zone' => null];
        }

        $estado = $respuesta->json('result.status');

        if (!$respuesta->successful() || $estado !== 'active') {
            return [
                'ok' => false,
                'message' => 'Cloudflare rechaza el token: ' . $this->primerError($respuesta, 'estado ' . (string) $estado),
                'zone' => null,
            ];
        }

        try {
            $zona = $this->zoneId(true);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'El token es valido pero no encuentra la zona del dominio: ' . $e->getMessage(),
                'zone' => null,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Token correcto y zona encontrada. Ya se pueden crear registros para ' . $this->domain->domain . '.',
            'zone' => $zona,
        ];
    }

    /**
     * Identificador de zona. Se cachea en el propio dominio para no gastar una
     * llamada a la API en cada creacion de DNS.
     */
    public function zoneId(bool $forzar = false): string
    {
        if (!$forzar && !empty($this->domain->cf_zone_id)) {
            return (string) $this->domain->cf_zone_id;
        }

        $respuesta = $this->client()->get(self::BASE . '/zones', ['name' => $this->domain->domain]);
        $resultado = $respuesta->json('result');

        if (!$respuesta->successful() || !is_array($resultado) || $resultado === []) {
            throw new \RuntimeException(
                'Cloudflare no encuentra la zona de ' . $this->domain->domain . '. ' . $this->primerError($respuesta)
            );
        }

        $zona = (string) $resultado[0]['id'];
        $anterior = (string) $this->domain->cf_zone_id;
        $this->domain->cf_zone_id = $zona;

        // Solo se guarda si el dominio esta de verdad en la base de datos. En
        // el boton "Probar conexion" se trabaja sobre una copia en memoria con
        // datos aun sin guardar, y ahi un save() crearia una fila fantasma.
        if ($zona !== $anterior && $this->domain->exists) {
            $this->domain->save();
        }

        return $zona;
    }

    /**
     * Crea un registro A o AAAA. Devuelve el identificador del registro.
     */
    public function createAddressRecord(string $name, string $ip, bool $proxied): string
    {
        $tipo = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A';

        return $this->createRecord([
            'type' => $tipo,
            'name' => $name,
            'content' => $ip,
            'proxied' => $proxied,
            'ttl' => 1,
            'comment' => 'DNS Reverse',
        ]);
    }

    /**
     * Registro SRV de Minecraft. Apunta al FQDN del nodo y al puerto real del
     * servidor, que es lo que permite conectarse sin escribir el puerto.
     */
    public function createMinecraftSrv(string $name, string $target, int $port, int $priority = 0, int $weight = 5): string
    {
        return $this->createRecord([
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
        ]);
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
     * @return array<int, array{id: string, type: string, name: string}>
     */
    public function findRecords(string $name): array
    {
        if (!$this->usable()) {
            return [];
        }

        try {
            $respuesta = $this->client()->get(self::BASE . '/zones/' . $this->zoneId() . '/dns_records', [
                'name' => $name,
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
                'name' => (string) ($registro['name'] ?? ''),
            ];
        }

        return $salida;
    }

    private function createRecord(array $datos): string
    {
        $respuesta = $this->client()->post(self::BASE . '/zones/' . $this->zoneId() . '/dns_records', $datos);

        $id = $respuesta->json('result.id');

        if (!$respuesta->successful() || !is_string($id)) {
            throw new \RuntimeException('Cloudflare no acepto el registro: ' . $this->primerError($respuesta));
        }

        return $id;
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

            // El codigo 10000 casi siempre son permisos del token mal puestos,
            // y el mensaje de Cloudflare no lo dice.
            if ((int) ($errores[0]['code'] ?? 0) === 10000) {
                $mensaje .= ' (revisa que el token tenga permiso Zone.DNS -> Edit sobre esta zona)';
            }

            return $mensaje;
        }

        return $porDefecto;
    }
}
