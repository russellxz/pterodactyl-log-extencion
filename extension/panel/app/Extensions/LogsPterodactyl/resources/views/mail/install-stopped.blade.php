<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalacion detenida</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2430;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;border:1px solid #e3e6ec;overflow:hidden;">
                <tr>
                    <td style="padding:20px 28px;border-bottom:1px solid #eef0f4;">
                        <span style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#8a90a0;">{{ $appName }}</span>
                        <div style="font-size:19px;font-weight:600;margin-top:4px;">Hemos detenido la instalacion de tu servidor</div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px 28px;font-size:15px;line-height:1.6;">
                        <p style="margin:0 0 16px;">Hola {{ $user->name_first ?: $user->username }},</p>

                        <p style="margin:0 0 16px;">
                            El servidor <strong>{{ $server->name }}</strong> llevaba
                            <strong>{{ $minutes }} minutos</strong> instalando sin terminar, asi que
                            nuestro sistema ha detenido el proceso automaticamente. Una instalacion
                            normal tarda unos pocos minutos.
                        </p>

                        <p style="margin:0 0 16px;">
                            Tu servidor <strong>sigue donde estaba, no se ha borrado nada</strong>, y ya
                            esta desbloqueado: puedes entrar en el y revisar toda su configuracion.
                        </p>

                        @if($rotation)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f8fa;border:1px solid #e3e6ec;border-radius:8px;margin:0 0 16px;">
                                <tr>
                                    <td style="padding:14px 16px;font-size:14px;">
                                        <div style="color:#8a90a0;text-transform:uppercase;font-size:11px;letter-spacing:.08em;margin-bottom:6px;">Puerto reasignado</div>
                                        <div><span style="color:#8a90a0;">Antes:</span> <code style="font-family:ui-monospace,Menlo,Consolas,monospace;">{{ $rotation['old'] ?? 'sin asignacion' }}</code></div>
                                        <div style="margin-top:2px;"><span style="color:#8a90a0;">Ahora:</span> <code style="font-family:ui-monospace,Menlo,Consolas,monospace;color:#1f7a45;font-weight:600;">{{ $rotation['new'] }}</code></div>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        <p style="margin:0 0 10px;font-weight:600;">Antes de volver a instalar, comprueba lo siguiente:</p>
                        <ul style="margin:0 0 18px;padding-left:20px;">
                            <li style="margin-bottom:6px;">Que el <strong>token</strong> o clave de acceso este bien escrito y no haya caducado.</li>
                            <li style="margin-bottom:6px;">Que el <strong>nombre de usuario</strong> sea correcto.</li>
                            <li style="margin-bottom:6px;">Que el <strong>repositorio</strong> no sea privado. Si lo es, hace falta un token con permiso de lectura.</li>
                            <li>Que la <strong>version</strong> o el enlace de descarga que has puesto exista.</li>
                        </ul>

                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
                            <tr>
                                <td style="background:#2f6fed;border-radius:7px;">
                                    <a href="{{ $panelUrl }}" style="display:inline-block;padding:11px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Ir a mi servidor</a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0;color:#6b7280;font-size:13px;">
                            Cuando hayas corregido los datos, entra en la pestana de arranque de tu
                            servidor, guarda los cambios y vuelve a lanzar la instalacion. Si vuelve
                            a fallar, responde a este correo y lo revisamos contigo.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:14px 28px;background:#fafbfc;border-top:1px solid #eef0f4;font-size:12px;color:#8a90a0;">
                        Este mensaje se ha enviado automaticamente desde {{ $appName }}.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
