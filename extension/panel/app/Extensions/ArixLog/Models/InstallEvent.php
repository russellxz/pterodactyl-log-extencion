<?php

namespace Pterodactyl\Extensions\ArixLog\Models;

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

    protected $table = 'arixlog_install_events';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'integer',
        'user_id' => 'integer',
        'node_id' => 'integer',
        'egg_id' => 'integer',
        'is_reinstall' => 'boolean',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_INSTALLING;
    }
}
