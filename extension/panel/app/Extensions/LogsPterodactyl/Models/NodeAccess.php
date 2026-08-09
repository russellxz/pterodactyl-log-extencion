<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Datos de acceso SSH a un nodo.
 *
 * El secreto (contrasena o clave privada) se cifra con la APP_KEY del panel
 * mediante el cast "encrypted", asi que en la base de datos nunca hay nada
 * legible. A la pantalla no se devuelve nunca: los campos son de solo
 * escritura y solo se dice si hay algo guardado o no.
 */
class NodeAccess extends Model
{
    public const AUTH_PASSWORD = 'password';
    public const AUTH_KEY = 'key';

    protected $table = 'logspterodactyl_node_access';

    protected $guarded = ['id'];

    protected $casts = [
        'node_id' => 'integer',
        'port' => 'integer',
        'enabled' => 'boolean',
        'auto_fix' => 'boolean',
        'secret' => 'encrypted',
        'passphrase' => 'encrypted',
        'last_ok_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    /**
     * Los atributos que nunca deben acabar en un json, un log ni un dd().
     */
    protected $hidden = ['secret', 'passphrase'];

    public function usesKey(): bool
    {
        return $this->auth_type === self::AUTH_KEY;
    }

    /**
     * El secreto descifrado. Se aisla en un metodo para que sea facil de
     * auditar quien lo pide.
     */
    public function plainSecret(): string
    {
        return (string) $this->secret;
    }

    public function plainPassphrase(): ?string
    {
        $valor = (string) $this->passphrase;

        return $valor === '' ? null : $valor;
    }

    /**
     * Olvida la huella guardada de la maquina.
     *
     * Es lo que hay que hacer cuando se reinstala la VPS: la maquina nueva
     * tiene otra clave de servidor y, con la vieja guardada, cualquier intento
     * de conexion se rechaza (que es justo lo que pasa con el known_hosts de
     * toda la vida y el aviso ese de "REMOTE HOST IDENTIFICATION HAS CHANGED").
     */
    public function forgetFingerprint(): void
    {
        $this->forceFill(['fingerprint' => null])->save();

        ExtensionEvent::log('info', 'nodos', 'Se olvido la huella SSH del nodo', [
            'host' => $this->host,
            'motivo' => 'la maquina se reinstalo o cambio su clave de servidor',
        ]);
    }

    /**
     * Se usa para no guardar nunca un secreto vacio por error.
     */
    public static function cifrar(string $valor): string
    {
        return Crypt::encryptString($valor);
    }
}
