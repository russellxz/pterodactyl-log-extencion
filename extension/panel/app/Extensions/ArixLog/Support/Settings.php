<?php

namespace Pterodactyl\Extensions\ArixLog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Almacen de configuracion de la extension.
 *
 * Se guarda en su propia tabla en lugar de en la tabla `settings` del panel
 * para que desinstalar la extension sea limpio: se borra la tabla y no queda
 * basura en la configuracion del panel.
 */
class Settings
{
    public const TABLE = 'arixlog_settings';

    public const DEFAULTS = [
        // --- Vigilante de instalaciones atascadas ---
        'watchdog_enabled' => '0',
        'watchdog_minutes' => '10',
        // fail            -> solo marca la instalacion como fallida
        // fail_rotate     -> marca fallida y cambia el puerto (recomendado)
        // force_rotate    -> ademas borra el servidor en wings para matar de
        //                    verdad el contenedor de instalacion colgado
        'watchdog_action' => 'fail_rotate',
        'watchdog_notify_owner' => '1',
        'watchdog_same_ip' => '1',

        // --- Boton de cancelar del area de cliente ---
        'client_cancel_enabled' => '1',
        'client_cancel_minutes' => '10',
        'client_cancel_rotate_port' => '0',

        // --- Registro de correos ---
        'mail_log_enabled' => '1',
        'mail_log_store_body' => '1',
        'mail_log_retention_days' => '60',

        // --- Monitor de recursos ---
        'resources_enabled' => '1',
        'resources_retention_days' => '14',
        'resources_alert_cpu' => '90',
        'resources_alert_memory' => '90',

        // --- Registros propios ---
        'events_retention_days' => '90',
        'installs_retention_days' => '365',

        // --- Actualizador del panel ---
        'backup_path' => '/var/backups/arixlog',
        'update_keep_backups' => '3',
    ];

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = [];

        try {
            if (Schema::hasTable(self::TABLE)) {
                $stored = DB::table(self::TABLE)->pluck('value', 'key')->all();
            }
        } catch (\Throwable) {
            $stored = [];
        }

        return $this->cache = array_merge(self::DEFAULTS, $stored);
    }

    /**
     * Solo lo que hay realmente guardado en la tabla, sin mezclar los valores
     * por defecto. Sirve para saber que se ha configurado de verdad.
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

    public static function make(): self
    {
        return app(self::class);
    }
}
