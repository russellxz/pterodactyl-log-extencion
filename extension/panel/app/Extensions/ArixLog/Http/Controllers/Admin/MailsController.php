<?php

namespace Pterodactyl\Extensions\ArixLog\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Pterodactyl\Extensions\ArixLog\Models\ExtensionEvent;
use Pterodactyl\Extensions\ArixLog\Models\MailLog;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Correos enviados por el panel: a quien, cuando y si salieron o no.
 */
class MailsController extends Controller
{
    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));

        $mails = MailLog::query()
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . $search . '%';
                $q->where(function ($inner) use ($like) {
                    $inner->where('to_addresses', 'like', $like)
                        ->orWhere('subject', 'like', $like)
                        ->orWhere('user_name', 'like', $like);
                });
            })
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('arixlog::admin.mails', [
            'mails' => $mails,
            'status' => $status,
            'search' => $search,
            'summary' => [
                'sent' => (int) MailLog::query()->where('status', MailLog::STATUS_SENT)->count(),
                'failed' => (int) MailLog::query()->where('status', MailLog::STATUS_FAILED)->count(),
                'queued' => (int) MailLog::query()->where('status', MailLog::STATUS_QUEUED)->count(),
            ],
        ]);
    }

    public function show(int $mail)
    {
        $log = MailLog::query()->findOrFail($mail);

        return view('arixlog::admin.mail-detail', ['mail' => $log]);
    }

    /**
     * Vista previa del cuerpo del correo dentro de un iframe aislado.
     *
     * Se sirve con una politica de seguridad muy cerrada porque el contenido
     * es HTML que en su dia genero el panel: no se le deja ejecutar scripts ni
     * pedir nada a internet.
     */
    public function preview(int $mail): Response
    {
        $log = MailLog::query()->findOrFail($mail);

        $body = $log->body_html ?: nl2br(e((string) $log->body_text));

        if (trim((string) $body) === '') {
            $body = '<p style="font-family:sans-serif;color:#888;">Este correo se guardo sin el cuerpo del mensaje.</p>';
        }

        return response($body, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:;",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * Reenvia un correo que fallo (o que se quiere volver a mandar).
     */
    public function resend(Request $request, int $mail)
    {
        $log = MailLog::query()->findOrFail($mail);

        $to = $request->filled('to')
            ? array_map('trim', explode(',', (string) $request->input('to')))
            : $log->recipients();

        $to = array_values(array_filter($to, fn ($address) => filter_var($address, FILTER_VALIDATE_EMAIL)));

        if ($to === []) {
            return back()->with('arixlog_error', 'No hay ninguna direccion de correo valida a la que enviar.');
        }

        if (($log->body_html === null || $log->body_html === '') && ($log->body_text === null || $log->body_text === '')) {
            return back()->with('arixlog_error', 'Este correo se guardo sin cuerpo, no se puede reenviar. Activa "guardar el cuerpo" en la configuracion para que los proximos si se puedan.');
        }

        try {
            $html = $log->body_html;
            $text = $log->body_text;
            $subject = (string) $log->subject;

            // Se pasa una vista vacia y se rellena el cuerpo directamente
            // sobre el mensaje de Symfony: el correo original ya venia
            // renderizado, no hay ninguna plantilla que volver a pintar.
            Mail::send([], [], function ($message) use ($to, $subject, $html, $text) {
                $message->to($to)->subject($subject);

                $symfony = $message->getSymfonyMessage();

                if ($html !== null && $html !== '') {
                    $symfony->html($html);
                }

                if ($text !== null && $text !== '') {
                    $symfony->text($text);
                }
            });
        } catch (\Throwable $e) {
            $log->forceFill([
                'status' => MailLog::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            ExtensionEvent::log('error', 'mails', 'Fallo al reenviar un correo', [
                'destinatarios' => implode(', ', $to),
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return back()->with('arixlog_error', 'No se pudo reenviar: ' . $e->getMessage());
        }

        $log->forceFill([
            'status' => MailLog::STATUS_SENT,
            'error' => null,
            'resend_count' => (int) $log->resend_count + 1,
            'last_resent_at' => now(),
        ])->save();

        ExtensionEvent::log('info', 'mails', 'Correo reenviado', [
            'destinatarios' => implode(', ', $to),
            'asunto' => $log->subject,
        ]);

        return back()->with('arixlog_success', 'Correo reenviado a ' . implode(', ', $to) . '.');
    }

    /**
     * Envia un correo de prueba para comprobar la configuracion SMTP.
     */
    public function test(Request $request)
    {
        $to = trim((string) $request->input('to'));

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->with('arixlog_error', 'Escribe una direccion de correo valida.');
        }

        try {
            Mail::send('arixlog::mail.test', [
                'appName' => config('app.name', 'Pterodactyl'),
                'when' => now()->toDateTimeString(),
            ], function ($message) use ($to) {
                $message->to($to)->subject('Prueba de envio - ' . config('app.name', 'Pterodactyl'));
            });
        } catch (\Throwable $e) {
            ExtensionEvent::log('error', 'mails', 'Fallo el correo de prueba', [
                'destinatario' => $to,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            return back()->with('arixlog_error', 'No se pudo enviar: ' . $e->getMessage());
        }

        return back()->with('arixlog_success', 'Correo de prueba enviado a ' . $to . '.');
    }
}
