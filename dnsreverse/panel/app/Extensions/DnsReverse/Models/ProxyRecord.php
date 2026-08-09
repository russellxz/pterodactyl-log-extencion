<?php

namespace Pterodactyl\Extensions\DnsReverse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;

/**
 * Un DNS creado por un cliente.
 *
 * Usa la MISMA tabla que la version anterior (`server_proxy`) a proposito: asi
 * todo lo que tus clientes ya tenian creado aparece en cuanto se instala esta
 * extension, sin migrar nada y sin que nadie tenga que volver a crear su DNS.
 *
 * @property int $id
 * @property int $server_id
 * @property string $domain
 * @property string $proxy_type
 * @property string|null $base_domain
 * @property string|null $cf_record_id
 * @property int $allocation_id
 * @property bool $ssl_enabled
 * @property string $ssl_mode
 * @property int|null $domain_id
 * @property int|null $created_by
 */
class ProxyRecord extends Model
{
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_SUBDOMAIN = 'subdomain';
    public const TYPE_SRV = 'srv';

    public const SSL_NONE = 'none';
    public const SSL_ORIGIN = 'origin';
    public const SSL_LETSENCRYPT = 'letsencrypt';
    public const SSL_LEGACY = 'legacy';

    protected $table = 'server_proxy';

    /**
     * La tabla original no tenia created_at/updated_at. Esta extension los
     * anade como columnas opcionales, asi que Eloquent puede gestionarlos:
     * las filas viejas se quedan con el valor a nulo y no pasa nada.
     */
    public $timestamps = true;

    protected $fillable = [
        'server_id',
        'domain',
        'proxy_type',
        'base_domain',
        'cf_record_id',
        'allocation_id',
        'ssl_enabled',
        'ssl_mode',
        'domain_id',
        'created_by',
        'synced_at',
        'cert_expires_at',
        'last_error',
    ];

    protected $casts = [
        'ssl_enabled' => 'boolean',
        'synced_at' => 'datetime',
        'cert_expires_at' => 'datetime',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class, 'allocation_id');
    }

    public function dnsDomain(): BelongsTo
    {
        return $this->belongsTo(DnsDomain::class, 'domain_id');
    }

    public function url(): string
    {
        return ($this->ssl_enabled ? 'https://' : 'http://') . $this->domain;
    }

    public function typeLabel(): string
    {
        return match ($this->proxy_type) {
            self::TYPE_SUBDOMAIN => 'Subdominio',
            self::TYPE_SRV => 'Minecraft SRV',
            default => 'Dominio propio',
        };
    }

    public function sslLabel(): string
    {
        return match ($this->ssl_mode) {
            self::SSL_ORIGIN => 'Certificado de origen',
            self::SSL_LETSENCRYPT => "Let's Encrypt",
            self::SSL_LEGACY => 'Si (anterior)',
            default => $this->ssl_enabled ? 'Si' : 'No',
        };
    }

    /**
     * Datos que se le mandan al area de cliente. Nunca sale de aqui nada
     * sensible (ni claves ni identificadores de Cloudflare).
     */
    public function toClientArray(): array
    {
        $allocation = $this->allocation;

        return [
            'id' => (int) $this->id,
            'domain' => (string) $this->domain,
            'url' => $this->url(),
            'type' => (string) $this->proxy_type,
            'type_label' => $this->typeLabel(),
            'ssl' => (bool) $this->ssl_enabled,
            'ssl_mode' => (string) $this->ssl_mode,
            'ssl_label' => $this->sslLabel(),
            'address' => $allocation ? (($allocation->alias ?: $allocation->ip) . ':' . $allocation->port) : null,
            'port' => $allocation?->port,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'cert_expires_at' => optional($this->cert_expires_at)->toDateTimeString(),
            'error' => $this->last_error ? (string) $this->last_error : null,
        ];
    }
}
