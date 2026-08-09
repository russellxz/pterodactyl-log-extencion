<?php

namespace Pterodactyl\Extensions\DnsReverse\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configuracion de la extension.
 *
 * En su propia tabla, no en la tabla `settings` del panel, para que
 * desinstalar no deje basura en la configuracion del panel.
 */
class Settings
{
    public const TABLE = 'dnsreverse_settings';

    public const DEFAULTS = [
        // --- Limites ---
        // Cuantos DNS puede crear un servidor recien creado. La version
        // anterior venia con 0 (nadie podia crear nada hasta que el
        // administrador entraba a mano servidor por servidor).
        'default_proxy_limit' => '1',

        // --- Instrucciones que ve el cliente al traer su propio dominio ---
        'dns_instructions' => 'Crea un registro A en tu proveedor de DNS apuntando tu dominio o subdominio a la IP del nodo: [ip]. No uses un CNAME apuntando a una IP.',
        // ip | alias -> que se muestra en [ip]
        'dns_instruction_source' => 'ip',

        // --- Certificados automaticos (Let's Encrypt) ---
        'letsencrypt_enabled' => '1',
        // Dias antes de caducar en los que se renueva sola.
        'letsencrypt_renew_days' => '21',
        'letsencrypt_auto_renew' => '1',

        // --- Dominios propios del cliente ---
        'allow_custom_domains' => '1',

        // --- Seguridad ---
        // Dominios que nadie puede usar aunque los escriba tal cual. Se anade
        // ademas, siempre, el dominio del panel y el FQDN de cada nodo.
        'blocked_domains' => 'localhost,pterodactyl.io',

        // --- Wings ---
        // Version minima del complemento de wings que se espera en el nodo.
        // Si el nodo responde con menos (o no responde), el panel avisa.
        'wings_min_version' => '2',
    ];

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $guardado = [];

        try {
            if (Schema::hasTable(self::TABLE)) {
                $guardado = DB::table(self::TABLE)->pluck('value', 'key')->all();
            }
        } catch (\Throwable) {
            $guardado = [];
        }

        return $this->cache = array_merge(self::DEFAULTS, $guardado);
    }

    /**
     * Solo lo guardado de verdad, sin mezclar los valores por defecto.
     *
     * @return array<string, string>
     */
    public function stored(): array
    {
        try {
            if (!Schema::hasTable(self::TABLE)) {
                return [];
            }

            return DB::table(self::TABLE)->pluck('value', 'key')->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $min = 0, int $max = PHP_INT_MAX): int
    {
        return max($min, min($max, (int) $this->get($key)));
    }

    public function set(string $key, mixed $value): void
    {
        if (!Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)->updateOrInsert(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'updated_at' => now()]
        );

        $this->cache = null;
    }

    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            if (array_key_exists($key, self::DEFAULTS)) {
                $this->set($key, $value);
            }
        }
    }

    /**
     * Lista de dominios que nadie puede usar: los de la configuracion, el del
     * propio panel y el FQDN de todos los nodos. Sin esto un cliente podria
     * crear un DNS con el dominio del panel y dejarlo inaccesible.
     *
     * @return array<int, string>
     */
    public function blockedDomains(): array
    {
        $lista = array_filter(array_map('trim', explode(',', (string) $this->get('blocked_domains'))));

        $panel = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($panel) && $panel !== '') {
            $lista[] = $panel;
        }

        try {
            foreach (DB::table('nodes')->pluck('fqdn') as $fqdn) {
                if (is_string($fqdn) && $fqdn !== '') {
                    $lista[] = $fqdn;
                }
            }
        } catch (\Throwable) {
            // Sin nodos accesibles se sigue con lo que haya.
        }

        return array_values(array_unique(array_map('strtolower', $lista)));
    }

    public static function make(): self
    {
        return app(self::class);
    }
}
