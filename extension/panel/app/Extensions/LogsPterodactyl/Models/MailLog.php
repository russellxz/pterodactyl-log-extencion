<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Copia de cada correo que sale del panel, con su resultado.
 */
class MailLog extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $table = 'logspterodactyl_mail_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'integer',
        'campaign_id' => 'integer',
        'resend_count' => 'integer',
        'sent_at' => 'datetime',
        'last_resent_at' => 'datetime',
    ];

    /**
     * Las direcciones se guardan separadas por coma para poder buscarlas con
     * un LIKE sin depender del soporte de JSON del motor de base de datos.
     */
    public function recipients(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->to_addresses))));
    }
}
