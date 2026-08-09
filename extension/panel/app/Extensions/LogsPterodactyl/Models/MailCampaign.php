<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un envio en bloque hecho desde el panel.
 */
class MailCampaign extends Model
{
    protected $table = 'logspterodactyl_mail_campaigns';

    protected $guarded = ['id'];

    protected $casts = [
        'total' => 'integer',
        'sent' => 'integer',
        'failed' => 'integer',
        'created_by' => 'integer',
        'finished_at' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(MailLog::class, 'campaign_id');
    }
}
