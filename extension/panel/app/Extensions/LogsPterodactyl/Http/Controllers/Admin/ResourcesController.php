<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\LogsPterodactyl\Services\ResourceMonitor;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Node;

/**
 * Consumo de recursos en tiempo real y ranking de los que mas gastan.
 */
class ResourcesController extends Controller
{
    public function __construct(private ResourceMonitor $monitor, private Settings $settings)
    {
    }

    public function index(Request $request)
    {
        return view('logspterodactyl::admin.resources', [
            'nodes' => Node::query()->orderBy('name')->get(['id', 'name']),
            'nodeId' => $request->query('node') ? (int) $request->query('node') : null,
            'alertCpu' => $this->settings->int('resources_alert_cpu', 1, 100000),
            'alertMemory' => $this->settings->int('resources_alert_memory', 1, 100),
            'samplingEnabled' => $this->settings->bool('resources_enabled'),
            'retentionDays' => $this->settings->int('resources_retention_days', 1, 3650),
        ]);
    }

    /**
     * Foto del consumo ahora mismo. La pantalla la pide cada pocos segundos.
     */
    public function live(Request $request): JsonResponse
    {
        $nodeId = $request->query('node') ? (int) $request->query('node') : null;

        $snapshot = $this->monitor->snapshot($nodeId, $request->boolean('fresh'));

        $totals = [
            'servers' => count($snapshot['servers']),
            'online' => 0,
            'cpu' => 0.0,
            'memory' => 0,
            'disk' => 0,
        ];

        foreach ($snapshot['servers'] as $server) {
            if ($server['online']) {
                $totals['online']++;
            }

            $totals['cpu'] += (float) ($server['usage']['cpu_absolute'] ?? 0);
            $totals['memory'] += (int) ($server['usage']['memory_bytes'] ?? 0);
            $totals['disk'] += (int) ($server['usage']['disk_bytes'] ?? 0);
        }

        $totals['cpu'] = round($totals['cpu'], 2);

        return new JsonResponse([
            'servers' => $snapshot['servers'],
            'errors' => $snapshot['errors'],
            'totals' => $totals,
            'alert_cpu' => $this->settings->int('resources_alert_cpu', 1, 100000),
            'alert_memory' => $this->settings->int('resources_alert_memory', 1, 100),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Ranking historico: quien consume mas de verdad, no solo en este instante.
     */
    public function top(Request $request): JsonResponse
    {
        $hours = (int) $request->query('hours', 24);
        $order = (string) $request->query('order', 'cpu');

        return new JsonResponse([
            'rows' => $this->monitor->topConsumers($hours, $order, 30)->all(),
            'hours' => $hours,
            'order' => $order,
        ]);
    }
}
