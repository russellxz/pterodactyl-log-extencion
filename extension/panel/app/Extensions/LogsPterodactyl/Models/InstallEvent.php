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
}
