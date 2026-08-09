@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Actualizar panel')
@section('logspterodactyl-heading', 'Actualizacion del panel')
@section('logspterodactyl-subheading', 'con respaldo previo y restauracion del tema Arix')

@section('logspterodactyl-content')
    <div class="row">
        <div class="col-md-7">
            <div class="box {{ $available ? 'box-warning' : 'box-success' }}">
                <div class="box-header with-border">
                    <h3 class="box-title">Version</h3>
                </div>
                <div class="box-body">
                    <div class="logspterodactyl-versions">
                        <div>
                            <span class="logspterodactyl-versions-label">Instalada</span>
                            <span class="logspterodactyl-versions-value">{{ $current }}</span>
                        </div>
                        <div class="logspterodactyl-versions-arrow">@logsicon('arrow-right', 20)</div>
                        <div>
                            <span class="logspterodactyl-versions-label">Ultima publicada</span>
                            <span class="logspterodactyl-versions-value">{{ $latest['version'] ?: 'desconocida' }}</span>
                        </div>
                    </div>

                    @if($latest['error'])
                        <p class="logspterodactyl-note logspterodactyl-note-warning">
                            @logsicon('alert', 16)
                            <span>No se pudo consultar la ultima version: {{ $latest['error'] }}</span>
                        </p>
                    @elseif($available)
                        <p class="logspterodactyl-note logspterodactyl-note-warning">
                            @logsicon('upload-cloud', 16)
                            <span>Hay una version nueva disponible.</span>
                        </p>
                    @else
                        <p class="logspterodactyl-note logspterodactyl-note-ok">
                            @logsicon('check-circle', 16)
                            <span>El panel esta al dia.</span>
                        </p>
                    @endif

                    <form method="POST" action="{{ route('admin.logspterodactyl.updater.check') }}" style="display:inline-block;">
                        {{ csrf_field() }}
                        <button type="submit" class="btn btn-sm btn-default">@logsicon('refresh', 14) Comprobar de nuevo</button>
                    </form>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Comprobaciones previas</h3>
                </div>
                <div class="box-body no-padding">
                    <table class="table logspterodactyl-table">
                        <tbody>
                        @foreach($checks as $check)
                            <tr>
                                <td style="width:34px;">
                                    <span class="logspterodactyl-check {{ $check['ok'] ? 'ok' : ($check['level'] === 'critico' ? 'danger' : 'warning') }}">
                                        @if($check['ok']) @logsicon('check', 14) @else @logsicon('x', 14) @endif
                                    </span>
                                </td>
                                <td>
                                    <strong>{{ $check['label'] }}</strong>
                                    <div class="logspterodactyl-muted logspterodactyl-small">{{ $check['detail'] }}</div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title">Lanzar la actualizacion</h3>
                </div>
                <form method="POST" action="{{ route('admin.logspterodactyl.updater.start') }}"
                      onsubmit="return confirm('El panel entrara en mantenimiento durante varios minutos. Antes se hara un respaldo completo. ¿Continuar?');">
                    {{ csrf_field() }}
                    <div class="box-body">
                        <ol class="logspterodactyl-steps-preview">
                            <li>Respaldo completo de los archivos y de la base de datos.</li>
                            <li>Descarga y comprobacion del paquete oficial de Pterodactyl.</li>
                            <li>Panel en mantenimiento, descompresion y <code>composer install</code>.</li>
                            <li>Migraciones de la base de datos.</li>
                            @if($theme['installed'])
                                <li>
                                    Restauracion de los archivos del tema Arix
                                    @if($theme['staged_path'])
                                        desde <code>{{ basename($theme['staged_path']) }}</code>
                                    @endif
                                    y recompilado de sus assets.
                                </li>
                            @endif
                            <li>Registro de la extension y salida del modo mantenimiento.</li>
                        </ol>

                        @if($themeLags)
                            <p class="logspterodactyl-note logspterodactyl-note-warning">
                                @logsicon('alert', 16)
                                <span>
                                    <strong>Leelo antes de continuar.</strong>
                                    Tu tema Arix esta hecho para el panel
                                    <strong>{{ $theme['target_panel'] }}</strong> y vas a actualizar a
                                    <strong>{{ $latest['version'] }}</strong>. Arix no es solo una capa de
                                    estilos: reemplaza modelos, controladores y rutas del panel por los suyos.
                                    Al restaurarlo se vuelven a poner los archivos hechos para
                                    {{ $theme['target_panel'] }} encima del panel nuevo, y si Pterodactyl ha
                                    cambiado alguna de esas clases el panel puede fallar.
                                    <br><br>
                                    Lo seguro es actualizar primero el tema a una version que soporte
                                    {{ $latest['version'] }}. Si aun asi sigues, el respaldo te deja volver
                                    atras con el boton "Deshacer".
                                </span>
                            </p>
                        @endif

                        @if($theme['installed'] && !$theme['staged_path'])
                            <p class="logspterodactyl-note logspterodactyl-note-warning">
                                @logsicon('alert', 16)
                                <span>
                                    Se ha detectado el tema Arix pero no su carpeta <code>arix/&lt;version&gt;</code>.
                                    Sin ella no se puede restaurar solo: tras actualizar habra que volver a subir
                                    los archivos del tema y ejecutar <code>php artisan arix install</code>.
                                </span>
                            </p>
                        @endif

                        @unless($canExec)
                            <p class="logspterodactyl-note logspterodactyl-note-warning">
                                @logsicon('alert', 16)
                                <span>
                                    PHP no puede lanzar procesos en este servidor. El boton preparara el guion
                                    pero tendras que ejecutarlo tu por SSH; el comando aparecera en el registro.
                                    Tambien puedes usar directamente
                                    <code>php artisan logspterodactyl:panel-update</code> como root.
                                </span>
                            </p>
                        @endunless

                        @if($blocking !== [])
                            <p class="logspterodactyl-note logspterodactyl-note-error">
                                @logsicon('x-circle', 16)
                                <span>Hay comprobaciones criticas sin pasar. Corrigelas antes de continuar.</span>
                            </p>
                        @endif

                        <div class="form-group">
                            <label for="version">Version de destino</label>
                            <input type="text" name="version" id="version" class="form-control"
                                   value="{{ $latest['version'] }}" placeholder="por ejemplo 1.15.0">
                        </div>

                        <label class="logspterodactyl-switch">
                            <input type="checkbox" name="restore_theme" value="1" checked>
                            <span>Restaurar el tema Arix despues de actualizar</span>
                        </label>

                        <label class="logspterodactyl-switch">
                            <input type="checkbox" name="confirm" value="1" required>
                            <span>Entiendo que el panel estara en mantenimiento y que se hara un respaldo antes</span>
                        </label>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-danger" @if($running) disabled @endif>
                            @logsicon('upload-cloud', 14) Actualizar ahora
                        </button>
                        @if($running)
                            <span class="logspterodactyl-muted">Ya hay una actualizacion en marcha.</span>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        <div class="col-md-5">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Progreso</h3>
                </div>
                <div class="box-body">
                    <div id="logspterodactyl-progress">
                        <p class="logspterodactyl-empty">
                            No hay ninguna actualizacion en marcha.
                        </p>
                    </div>
                    <pre class="logspterodactyl-stack" id="logspterodactyl-update-log" hidden style="max-height:340px;"></pre>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Historial y vuelta atras</h3>
                </div>
                <div class="box-body no-padding">
                    @if($runs->isEmpty())
                        <p class="logspterodactyl-empty">Todavia no se ha lanzado ninguna actualizacion desde aqui.</p>
                    @else
                        <table class="table logspterodactyl-table">
                            <thead><tr><th>Fecha</th><th>Cambio</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                            @foreach($runs as $run)
                                <tr>
                                    <td class="logspterodactyl-small">{{ optional($run->started_at)->format('d/m/Y H:i') }}</td>
                                    <td class="logspterodactyl-small">{{ $run->from_version }} &rarr; {{ $run->to_version }}</td>
                                    <td>
                                        @php
                                            $tone = match($run->status) {
                                                'success' => 'ok',
                                                'failed' => 'danger',
                                                'rolled_back' => 'warning',
                                                default => 'info',
                                            };
                                            $label = match($run->status) {
                                                'success' => 'Correcta',
                                                'failed' => 'Fallida',
                                                'rolled_back' => 'Deshecha',
                                                default => 'En marcha',
                                            };
                                        @endphp
                                        <span class="logspterodactyl-state logspterodactyl-state-{{ $tone }}">{{ $label }}</span>
                                    </td>
                                    <td class="text-right">
                                        <form method="POST" action="{{ route('admin.logspterodactyl.updater.rollback', $run->id) }}"
                                              onsubmit="return confirm('Se restauraran los archivos y la base de datos al estado anterior a esta actualizacion. El panel entrara en mantenimiento. ¿Continuar?');">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="confirm" value="1">
                                            <button type="submit" class="btn btn-xs btn-warning">
                                                @logsicon('rotate', 12) Deshacer
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                <div class="box-footer logspterodactyl-small logspterodactyl-muted">
                    "Deshacer" restaura los archivos y la base de datos desde el respaldo que se hizo
                    justo antes de esa actualizacion. Los archivos que la version nueva anadio y que no
                    existian antes se quedan en el disco, pero el panel restaurado no los usa.
                </div>
            </div>
        </div>
    </div>
