<?php

namespace Pterodactyl\Extensions\LogsPterodactyl\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Extensions\LogsPterodactyl\Models\MailCampaign;
use Pterodactyl\Extensions\LogsPterodactyl\Services\BulkMailer;
use Pterodactyl\Extensions\LogsPterodactyl\Support\Settings;
use Pterodactyl\Http\Controllers\Controller;

/**
 * Redactar y enviar correos a los clientes desde el panel.
 */
class ComposeController extends Controller
{
    /** Extensiones de imagen admitidas para el logo. */
    private const LOGO_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(private BulkMailer $mailer, private Settings $settings)
    {
    }

    public function index()
    {
        return view('logspterodactyl::admin.mail-compose', [
            'totalUsers' => $this->mailer->totalUsers(),
            'logoUrl' => $this->settings->get('mail_logo_url') ?: null,
            'campaigns' => MailCampaign::query()->orderByDesc('id')->limit(10)->get(),
            'logEnabled' => $this->settings->bool('mail_log_enabled'),
        ]);
    }

    /**
     * Buscador de clientes del selector.
     */
    public function search(Request $request): JsonResponse
    {
        return new JsonResponse([
            'users' => $this->mailer->search((string) $request->query('q', '')),
        ]);
    }

    /**
     * Vista previa: exactamente el mismo HTML que se va a enviar.
     */
    public function preview(Request $request): Response
    {
        $html = $this->mailer->render(
            (string) $request->input('body', ''),
            $this->settings->get('mail_logo_url') ?: null,
            (object) [
                'name_first' => 'Nombre',
                'name_last' => 'Ejemplo',
                'username' => 'cliente',
                'email' => 'cliente@ejemplo.com',
            ]
        );

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            // El HTML lo escribe un administrador, pero aun asi se sirve sin
            // permitir scripts: una vista previa no tiene por que ejecutar nada.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src * data:; font-src data:;",
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * Sube (o quita) el logo de los correos.
     */
    public function logo(Request $request)
    {
        if ($request->boolean('remove')) {
            $this->deleteExistingLogo();
            $this->settings->set('mail_logo_url', '');

            return back()->with('logspterodactyl_success', 'Logo quitado. Los correos volveran a mostrar el nombre del panel.');
        }

        $request->validate([
            'logo' => 'required|file|max:2048',
        ], [
            'logo.max' => 'La imagen no puede pasar de 2 MB.',
        ]);

        $file = $request->file('logo');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (!in_array($extension, self::LOGO_TYPES, true)) {
            return back()->with('logspterodactyl_error', 'Formato no admitido. Usa JPG, PNG, GIF o WEBP.');
        }

        // Se comprueba que el archivo es una imagen de verdad y no algo con la
        // extension cambiada: acaba en una carpeta publica del panel.
        $info = @getimagesize($file->getPathname());

        if ($info === false) {
            return back()->with('logspterodactyl_error', 'Ese archivo no es una imagen valida.');
        }

        $directory = public_path('extensions/logspterodactyl/logos');

        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            return back()->with('logspterodactyl_error', 'No se pudo crear la carpeta de logos. Ejecuta: sudo bash permissions.sh');
        }

        if (!is_writable($directory)) {
            return back()->with(
                'logspterodactyl_error',
                'No hay permisos de escritura en ' . $directory . '. Ejecuta: sudo bash permissions.sh'
            );
        }

        $this->deleteExistingLogo();

        // Nombre aleatorio: evita que el navegador se quede con el logo viejo
        // en cache y evita choques con lo que hubiera antes.
        $name = 'logo-' . bin2hex(random_bytes(6)) . '.' . $extension;

        try {
            $file->move($directory, $name);
        } catch (\Throwable $e) {
            return back()->with('logspterodactyl_error', 'No se pudo guardar la imagen: ' . $e->getMessage());
        }

        $url = rtrim((string) config('app.url'), '/') . '/extensions/logspterodactyl/logos/' . $name;
        $this->settings->set('mail_logo_url', $url);

        return back()->with('logspterodactyl_success', 'Logo actualizado. Aparecera en la cabecera de los correos.');
    }

    /**
     * Envia la campana.
     */
    public function send(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:200000',
            'audience' => 'required|in:all,one,selected',
            'email' => 'nullable|email',
            'users' => 'nullable|array',
            'users.*' => 'integer',
            'confirm' => 'required|accepted',
        ]);

        $recipients = $this->mailer->recipients(
            $data['audience'],
            array_map('intval', $data['users'] ?? []),
            $data['email'] ?? null
        );

        if ($recipients->isEmpty()) {
            return back()->withInput()->with(
                'logspterodactyl_error',
                'No hay ningun destinatario. Elige a quien se lo mandas.'
            );
        }

        $campaign = MailCampaign::create([
            'subject' => $data['subject'],
            'body_html' => $data['body'],
            'logo_url' => $this->settings->get('mail_logo_url') ?: null,
            'audience' => $data['audience'],
            'total' => $recipients->count(),
            'status' => 'sending',
            'created_by' => $request->user()->id ?? null,
            'created_by_name' => $request->user()->email ?? null,
        ]);

        // El envio va en la misma peticion. Con un servidor SMTP normal son
        // unos 2-4 correos por segundo, asi que se sube el limite de tiempo
        // para que no se corte a mitad en listas grandes.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $result = $this->mailer->send($campaign, $recipients);

        $mensaje = sprintf('Envio terminado: %d correo(s) enviados', $result['sent']);

        if ($result['failed'] > 0) {
            $mensaje .= sprintf(', %d fallidos', $result['failed']);

            if ($result['errors'] !== []) {
                $mensaje .= '. Primeros errores: ' . implode(' | ', $result['errors']);
            }
        }

        return redirect()
            ->route('admin.logspterodactyl.mails')
            ->with($result['failed'] > 0 ? 'logspterodactyl_error' : 'logspterodactyl_success', $mensaje);
    }

    /**
     * Borra el logo anterior del disco para no dejar imagenes sueltas.
     */
    private function deleteExistingLogo(): void
    {
        $actual = (string) $this->settings->get('mail_logo_url');

        if ($actual === '') {
            return;
        }

        $name = basename(parse_url($actual, PHP_URL_PATH) ?: '');

        if ($name === '' || !preg_match('/^logo-[a-f0-9]+\.[a-z0-9]+$/i', $name)) {
            return;
        }

        $path = public_path('extensions/logspterodactyl/logos/' . $name);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
