<?php

namespace Pterodactyl\Extensions\DnsReverse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Registro propio de la extension: quien creo o borro que DNS y cuando.
 *
 * Sirve para dos cosas: saber a quien preguntar cuando un dominio da guerra y
 * poder demostrar que la extension no borro nada por su cuenta.
 *
 * @property int $id
 * @property string $level
 * @property string $action
 */
class DnsEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dnsreverse_events';

    protected $fillable = [
        'level',
        'action',
        'domain',
        'server_id',
        'user_id',
        'message',
        'context',
    ];

    public static function record(
        string $level,
        string $action,
        string $message,
        array $context = [],
        ?string $domain = null,
        ?int $serverId = null,
        ?int $userId = null
    ): void {
        try {
            if (!Schema::hasTable('dnsreverse_events')) {
                return;
            }

            self::create([
                'level' => $level,
                'action' => $action,
                'domain' => $domain,
                'server_id' => $serverId,
                'user_id' => $userId ?? optional(auth()->user())->id,
                'message' => mb_substr($message, 0, 2000),
                'context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // El registro no puede ser nunca el motivo de que falle una accion.
        }
    }

    public function decodedContext(): array
    {
        if (empty($this->context)) {
            return [];
        }

        $datos = json_decode((string) $this->context, true);

        return is_array($datos) ? $datos : [];
    }
}
