<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Registro interno de la extension: todo lo que hace queda anotado aqui.
 */
class ExtensionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'logspterodactyl_events';

    protected $guarded = ['id'];

    protected $casts = [
        'context' => 'array',
        'user_id' => 'integer',
        'server_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public static function log(string $level, string $channel, string $message, array $context = [], ?int $serverId = null): void
    {
        try {
            static::create([
                'level' => $level,
                'channel' => $channel,
                'message' => mb_substr($message, 0, 500),
                'context' => $context ?: null,
                'server_id' => $serverId,
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Nunca dejamos que un fallo al registrar rompa la accion real.
        }
    }
}
