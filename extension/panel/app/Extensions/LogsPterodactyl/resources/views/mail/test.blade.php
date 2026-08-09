<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Prueba de envio</title></head>
<body style="margin:0;padding:24px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2430;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center">
        <table role="presentation" cellpadding="0" cellspacing="0" style="max-width:520px;width:100%;background:#fff;border:1px solid #e3e6ec;border-radius:10px;">
            <tr><td style="padding:24px 28px;">
                <div style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#8a90a0;">{{ $appName }}</div>
                <h1 style="font-size:19px;margin:6px 0 14px;">La configuracion de correo funciona</h1>
                <p style="margin:0 0 12px;font-size:15px;line-height:1.6;">
                    Si estas leyendo esto, el panel puede enviar correos correctamente.
                </p>
                <p style="margin:0;font-size:13px;color:#6b7280;">
                    Enviado el {{ $when }} desde la extension LogsPterodactyl.
                </p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
