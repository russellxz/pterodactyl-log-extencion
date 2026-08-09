<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila por cada instalacion (o reinstalacion) de servidor.
 */
class InstallEvent extends Model
{
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_TIMEOUT = 'timeout';

    /**
     * El nodo devolvio el fallo al instante despues de haberle parado a este
     * mismo servidor otra instalacion. Casi siempre significa que wings sigue
     * ocupado con el contenedor de instalacion anterior y ha rechazado esta:
     * no es culpa de los datos del cliente y se arregla en el nodo.
     */
    public const DIAG_NODE_BUSY = 'nodo_ocupado';

    /**
     * Varios intentos seguidos que el nodo nunca llego a contestar.
     */
    public const DIAG_NODE_SILENT = 'nodo_sin_respuesta';

    /**
     * Fallo muy rapido, pero sin antecedentes que lo expliquen. Puede ser un
     * script del egg que se rompe enseguida o una imagen que no se descarga.
     */
    public const DIAG_FAST_FAIL = 'fallo_rapido';

    protected $table = 'logspterodactyl_install_events';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'integer',
        'user_id' => 'integer',
        'node_id' => 'integer',
        'egg_id' => 'integer',
        'is_reinstall' => 'boolean',
        'forced' => 'boolean',
        'wings_deleted' => 'boolean',
        'attempt' => 'integer',
        'previous_id' => 'integer',
        'duration_seconds' => 'integer',
        'unblocked_times' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'unblock_until' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_INSTALLING;
    }

    /**
     * El intento anterior del mismo servidor, si lo hubo.
     */
    public function previous(): ?self
    {
        return $this->previous_id ? self::query()->find($this->previous_id) : null;
    }

    /**
     * El intento siguiente del mismo servidor, si ya se hizo.
     */
    public function next(): ?self
    {
        return self::query()->where('previous_id', $this->id)->first();
    }

    public function wasStoppedBySystem(): bool
    {
        return in_array($this->status, [self::STATUS_TIMEOUT, self::STATUS_CANCELLED], true);
    }

    /**
     * ¿El problema esta en el nodo y no en los datos que puso el cliente?
     */
    public function blamesNode(): bool
    {
        return in_array($this->diagnosis, [self::DIAG_NODE_BUSY, self::DIAG_NODE_SILENT], true);
    }

    /**
     * Comandos que hay que ejecutar EN EL NODO para soltar el bloqueo.
     *
     * Wings guarda el "estoy instalando" en su memoria y no expone ninguna
     * orden para soltarlo (su API solo tiene power, commands, install,
     * reinstall, sync y delete). Mientras el contenedor de instalacion
     * anterior siga vivo, rechaza cualquier instalacion nueva de ese servidor.
     * Se suelta matando ese contenedor, o reiniciando wings.
     *
     * @return array<int, string>
     */
    public function nodeCommands(): array
    {
        return [
            'docker rm -f ' . ($this->server_uuid ?: '<uuid-del-servidor>') . '_installer',
            'systemctl restart wings',
        ];
    }
}
