<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $appName }}</title>
</head>
{{--
    Plantilla de los correos que se envian desde el panel.

    Va con tablas y estilos en linea a proposito: es lo unico que pintan igual
    Gmail, Outlook y el resto. Nada de flexbox, grid ni hojas de estilo aparte.
--}}
<body style="margin:0;padding:0;background:#f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f5f7;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:600px;background:#ffffff;border-radius:10px;border:1px solid #e3e6ec;overflow:hidden;">

                @if($logoUrl)
                    <tr>
                        <td align="center" style="padding:26px 28px 10px;">
                            <img src="{{ $logoUrl }}" alt="{{ $appName }}"
                                 style="max-width:190px;max-height:80px;height:auto;display:block;border:0;">
                        </td>
                    </tr>
                @else
                    <tr>
                        <td align="center" style="padding:24px 28px 8px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:19px;font-weight:600;color:#1f2430;">
                            {{ $appName }}
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:14px 30px 26px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.65;color:#1f2430;">
                        {!! $cuerpo !!}
                    </td>
                </tr>

                <tr>
                    <td style="padding:14px 30px;background:#fafbfc;border-top:1px solid #eef0f4;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;color:#8a90a0;">
                        Este mensaje se ha enviado desde {{ $appName }}.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
