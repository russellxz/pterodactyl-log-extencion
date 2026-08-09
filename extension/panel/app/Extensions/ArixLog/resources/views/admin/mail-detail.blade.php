@extends('arixlog::admin._layout')

@section('arixlog-title', 'Correo')
@section('arixlog-heading', 'Detalle del correo')
@section('arixlog-subheading', $mail->subject ?: '(sin asunto)')

@section('arixlog-content')
    <div class="row">
        <div class="col-md-4">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Datos del envio</h3>
                </div>
                <div class="box-body no-padding">
                    <table class="table arixlog-table">
                        <tbody>
                            <tr><td>Estado</td><td class="text-right">
                                @php
                                    $tone = match($mail->status) { 'sent' => 'ok', 'failed' => 'danger', default => 'warning' };
                                    $label = match($mail->status) { 'sent' => 'Enviado', 'failed' => 'Fallido', default => 'Sin confirmar' };
                                @endphp
                                <span class="arixlog-state arixlog-state-{{ $tone }}">{{ $label }}</span>
                            </td></tr>
                            <tr><td>Para</td><td class="text-right">{{ $mail->to_addresses }}</td></tr>
                            @if($mail->cc_addresses)
                                <tr><td>Copia</td><td class="text-right">{{ $mail->cc_addresses }}</td></tr>
                            @endif
                            <tr><td>Cliente</td><td class="text-right">{{ $mail->user_name ?: 'no identificado' }}</td></tr>
                            <tr><td>Remitente</td><td class="text-right">{{ $mail->from_address ?: '-' }}</td></tr>
                            <tr><td>Creado</td><td class="text-right">{{ optional($mail->created_at)->format('d/m/Y H:i:s') }}</td></tr>
                            <tr><td>Confirmado</td><td class="text-right">{{ optional($mail->sent_at)->format('d/m/Y H:i:s') ?: '-' }}</td></tr>
                            <tr><td>Reenvios</td><td class="text-right">{{ $mail->resend_count }}</td></tr>
                            @if($mail->last_resent_at)
                                <tr><td>Ultimo reenvio</td><td class="text-right">{{ $mail->last_resent_at->format('d/m/Y H:i') }}</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            @if($mail->error)
                <div class="box box-danger">
                    <div class="box-header with-border"><h3 class="box-title">Error devuelto</h3></div>
                    <div class="box-body"><pre class="arixlog-stack">{{ $mail->error }}</pre></div>
                </div>
            @endif

            <div class="box">
                <div class="box-header with-border"><h3 class="box-title">Reenviar</h3></div>
                <form method="POST" action="{{ route('admin.arixlog.mails.resend', $mail->id) }}">
                    {{ csrf_field() }}
                    <div class="box-body">
                        <div class="form-group">
                            <label for="to">Destinatarios</label>
                            <input type="text" name="to" id="to" class="form-control"
                                   value="{{ $mail->to_addresses }}">
                            <p class="text-muted arixlog-small" style="margin-top:6px;">
                                Separa varias direcciones con comas. Se enviara el mismo contenido guardado.
                            </p>
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">@arixicon('send', 14) Reenviar</button>
                        <a href="{{ route('admin.arixlog.mails') }}" class="btn btn-default">Volver</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-8">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Contenido</h3>
                </div>
                <div class="box-body no-padding">
                    @if($mail->body_html || $mail->body_text)
                        <iframe src="{{ route('admin.arixlog.mails.preview', $mail->id) }}"
                                class="arixlog-mail-preview" sandbox="" title="Vista previa del correo"></iframe>
                    @else
                        <p class="arixlog-empty">
                            Este correo se guardo sin el cuerpo del mensaje. Activa "guardar el cuerpo"
                            en la configuracion para que los proximos se puedan ver y reenviar.
                        </p>
                    @endif
                </div>
                <div class="box-footer arixlog-small arixlog-muted">
                    Los enlaces con credenciales de un solo uso (como los de restablecer contrasena)
                    se guardan censurados a proposito.
                </div>
            </div>
        </div>
    </div>
@endsection
