@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Correos')
@section('logspterodactyl-heading', 'Correos enviados')
@section('logspterodactyl-subheading', 'a que cliente fue cada uno y si llego')

@section('logspterodactyl-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="logspterodactyl-stats">
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'check-circle', 'value' => $summary['sent'], 'label' => 'enviados', 'tone' => 'ok'])
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'x-circle', 'value' => $summary['failed'], 'label' => 'fallidos', 'tone' => $summary['failed'] ? 'danger' : ''])
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'clock', 'value' => $summary['queued'], 'label' => 'sin confirmar', 'tone' => $summary['queued'] ? 'warning' : ''])
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-8">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Historial de envios</h3>
                    <div class="box-tools">
                        <a href="{{ route('admin.logspterodactyl.compose') }}" class="btn btn-xs btn-primary">
                            @logsicon('send', 13) Redactar y enviar
                        </a>
                    </div>
                </div>
                <div class="box-body">
                    <form method="GET" class="logspterodactyl-filters" style="margin-bottom:12px;">
                        <div class="logspterodactyl-field">
                            <label for="status">Estado</label>
                            <select name="status" id="status" class="form-control" onchange="this.form.submit()">
                                <option value="">Todos</option>
                                <option value="sent" @if($status === 'sent') selected @endif>Enviados</option>
                                <option value="failed" @if($status === 'failed') selected @endif>Fallidos</option>
                                <option value="queued" @if($status === 'queued') selected @endif>Sin confirmar</option>
                            </select>
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-grow">
                            <label for="search">Buscar</label>
                            <input type="text" name="search" id="search" class="form-control"
                                   value="{{ $search }}" placeholder="correo, asunto o nombre del cliente">
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-actions">
                            <button type="submit" class="btn btn-primary">@logsicon('search', 14) Buscar</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover logspterodactyl-table">
                            <thead>
                                <tr>
                                    <th>Destinatario</th>
                                    <th>Asunto</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($mails as $mail)
                                <tr>
                                    <td>
                                        <strong>{{ $mail->user_name ?: '-' }}</strong>
                                        <div class="logspterodactyl-muted logspterodactyl-small">{{ $mail->to_addresses }}</div>
                                    </td>
                                    <td>
                                        {{ $mail->subject ?: '(sin asunto)' }}
                                        @if($mail->resend_count > 0)
                                            <span class="logspterodactyl-chip">reenviado {{ $mail->resend_count }}x</span>
                                        @endif
                                    </td>
                                    <td>
                                        @php
                                            $tone = match($mail->status) {
                                                'sent' => 'ok',
                                                'failed' => 'danger',
                                                default => 'warning',
                                            };
                                            $label = match($mail->status) {
                                                'sent' => 'Enviado',
                                                'failed' => 'Fallido',
                                                default => 'Sin confirmar',
                                            };
                                        @endphp
                                        <span class="logspterodactyl-state logspterodactyl-state-{{ $tone }}">{{ $label }}</span>
                                        @if($mail->error)
                                            <div class="logspterodactyl-muted logspterodactyl-small">{{ \Illuminate\Support\Str::limit($mail->error, 70) }}</div>
                                        @endif
                                    </td>
                                    <td class="logspterodactyl-small">{{ optional($mail->created_at)->format('d/m/Y H:i') }}</td>
                                    <td class="text-right">
                                        <a href="{{ route('admin.logspterodactyl.mails.show', $mail->id) }}" class="btn btn-xs btn-default">
                                            Ver
                                        </a>
                                        <form method="POST" action="{{ route('admin.logspterodactyl.mails.resend', $mail->id) }}"
                                              style="display:inline-block;"
                                              onsubmit="return confirm('Se volvera a enviar este correo a {{ $mail->to_addresses }}. ¿Continuar?');">
                                            {{ csrf_field() }}
                                            <button type="submit" class="btn btn-xs btn-primary" title="Reenviar">
                                                @logsicon('send', 12)
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <p class="logspterodactyl-empty">
                                            Todavia no se ha registrado ningun correo. El registro solo recoge
                                            los que salen a partir de la instalacion de la extension.
                                        </p>
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $mails->links() }}
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Probar el envio</h3>
                </div>
                <form method="POST" action="{{ route('admin.logspterodactyl.mails.test') }}">
                    {{ csrf_field() }}
                    <div class="box-body">
                        <p class="text-muted">
                            Manda un correo de prueba para comprobar que la configuracion SMTP del panel
                            funciona. Aparecera tambien en el historial.
                        </p>
                        <div class="form-group">
                            <label for="to">Direccion de destino</label>
                            <input type="email" name="to" id="to" class="form-control" required
                                   value="{{ auth()->user()->email }}">
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">@logsicon('send', 14) Enviar prueba</button>
                    </div>
                </form>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Sobre los estados</h3>
                </div>
                <div class="box-body logspterodactyl-small">
                    <p><strong>Enviado:</strong> el servidor de correo acepto el mensaje.</p>
                    <p><strong>Fallido:</strong> el envio dio error, o se quedo sin confirmar mas de 15 minutos.</p>
                    <p><strong>Sin confirmar:</strong> se acaba de mandar y todavia no hay respuesta.</p>
                    <p class="logspterodactyl-muted" style="margin-top:12px;">
                        Que el estado sea "enviado" significa que el servidor de correo lo acepto,
                        no que el cliente lo haya abierto ni que no haya caido en spam.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