@endsection

@section('logspterodactyl-scripts')
    <script>
        (function () {
            'use strict';

            var runId = @json(optional($running)->id);
            var runToken = @json(session('logspterodactyl_watch') ?: optional($running)->token);

            if (!runToken) { return; }

            var progress = document.getElementById('logspterodactyl-progress');
            var logBox = document.getElementById('logspterodactyl-update-log');
            var statusUrl = runId ? @json(url('/admin/logspterodactyl/updater')) + '/' + runId + '/status' : null;
            var staticUrl = '/extensions/logspterodactyl/runs/' + runToken + '/status.json';
            var logUrl = '/extensions/logspterodactyl/runs/' + runToken + '/update.log';

            function escapeHtml(text) {
                return String(text === null || text === undefined ? '' : text)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function render(status) {
                if (!status) { return; }

                var html = '<ul class="logspterodactyl-steps">';
                var steps = status.steps || [];

                for (var i = 0; i < steps.length; i++) {
                    html += '<li class="logspterodactyl-step logspterodactyl-step-' + escapeHtml(steps[i].state) + '">'
                        + '<span class="logspterodactyl-step-dot"></span>'
                        + '<span>' + escapeHtml(steps[i].name) + '</span></li>';
                }

                if (status.state === 'running' && status.step) {
                    html += '<li class="logspterodactyl-step logspterodactyl-step-running">'
                        + '<span class="logspterodactyl-step-dot"></span>'
                        + '<span>' + escapeHtml(status.step) + '</span></li>';
                }

                html += '</ul>';

                if (status.state === 'success') {
                    html += '<p class="logspterodactyl-note logspterodactyl-note-ok"><span>Actualizacion terminada. '
                        + 'Recarga la pagina para ver la version nueva.</span></p>';
                } else if (status.state === 'failed') {
                    html += '<p class="logspterodactyl-note logspterodactyl-note-error"><span>'
                        + escapeHtml(status.error || 'La actualizacion fallo.')
                        + ' El respaldo sigue disponible: puedes usar "Deshacer".</span></p>';
                }

                progress.innerHTML = html;
            }

            function fetchJson(url, callback) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now(), true);
                xhr.onload = function () {
                    if (xhr.status !== 200) { return callback(null); }
                    try { callback(JSON.parse(xhr.responseText)); } catch (e) { callback(null); }
                };
                xhr.onerror = function () { callback(null); };
                xhr.send();
            }

            function fetchText(url, callback) {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', url + '?_=' + Date.now(), true);
                xhr.onload = function () { callback(xhr.status === 200 ? xhr.responseText : null); };
                xhr.onerror = function () { callback(null); };
                xhr.send();
            }

            function poll() {
                // El archivo estatico lo sirve el servidor web sin pasar por
                // PHP, asi que sigue respondiendo aunque el panel este en
                // modo mantenimiento. Es la fuente principal.
                fetchJson(staticUrl, function (status) {
                    if (status) {
                        render(status);

                        if (status.state !== 'running' && statusUrl) {
                            // Ya termino: se avisa al panel para que cierre la fila.
                            fetchJson(statusUrl, function () {});
                        }
                        return;
                    }

                    if (statusUrl) {
                        fetchJson(statusUrl, function (data) {
                            if (data && data.status) { render(data.status); }
                        });
                    }
                });

                fetchText(logUrl, function (text) {
                    if (text === null) { return; }
                    logBox.hidden = false;
                    logBox.textContent = text.slice(-20000);
                    logBox.scrollTop = logBox.scrollHeight;
                });
            }

            poll();
            window.setInterval(poll, 4000);
        })();
    </script>
@endsection
