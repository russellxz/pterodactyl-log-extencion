@extends('arixlog::admin._layout')

@section('arixlog-title', 'Resumen')
@section('arixlog-heading', 'ArixLog')
@section('arixlog-subheading', 'estado del panel de un vistazo')

@section('arixlog-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="arixlog-stats">
                @include('arixlog::admin.partials.stat', [
                    'icon' => 'bug',
                    'value' => $logs['serious'],
                    'label' => 'errores graves en el log actual',
                    'hint' => $logs['latest'] ?: 'sin archivos de log',
                    'tone' => $logs['serious'] > 0 ? 'danger' : 'ok',
                ])

                @include('arixlog::admin.partials.stat', [
                    'icon' => 'server',
                    'value' => $installs['installing'],
                    'label' => 'instalaciones en curso',
                    'hint' => $installs['over_limit'] . ' por encima de ' . $installs['limit'] . ' min',
                    'tone' => $installs['over_limit'] > 0 ? 'warning' : 'ok',
                ])

                @include('arixlog::admin.partials.stat', [
                    'icon' => 'mail',
                    'value' => $mails['failed_24h'],
                    'label' => 'correos fallidos (24 h)',
                    'hint' => $mails['sent_24h'] . ' enviados correctamente',
                    'tone' => $mails['failed_24h'] > 0 ? 'danger' : 'ok',
                ])

                @include('arixlog::admin.partials.stat', [
                    'icon' => 'upload-cloud',
                    'value' => $panel['current'],
                    'label' => 'version del panel',
                    'hint' => $panel['available'] ? 'hay actualizacion: ' . $panel['latest'] : 'al dia',
                    'tone' => $panel['available'] ? 'warning' : 'ok',
                ])
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Sistema automatico de instalaciones</h3>
                </div>
                <div class="box-body">
                    @if($installs['enabled'])
                        <p class="arixlog-note arixlog-note-ok">
                            @arixicon('shield', 16)
                            <span>
                                Activo. Toda instalacion que pase de
                                <strong>{{ $installs['limit'] }} minutos</strong> se detiene automaticamente.
                            </span>
                        </p>
                    @else
                        <p class="arixlog-note arixlog-note-warning">
                            @arixicon('alert', 16)
                            <span>
                                Desactivado. Las instalaciones colgadas se quedaran colgadas hasta que alguien
                                las pare a mano. Se activa en
                                <a href="{{ route('admin.arixlog.settings') }}">Configuracion</a>.
                            </span>
                        </p>
                    @endif

                    <table class="table table-condensed arixlog-table">
                        <tbody>
                            <tr>
                                <td>Instalaciones registradas en las ultimas 24 h</td>
                                <td class="text-right"><strong>{{ $installs['last_24h'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>De ellas fallidas o detenidas por tiempo</td>
                                <td class="text-right"><strong>{{ $installs['failed_24h'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Aviso al cliente en el area de usuario</td>
                                <td class="text-right">
                                    <strong>
                                        {{ $settings->bool('client_cancel_enabled')
                                            ? 'activo a partir de ' . $settings->int('client_cancel_minutes') . ' min'
                                            : 'desactivado' }}
                                    </strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <a href="{{ route('admin.arixlog.installs') }}" class="btn btn-sm btn-primary">
                        @arixicon('arrow-right', 14) Ver instalaciones
                    </a>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Panel y tema</h3>
                </div>
                <div class="box-body">
                    <table class="table table-condensed arixlog-table">
                        <tbody>
                            <tr>
                                <td>Version instalada</td>
                                <td class="text-right"><strong>{{ $panel['current'] }}</strong></td>
                            </tr>
                            <tr>
                                <td>Ultima version publicada</td>
                                <td class="text-right"><strong>{{ $panel['latest'] ?: 'no se pudo consultar' }}</strong></td>
                            </tr>
                            <tr>
                                <td>Tema Arix</td>
                                <td class="text-right">
                                    <strong>
                                        {{ $panel['theme']['installed']
                                            ? 'detectado' . ($panel['theme']['version'] ? ' (v' . $panel['theme']['version'] . ')' : '')
                                            : 'no detectado' }}
                                    </strong>
                                </td>
                            </tr>
                            @if($panel['theme']['installed'])
                                <tr>
                                    <td>Copia del tema para restaurar</td>
                                    <td class="text-right">
                                        <strong>{{ $panel['theme']['staged_path'] ? basename($panel['theme']['staged_path']) : 'no disponible' }}</strong>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    </table>

                    <a href="{{ route('admin.arixlog.updater') }}" class="btn btn-sm btn-primary">
                        @arixicon('arrow-right', 14) Ir al actualizador
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Ultimo movimiento de la extension</h3>
                </div>
                <div class="box-body no-padding">
                    @if($recent->isEmpty())
                        <p class="arixlog-empty">Todavia no hay nada registrado.</p>
                    @else
                        <ul class="arixlog-feed">
                            @foreach($recent as $event)
                                <li class="arixlog-feed-item arixlog-level-{{ $event->level }}">
                                    <span class="arixlog-feed-dot"></span>
                                    <div>
                                        <div class="arixlog-feed-message">{{ $event->message }}</div>
                                        <div class="arixlog-feed-meta">
                                            {{ $event->channel }} &middot;
                                            {{ optional($event->created_at)->diffForHumans() }}
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="box-footer">
                    <a href="{{ route('admin.arixlog.events') }}" class="btn btn-sm btn-default">
                        @arixicon('file-text', 14) Ver el registro completo
                    </a>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Errores del panel</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted" style="margin-bottom:12px;">
                        {{ $logs['files'] }} archivo(s) de log.
                        @if($logs['latest'])
                            El actual es <code>{{ $logs['latest'] }}</code>.
                        @endif
                    </p>

                    <div class="arixlog-levelbar">
                        @foreach(['emergency' => 'Emergencia', 'critical' => 'Critico', 'error' => 'Error', 'warning' => 'Aviso'] as $key => $label)
                            <div class="arixlog-levelbar-item arixlog-level-{{ $key }}">
                                <span class="arixlog-levelbar-value">{{ $logs['counts'][$key] ?? 0 }}</span>
                                <span class="arixlog-levelbar-label">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>

                    <a href="{{ route('admin.arixlog.logs') }}" class="btn btn-sm btn-primary" style="margin-top:12px;">
                        @arixicon('arrow-right', 14) Abrir el visor
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <p class="text-muted" style="font-size:12px;">
                ArixLog v{{ $version }} &middot; compatible con el tema Arix: no reemplaza archivos del panel
                ni recompila el frontend.
            </p>
        </div>
    </div>
@endsection
