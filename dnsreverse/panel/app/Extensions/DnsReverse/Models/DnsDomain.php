<?php

namespace Pterodactyl\Extensions\DnsReverse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Un dominio base de la casa (por ejemplo ultraplus.click).
 *
 * Cada uno lleva SU token de Cloudflare y SU certificado de origen, que es la
 * diferencia principal con la version anterior: alli habia un unico token y un
 * unico certificado para todo, asi que no se podian mezclar dominios de
 * cuentas de Cloudflare distintas.
 *
 * @property int $id
 * @property string $domain
 * @property string|null $label
 * @property string|null $cf_token
 * @property string|null $cf_zone_id
 * @property string|null $ssl_cert
 * @property string|null $ssl_key
 * @property string $proxied_mode
 * @property bool $allow_subdomain
 * @property bool $allow_srv
 * @property bool $allow_letsencrypt
 * @property bool $active
 * @property string|null $reserved
 * @property string|null $notes
 */
class DnsDomain extends Model
{
    protected $table = 'dnsreverse_domains';

    protected $fillable = [
        'domain',
        'label',
        'cf_token',
        'cf_zone_id',
        'ssl_cert',
        'ssl_key',
        'proxied_mode',
        'allow_subdomain',
        'allow_srv',
        'allow_letsencrypt',
        'active',
        'reserved',
        'notes',
    ];

    protected $casts = [
        'allow_subdomain' => 'boolean',
        'allow_srv' => 'boolean',
        'allow_letsencrypt' => 'boolean',
        'active' => 'boolean',
    ];

    /**
     * El token nunca se guarda en claro. Se cifra con la APP_KEY del panel,
     * igual que hace el propio panel con los tokens de los nodos.
     */
    public function setToken(?string $token): void
    {
        $token = trim((string) $token);

        $this->cf_token = $token === '' ? null : Crypt::encryptString($token);
    }

    public function token(): string
    {
        if (empty($this->cf_token)) {
            return '';
        }

        try {
            return Crypt::decryptString($this->cf_token);
        } catch (\Throwable) {
            // Cifrado con otra APP_KEY (panel restaurado de un respaldo con
            // clave distinta). Se trata como "sin token" para que el
            // administrador lo vuelva a poner.
            return '';
        }
    }

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    public function hasOriginCertificate(): bool
    {
        return trim((string) $this->ssl_cert) !== '' && trim((string) $this->ssl_key) !== '';
    }

    /**
     * Nombres que un cliente no puede pedir como subdominio.
     *
     * @return array<int, string>
     */
    public function reservedNames(): array
    {
        $lista = array_filter(array_map('trim', explode(',', (string) $this->reserved)));

        return array_values(array_unique(array_map('strtolower', $lista)));
    }

    /**
     * ¿El registro de Cloudflare va con nube naranja?
     *
     * Con certificado de origen SI: ese certificado solo lo acepta Cloudflare,
     * asi que el trafico tiene que pasar por ahi a la fuerza.
     *
     * Con Let's Encrypt NUNCA, y esto no es negociable ni siquiera si el
     * dominio esta puesto en "siempre naranja": para emitir el certificado,
     * Let's Encrypt tiene que llegar por el puerto 80 hasta el nodo. Con la
     * nube naranja de por medio la comprobacion se queda en Cloudflare y la
     * emision falla, asi que el cliente se queda sin dominio y sin saber por
     * que. Una vez emitido, si se quiere, se puede poner la nube en naranja a
     * mano desde Cloudflare.
     */
    public function shouldProxy(string $sslMode): bool
    {
        if ($sslMode === ProxyRecord::SSL_LETSENCRYPT) {
            return false;
        }

        return match ($this->proxied_mode) {
            'always' => true,
            'never' => false,
            default => $sslMode === ProxyRecord::SSL_ORIGIN,
        };
    }

    public function proxies()
    {
        return $this->hasMany(ProxyRecord::class, 'domain_id');
    }

    /**
     * Engancha a su dominio los DNS que estaban sueltos.
     *
     * Pasa con todo lo que se creo con la version anterior (que no guardaba
     * esta relacion) y con lo que se creo antes de dar de alta el dominio. Sin
     * esto, esos DNS funcionan igual pero no se cuentan en el panel y el
     * administrador ve un "0 en uso" que no es verdad.
     *
     * @return int cuantos se han enganchado
     */
    public static function vincularProxysSueltos(?self $soloEste = null): int
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('server_proxy', 'domain_id')) {
                return 0;
            }
        } catch (\Throwable) {
            return 0;
        }

        $dominios = $soloEste !== null ? collect([$soloEste]) : self::all();
        $enganchados = 0;

        foreach ($dominios as $dominio) {
            try {
                // Por base_domain, que es lo que si guardaba la version
                // anterior...
                $enganchados += ProxyRecord::query()
                    ->whereNull('domain_id')
                    ->where('base_domain', $dominio->domain)
                    ->update(['domain_id' => $dominio->id]);

                // ...y por el propio nombre del dominio, para los que ni
                // siquiera tienen base_domain guardado.
                $enganchados += ProxyRecord::query()
                    ->whereNull('domain_id')
                    ->where(function ($consulta) use ($dominio) {
                        $consulta->where('domain', $dominio->domain)
                            ->orWhere('domain', 'like', '%.' . $dominio->domain);
                    })
                    ->update(['domain_id' => $dominio->id, 'base_domain' => $dominio->domain]);
            } catch (\Throwable) {
                // Un dominio que falle no puede parar a los demas.
            }
        }

        return $enganchados;
    }
}
