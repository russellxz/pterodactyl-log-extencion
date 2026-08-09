<?php

namespace Pterodactyl\Extensions\ArixLog\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Extensions\ArixLog\Models\ResourceSample;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

/**
 * Consulta el consumo real de los servidores preguntando a cada nodo.
 *
 * Se usa el endpoint GET /api/servers de wings, que devuelve de una sola vez
 * el estado y el consumo de todos los servidores del nodo. Pedirlo servidor a
 * servidor seria una peticion por servidor y en un nodo con cien servidores
 * eso es inviable.
 *
 * Importante: la respuesta de wings incluye tambien la configuracion completa
 * de cada servidor, con sus variables de entorno (tokens, contrasenas de
 * bases de datos, claves de API...). Aqui se descarta por completo: solo se
 * toman el identificador, el estado y el consumo. Nunca se guarda ni se
 * muestra la configuracion.
 */
class ResourceMonitor
{
    private const CACHE_SECONDS = 5;
    private const TIMEOUT = 5;

    /**
     * Consumo de todos los servidores de un nodo.
     *
     * @return array<string, array<string, mixed>> indexado por uuid
     */
    public function node(Node $node, bool $fresh = false): array
    {
        $key = 'arixlog:node-usage:' . $node->id;

        if (!$fresh) {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $usage = $this->fetch($node);

        Cache::put($key, $usage, self::CACHE_SECONDS);

        return $usage;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetch(Node $node): array
    {
        try {
            $client = new Client([
                'base_uri' => $node->getConnectionAddress(),
                'timeout' => self::TIMEOUT,
                'connect_timeout' => 3,
                'verify' => app()->environment('production'),
                'headers' => [
                    'Authorization' => 'Bearer ' . $node->getDecryptedKey(),
                    'Accept' => 'application/json',
                ],
            ]);

            $response = $client->get('/api/servers', ['query' => ['per_page' => 5000]]);
            $decoded = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            return ['__error' => ['message' => mb_substr($e->getMessage(), 0, 200)]];
        }

        // Segun la version, wings devuelve una lista plana o un objeto
        // paginado con la lista dentro de "data".
        $rows = $decoded['data'] ?? $decoded;

        if (!is_array($rows)) {
            return [];
        }

        $usage = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $uuid = $row['configuration']['uuid'] ?? ($row['uuid'] ?? null);

            if (!is_string($uuid)) {
                continue;
            }

            $utilization = is_array($row['utilization'] ?? null) ? $row['utilization'] : [];

            // Solo estos campos. La configuracion se queda fuera a proposito.
            $usage[$uuid] = [
                'state' => (string) ($row['state'] ?? ($utilization['state'] ?? 'offline')),
                'is_suspended' => (bool) ($row['is_suspended'] ?? false),
                'cpu_absolute' => round((float) ($utilization['cpu_absolute'] ?? 0), 2),
                'memory_bytes' => (int) ($utilization['memory_bytes'] ?? 0),
                'memory_limit_bytes' => (int) ($utilization['memory_limit_bytes'] ?? 0),
                'disk_bytes' => (int) ($utilization['disk_bytes'] ?? 0),
                'network_rx_bytes' => (int) ($utilization['network']['rx_bytes'] ?? 0),
                'network_tx_bytes' => (int) ($utilization['network']['tx_bytes'] ?? 0),
                'uptime' => (int) ($utilization['uptime'] ?? 0),
            ];
        }

        return $usage;
    }

    /**
     * Foto completa: todos los servidores con su dueno y su consumo.
     *
     * @param int|null $nodeId limitar a un nodo concreto
     *
     * @return array{servers: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public function snapshot(?int $nodeId = null, bool $fresh = false): array
    {
        $nodes = Node::query()
            ->when($nodeId, fn ($q) => $q->whereKey($nodeId))
            ->get();

        $usageByNode = [];
        $errors = [];

        foreach ($nodes as $node) {
            $usage = $this->node($node, $fresh);

            if (isset($usage['__error'])) {
                $errors[] = sprintf('Nodo "%s": %s', $node->name, $usage['__error']['message']);
                unset($usage['__error']);
            }

            $usageByNode[$node->id] = $usage;
        }

        $servers = Server::query()
            ->when($nodeId, fn ($q) => $q->where('node_id', $nodeId))
            ->with(['user:id,username,email,name_first,name_last', 'node:id,name', 'allocation:id,ip,ip_alias,port'])
            ->get();

        $rows = [];

        foreach ($servers as $server) {
            $usage = $usageByNode[$server->node_id][$server->uuid] ?? null;

            $rows[] = [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'uuid_short' => $server->uuidShort,
                'name' => $server->name,
                'status' => $server->status,
                'node' => $server->node->name ?? '-',
                'node_id' => $server->node_id,
                'address' => $server->allocation
                    ? (($server->allocation->ip_alias ?: $server->allocation->ip) . ':' . $server->allocation->port)
                    : '-',
                'owner' => $this->ownerName($server),
                'owner_email' => $server->user->email ?? '-',
                'owner_id' => $server->owner_id,
                'limits' => [
                    'memory' => (int) $server->memory,
                    'disk' => (int) $server->disk,
                    'cpu' => (int) $server->cpu,
                ],
                'usage' => $usage,
                'online' => $usage !== null && ($usage['state'] ?? 'offline') === 'running',
                'reachable' => $usage !== null,
            ];
        }

        // Los que mas consumen, arriba.
        usort($rows, function ($a, $b) {
            $ca = $a['usage']['cpu_absolute'] ?? -1;
            $cb = $b['usage']['cpu_absolute'] ?? -1;

            if ($ca === $cb) {
                return ($b['usage']['memory_bytes'] ?? 0) <=> ($a['usage']['memory_bytes'] ?? 0);
            }

            return $cb <=> $ca;
        });

        return ['servers' => $rows, 'errors' => $errors];
    }

    /**
     * Guarda una muestra de cada servidor encendido para poder consultar el
     * historico y detectar abusos que solo se ven con el tiempo.
     *
     * @return int cuantas muestras se guardaron
     */
    public function sample(): int
    {
        $now = now();
        $stored = 0;

        foreach (Node::query()->get() as $node) {
            $usage = $this->node($node, true);
            unset($usage['__error']);

            if ($usage === []) {
                continue;
            }

            $servers = Server::query()
                ->where('node_id', $node->id)
                ->whereIn('uuid', array_keys($usage))
                ->get(['id', 'uuid', 'node_id']);

            $rows = [];

            foreach ($servers as $server) {
                $data = $usage[$server->uuid];

                // Un servidor apagado no aporta nada al historico y llenaria
                // la tabla de ceros.
                if (($data['state'] ?? 'offline') !== 'running') {
                    continue;
                }

                $rows[] = [
                    'server_id' => $server->id,
                    'node_id' => $server->node_id,
                    'cpu_absolute' => $data['cpu_absolute'],
                    'memory_bytes' => $data['memory_bytes'],
                    'memory_limit_bytes' => $data['memory_limit_bytes'],
                    'disk_bytes' => $data['disk_bytes'],
                    'network_rx_bytes' => $data['network_rx_bytes'],
                    'network_tx_bytes' => $data['network_tx_bytes'],
                    'state' => $data['state'],
                    'sampled_at' => $now,
                ];
            }

            if ($rows !== []) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    ResourceSample::insert($chunk);
                }

                $stored += count($rows);
            }
        }

