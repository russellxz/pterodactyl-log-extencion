<?php

namespace Pterodactyl\Extensions\ArixLog\Services;

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;

/**
 * Cambia el puerto principal de un servidor por otro libre del mismo nodo.
 *
 * Se hace a mano en lugar de usar BuildModificationService del panel porque
 * ese servicio exige que la asignacion ya pertenezca al servidor y ademas
 * reescribe los limites de bases de datos, copias y asignaciones extra cuando
 * no se le pasan; aqui solo queremos tocar el puerto y nada mas.
 */
class PortRotator
{
    /**
     * Busca una asignacion libre para el servidor.
     *
     * @param bool $preferSameIp intenta primero mantener la misma IP
     */
    public function findFreeAllocation(Server $server, bool $preferSameIp = true): ?Allocation
    {
        $current = $server->allocation;

        $base = fn () => Allocation::query()
            ->where('node_id', $server->node_id)
            ->whereNull('server_id')
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id));

        if ($preferSameIp && $current) {
            $sameIp = $base()->where('ip', $current->ip)->orderBy('port')->first();

            if ($sameIp) {
                return $sameIp;
            }
        }

        return $base()->orderBy('ip')->orderBy('port')->first();
    }

    /**
     * Mueve el servidor a una asignacion libre y suelta la anterior.
     *
     * Las asignaciones adicionales del servidor (si las tiene) se respetan:
     * solo se libera la que era la principal.
     *
     * @return array{old: ?string, new: string, old_id: ?int, new_id: int}|null
     *         null si el nodo no tiene ningun puerto libre
     */
    public function rotate(Server $server, bool $preferSameIp = true): ?array
    {
        $target = $this->findFreeAllocation($server, $preferSameIp);

        if (!$target) {
            return null;
        }

        $previous = $server->allocation;

        DB::transaction(function () use ($server, $target, $previous) {
            // Se vuelve a comprobar dentro de la transaccion y bloqueando la
            // fila: entre la busqueda y este punto otro proceso de creacion de
            // servidores podria haberse quedado con el puerto.
            $locked = Allocation::query()
                ->whereKey($target->id)
                ->whereNull('server_id')
                ->lockForUpdate()
                ->firstOrFail();

            $locked->server_id = $server->id;
            $locked->notes = 'ArixLog: puerto asignado automaticamente el ' . now()->toDateTimeString();
            $locked->save();

            $server->allocation_id = $locked->id;
            $server->save();

            if ($previous && $previous->id !== $locked->id) {
                $previous->server_id = null;
                $previous->notes = null;
                $previous->save();
            }
        });

        $server->refresh()->load('allocation');

        return [
            'old' => $previous ? $this->label($previous) : null,
            'old_id' => $previous?->id,
            'new' => $this->label($target->refresh()),
            'new_id' => $target->id,
        ];
    }

    public function label(?Allocation $allocation): string
    {
        if (!$allocation) {
            return 'sin asignacion';
        }

        $host = $allocation->ip_alias ?: $allocation->ip;

        return $host . ':' . $allocation->port;
    }

    /**
     * Cuantos puertos libres le quedan al nodo. Se usa para avisar en el panel
     * antes de que el sistema automatico se quede sin donde mover servidores.
     */
    public function freeCount(int $nodeId): int
    {
        return Allocation::query()
            ->where('node_id', $nodeId)
            ->whereNull('server_id')
            ->count();
    }
}
