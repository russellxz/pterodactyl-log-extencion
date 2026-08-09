<?php

namespace Pterodactyl\Extensions\ArixLog\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Pterodactyl\Extensions\ArixLog\Models\MailLog;
use Pterodactyl\Extensions\ArixLog\Support\Settings;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Guarda una copia de cada correo que sale del panel.
 *
 * Laravel emite MessageSending justo antes de entregar el mensaje al
 * transporte y MessageSent cuando el transporte lo acepta. Si el transporte
 * revienta (SMTP caido, credenciales malas...) no llega ningun evento y la
 * excepcion sube al que llamo, por eso la fila se crea como "queued" y solo
 * pasa a "sent" cuando hay confirmacion. Las que se quedan en "queued" las
 * marca como fallidas el comando arixlog:prune.
 */
class MailLogListener
{
    /**
     * Relaciona el objeto del mensaje con la fila creada, para no tener que
     * meterle cabeceras raras al correo.
     *
     * @var array<int, int>
     */
    private static array $pending = [];

    public function sending(MessageSending $event): void
    {
        $this->guard(function () use ($event) {
            $log = $this->store($event->message, MailLog::STATUS_QUEUED);

            if ($log) {
                self::$pending[spl_object_id($event->message)] = $log->id;
            }
        });
    }

    public function sent(MessageSent $event): void
    {
        $this->guard(function () use ($event) {
            $message = $event->sent->getOriginalMessage();

            if (!$message instanceof Email) {
                return;
            }

            $key = spl_object_id($message);
            $id = self::$pending[$key] ?? null;
            unset(self::$pending[$key]);

            $messageId = $this->header($message, 'Message-ID');

            if ($id !== null) {
                MailLog::query()->whereKey($id)->update([
                    'status' => MailLog::STATUS_SENT,
                    'sent_at' => now(),
                    'message_id' => $messageId,
                    'updated_at' => now(),
                ]);

                return;
            }

            // No vimos el evento previo (por ejemplo si la extension se
            // instalo entre medias): se guarda igualmente como enviado.
            $log = $this->store($message, MailLog::STATUS_SENT);

            $log?->forceFill(['sent_at' => now(), 'message_id' => $messageId])->save();
        });
    }

    /**
     * Laravel 11 y 12 no emiten este evento, pero si alguna version futura o
     * un paquete de terceros lo emite, aqui queda recogido.
     */
    public function failed(object $event): void
    {
        $this->guard(function () use ($event) {
            $message = $event->message ?? null;

            if (!$message instanceof Email) {
                return;
            }

            $key = spl_object_id($message);
            $id = self::$pending[$key] ?? null;
            unset(self::$pending[$key]);

            $error = isset($event->exception) && $event->exception instanceof \Throwable
                ? mb_substr($event->exception->getMessage(), 0, 1000)
                : 'El transporte de correo rechazo el mensaje.';

            if ($id !== null) {
                MailLog::query()->whereKey($id)->update([
                    'status' => MailLog::STATUS_FAILED,
                    'error' => $error,
                    'updated_at' => now(),
                ]);

                return;
            }

            $this->store($message, MailLog::STATUS_FAILED)?->forceFill(['error' => $error])->save();
        });
    }

    private function store(Email $message, string $status): ?MailLog
    {
        $settings = Settings::make();

        if (!$settings->bool('mail_log_enabled')) {
            return null;
        }

        $storeBody = $settings->bool('mail_log_store_body');
        $to = $this->addresses($message->getTo());
        $recipient = $this->resolveRecipient($to);

        return MailLog::create([
            'mailer' => $this->header($message, 'X-Mailer') ?: config('mail.default'),
            'to_addresses' => $to,
            'cc_addresses' => $this->addresses($message->getCc()) ?: null,
            'bcc_addresses' => $this->addresses($message->getBcc()) ?: null,
            'from_address' => $this->addresses($message->getFrom()) ?: null,
            'subject' => mb_substr((string) $message->getSubject(), 0, 250),
            'body_html' => $storeBody ? $this->redact($this->body($message->getHtmlBody())) : null,
            'body_text' => $storeBody ? $this->redact($this->body($message->getTextBody())) : null,
            'user_id' => $recipient['id'],
            'user_name' => $recipient['name'],
            'status' => $status,
        ]);
    }

    /**
     * @param array<int, Address> $addresses
     */
    private function addresses(array $addresses): string
    {
        return implode(', ', array_map(fn (Address $a) => $a->getAddress(), $addresses));
    }

    private function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $contents = stream_get_contents($body);

            return $contents === false ? null : $contents;
        }

        return is_string($body) ? $body : null;
    }

    /**
     * Quita de la copia guardada los enlaces con credenciales de un solo uso.
     *
     * Los correos de restablecer contrasena del panel llevan un token en la
     * URL: guardarlo tal cual en la base de datos y ensenarlo en una pantalla
     * seria regalar una via de entrada a las cuentas. El correo que recibe el
     * cliente no se toca, solo la copia del registro.
     */
    private function redact(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return $body;
        }

        $patterns = [
            // /auth/password/reset/<token>
            '#(/auth/password/reset/)[A-Za-z0-9]{20,}#i' => '$1[token-oculto]',
            // ?token=... o &signature=... y similares
            '#([?&](?:token|signature|code|secret|key)=)[^"\'\s&<]{8,}#i' => '$1[oculto]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $body);
    }

    /**
     * Busca al cliente por su correo para poder mostrar el nombre junto al
     * envio, que es lo que hace util el listado.
     *
     * @return array{id: ?int, name: ?string}
     */
    private function resolveRecipient(string $to): array
    {
        $first = trim(explode(',', $to)[0] ?? '');

        if ($first === '') {
            return ['id' => null, 'name' => null];
        }

        try {
            $user = \Pterodactyl\Models\User::query()
                ->where('email', $first)
                ->first(['id', 'username', 'name_first', 'name_last']);

            if (!$user) {
                return ['id' => null, 'name' => null];
            }

            $name = trim(($user->name_first ?? '') . ' ' . ($user->name_last ?? ''));

            return ['id' => $user->id, 'name' => $name !== '' ? $name : $user->username];
        } catch (\Throwable) {
            return ['id' => null, 'name' => null];
        }
    }

    private function header(Email $message, string $name): ?string
    {
        try {
            $header = $message->getHeaders()->get($name);

            return $header?->getBodyAsString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Un fallo registrando el correo jamas debe impedir que el correo salga.
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                logger()->debug('[ArixLog] no se pudo registrar un correo: ' . $e->getMessage());
            } catch (\Throwable) {
                // Nada mas que hacer.
            }
        }
    }
}
