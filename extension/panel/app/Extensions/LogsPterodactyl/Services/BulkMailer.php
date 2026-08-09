<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Pterodactyl\Extensions\LogsPterodactyl\Listeners\MailLogListener;
use Pterodactyl\Extensions\LogsPterodactyl\Models\ExtensionEvent;
use Pterodactyl\Extensions\LogsPterodactyl\Models\MailCampaign;
use Pterodactyl\Extensions\LogsPterodactyl\Models\MailLog;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Models\User;

/**
 * Envio de correos a los clientes desde el panel.
 *
 * El cuerpo lo escribe el administrador en HTML y se envuelve en una plantilla
 * sencilla con el logo que haya subido. Se envia de uno en uno (no con todos
 * los destinatarios en el mismo mensaje) para que nadie vea la direccion de
 * los demas, que ademas es lo que hace que los filtros antispam no lo tumben.
 */
class BulkMailer
{
    /** Cuantos correos se mandan de una tacada antes de respirar. */
    private const CHUNK = 25;

    /**
     * Destinatarios segun el modo elegido.
     *
     * @param string             $audience all | one | selected
     * @param array<int, int>    $userIds  ids cuando el modo es "selected"
     * @param string|null        $email    direccion suelta cuando el modo es "one"
     *
     * @return Collection<int, object>
     */
    public function recipients(string $audience, array $userIds = [], ?string $email = null): Collection
    {
        $columns = ['id', 'username', 'email', 'name_first', 'name_last'];

        if ($audience === 'all') {
            return User::query()->orderBy('id')->get($columns);
        }

        if ($audience === 'one') {
            $direccion = trim((string) $email);

            if (!filter_var($direccion, FILTER_VALIDATE_EMAIL)) {
                return collect();
            }

            $usuario = User::query()->where('email', $direccion)->first($columns);

            // Se permite escribir una direccion que no sea de ningun cliente
            // (por ejemplo para probar antes de mandarlo a todos).
            return collect([$usuario ?: (object) [
                'id' => null,
                'username' => null,
                'email' => $direccion,
                'name_first' => null,
                'name_last' => null,
            ]]);
        }

        if ($userIds === []) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->orderBy('id')->get($columns);
    }

    /**
     * Busca clientes por nombre o correo, para el selector de la pantalla.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $limit = 40): array
    {
        $term = trim($term);

        return User::query()
            ->when($term !== '', function ($q) use ($term) {
                $like = '%' . $term . '%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('email', 'like', $like)
                        ->orWhere('username', 'like', $like)
                        ->orWhere('name_first', 'like', $like)
                        ->orWhere('name_last', 'like', $like);
                });
            })
            ->orderBy('username')
            ->limit(max(1, min(100, $limit)))
            ->get(['id', 'username', 'email', 'name_first', 'name_last'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'email' => $u->email,
                'name' => trim(($u->name_first ?? '') . ' ' . ($u->name_last ?? '')) ?: $u->username,
            ])
            ->all();
    }

    public function totalUsers(): int
    {
        return (int) User::query()->count();
    }

    /**
     * Envuelve el HTML del administrador en la plantilla de correo.
     *
     * Se hace aqui y no en la vista para poder ensenar exactamente lo mismo en
     * la vista previa que lo que se va a enviar.
     */
    public function render(string $bodyHtml, ?string $logoUrl, ?object $recipient = null): string
    {
        $cuerpo = $this->replacePlaceholders($bodyHtml, $logoUrl, $recipient);
        $settings = Settings::make();
        $modo = (string) $settings->get('mail_template_mode');

        // "raw": se envia el HTML del administrador tal cual, sin marco. Para
        // quien trae su propia maquetacion hecha y no quiere nada por encima.
        if ($modo === 'raw') {
            return $cuerpo;
        }

        // "custom": el marco lo escribe el administrador y lleva {{contenido}}
        // donde va el mensaje.
        if ($modo === 'custom') {
            $plantilla = (string) $settings->get('mail_template_html');

            if (trim($plantilla) !== '' && str_contains($plantilla, '{{contenido}}')) {
                return str_replace(
                    '{{contenido}}',
                    $cuerpo,
                    $this->replacePlaceholders($plantilla, $logoUrl, $recipient)
                );
            }
            // Si la plantilla esta vacia o le falta {{contenido}} se cae al
            // marco de serie en vez de mandar un correo roto.
        }

        return view('logspterodactyl::mail.bulk', [
            'cuerpo' => $cuerpo,
            'logoUrl' => $logoUrl,
            'appName' => config('app.name', 'Pterodactyl'),
        ])->render();
    }

