<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un intento de actualizacion del panel, con su respaldo asociado.
 */
class UpdateRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $table = 'logspterodactyl_update_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'started_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