        return $stored;
    }

    /**
     * Ranking historico de los servidores que mas recursos consumen.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topConsumers(int $hours = 24, string $order = 'cpu', int $limit = 25): Collection
    {
        $order = in_array($order, ['cpu', 'memory', 'disk'], true) ? $order : 'cpu';
        $column = match ($order) {
            'memory' => 'avg_memory',
            'disk' => 'max_disk',
            default => 'avg_cpu',
        };

        $rows = ResourceSample::query()
            ->selectRaw('server_id')
            ->selectRaw('AVG(cpu_absolute) as avg_cpu')
            ->selectRaw('MAX(cpu_absolute) as max_cpu')
            ->selectRaw('AVG(memory_bytes) as avg_memory')
            ->selectRaw('MAX(memory_bytes) as max_memory')
            ->selectRaw('MAX(disk_bytes) as max_disk')
            ->selectRaw('COUNT(*) as samples')
            ->where('sampled_at', '>=', now()->subHours(max(1, $hours)))
            ->groupBy('server_id')
            ->orderByDesc($column)
            ->limit(max(1, min(200, $limit)))
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $servers = Server::query()
            ->whereIn('id', $rows->pluck('server_id')->all())
            ->with(['user:id,username,email,name_first,name_last', 'node:id,name'])
            ->get()
            ->keyBy('id');

        return $rows->map(function ($row) use ($servers) {
            $server = $servers->get($row->server_id);

            return [
                'server_id' => $row->server_id,
                'name' => $server->name ?? '(servidor borrado)',
                'uuid_short' => $server->uuidShort ?? null,
                'node' => $server->node->name ?? '-',
                'owner' => $server ? $this->ownerName($server) : '-',
                'owner_email' => $server->user->email ?? '-',
                'avg_cpu' => round((float) $row->avg_cpu, 2),
                'max_cpu' => round((float) $row->max_cpu, 2),
                'avg_memory' => (int) $row->avg_memory,
                'max_memory' => (int) $row->max_memory,
                'max_disk' => (int) $row->max_disk,
                'memory_limit' => (int) (($server->memory ?? 0) * 1024 * 1024),
                'samples' => (int) $row->samples,
            ];
        });
    }

    private function ownerName(Server $server): string
    {
        $user = $server->user;

        if (!$user) {
            return '(sin dueno)';
        }

        $name = trim(($user->name_first ?? '') . ' ' . ($user->name_last ?? ''));

        return $name !== '' ? $name : (string) $user->username;
    }
}