    /**
     * Sustituye los marcadores en un texto.
     */
    private function replacePlaceholders(string $html, ?string $logoUrl, ?object $recipient): string
    {
        $nombre = '';

        if ($recipient) {
            $nombre = trim(($recipient->name_first ?? '') . ' ' . ($recipient->name_last ?? ''))
                ?: (string) ($recipient->username ?? '');
        }

        return strtr($html, [
            '{{nombre}}' => e($nombre),
            '{{correo}}' => e((string) ($recipient->email ?? '')),
            '{{panel}}' => e((string) config('app.name', 'Pterodactyl')),
            '{{logo}}' => $logoUrl ? e($logoUrl) : '',
            '{{url}}' => e(rtrim((string) config('app.url'), '/')),
        ]);
    }

    /**
     * El marco de serie, para poder ensenarlo como punto de partida cuando el
     * administrador quiera escribir el suyo.
     */
    public function defaultTemplate(): string
    {
        return view('logspterodactyl::mail.bulk', [
            'cuerpo' => '{{contenido}}',
            'logoUrl' => '{{logo}}',
            'appName' => (string) config('app.name', 'Pterodactyl'),
        ])->render();
    }

    /**
     * Manda la campana. Devuelve cuantos salieron y cuantos fallaron.
     *
     * @param Collection<int, object> $recipients
     *
     * @return array{sent: int, failed: int, errors: array<int, string>}
     */
    public function send(MailCampaign $campaign, Collection $recipients): array
    {
        $enviados = 0;
        $fallidos = 0;
        $errores = [];

        $campaign->forceFill(['status' => 'sending', 'total' => $recipients->count()])->save();

        // A partir de aqui, cada correo que salga queda etiquetado con esta
        // campana por el propio registro de correos.
        MailLogListener::withCampaign($campaign->id);

        // El try/finally es importante: si algo revienta a mitad, la etiqueta
        // de campana tiene que quitarse igualmente o los correos normales del
        // panel que salgan despues quedarian marcados como parte del envio.
        try {
            foreach ($recipients->chunk(self::CHUNK) as $lote) {
                foreach ($lote as $destinatario) {
                    $direccion = (string) ($destinatario->email ?? '');

                    if (!filter_var($direccion, FILTER_VALIDATE_EMAIL)) {
                        $fallidos++;

                        continue;
                    }

                    $html = $this->render((string) $campaign->body_html, $campaign->logo_url, $destinatario);

                    try {
                        Mail::send([], [], function ($message) use ($direccion, $campaign, $html) {
                            $message->to($direccion)->subject((string) $campaign->subject);
                            $message->getSymfonyMessage()->html($html);
                        });

                        $enviados++;
                    } catch (\Throwable $e) {
                        $fallidos++;

                        if (count($errores) < 5) {
                            $errores[] = $direccion . ': ' . mb_substr($e->getMessage(), 0, 160);
                        }

                        $this->logFailure($campaign, $destinatario, $direccion, $e);
                    }
                }

                $campaign->forceFill(['sent' => $enviados, 'failed' => $fallidos])->save();
            }
        } finally {
            MailLogListener::withCampaign(null);
        }

        $campaign->forceFill([
            'sent' => $enviados,
            'failed' => $fallidos,
            'status' => 'done',
            'finished_at' => now(),
        ])->save();

        ExtensionEvent::log($fallidos > 0 ? 'warning' : 'info', 'mails', sprintf(
            'Envio "%s": %d enviados, %d fallidos',
            mb_substr((string) $campaign->subject, 0, 80),
            $enviados,
            $fallidos
        ), ['campana' => $campaign->id, 'errores' => $errores]);

        return ['sent' => $enviados, 'failed' => $fallidos, 'errors' => $errores];
    }

    private function logFailure(MailCampaign $campaign, object $destinatario, string $direccion, \Throwable $e): void
    {
        try {
            MailLog::create([
                'mailer' => config('mail.default'),
                'to_addresses' => $direccion,
                'subject' => $campaign->subject,
                'user_id' => $destinatario->id ?? null,
                'user_name' => trim(($destinatario->name_first ?? '') . ' ' . ($destinatario->name_last ?? ''))
                    ?: ($destinatario->username ?? null),
                'campaign_id' => $campaign->id,
                'status' => MailLog::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
        } catch (\Throwable) {
            // Nada que hacer.
        }
    }
}
