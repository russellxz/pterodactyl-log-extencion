<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Muestra puntual del consumo de un servidor. Sirve para el historico y para
 * el ranking de los que mas gastan.
 */
class ResourceSample extends Model
{
    public $timestamps = false;

    protected $table = 'logspterodactyl_resource_samples';

    protected $guarded = ['id'];

    protected $casts = [
        'server_id' => 'integer',
        'node_id' => 'integer',
        'cpu_absolute' => 'float',
        'memory_bytes' => 'integer',
        'memory_limit_bytes' => 'integer',
        'disk_bytes' => 'integer',
        'network_rx_bytes' => 'integer',
        'network_tx_bytes' => 'integer',
        'sampled_at' => 'datetime',
    ];
}
