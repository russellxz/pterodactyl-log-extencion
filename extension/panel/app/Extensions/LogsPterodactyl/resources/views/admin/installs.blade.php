@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Instalaciones')
@section('logspterodactyl-heading', 'Instalaciones')
@section('logspterodactyl-subheading', 'en curso ahora mismo e historial completo')

@section('logspterodactyl-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="logspterodactyl-stats">
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'server', 'value' => $summary['total'], 'label' => 'instalaciones (30 dias)', 'tone' => ''])
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'check-circle', 'value' => $summary['success'], 'label' => 'terminadas bien', 'tone' => 'ok'])
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'x-circle', 'value' => $summary['failed'], 'label' => 'fallidas', 'tone' => $summary['failed'] ? 'danger' : ''])
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'clock', 'value' => $summary['timeout'], 'label' => 'detenidas por tiempo', 'tone' => $summary['timeout'] ? 'warning' : ''])
                @include('logspterodactyl::admin.partials.stat', ['icon' => 'stop', 'value' => $summary['cancelled'], 'label' => 'detenidas a mano', 'tone' => ''])
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Instalando ahora mismo</h3>
                    <div class="box-tools">
                        <span class="logspterodactyl-muted" id="logspterodactyl-live-meta"></span>
                    </div>
                </div>
                <div class="box-body no-padding">
                    <div id="logspterodactyl-live"><p class="logspterodactyl-empty">Cargando...</p></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Seguimiento de las que detuvo el sistema</h3>
                    <div class="box-tools">
                        <span class="logspterodactyl-muted logspterodactyl-small">ultimos 60 dias</span>
                    </div>
                </div>
                <div class="box-body">
                    @if($followUp['rows'] === [])
                        <p class="logspterodactyl-empty">
                            No se ha detenido ninguna instalacion en los ultimos 60 dias.
                        </p>
                    @else
                        <div class="logspterodactyl-stats" style="margin-bottom:14px;">
                            @include('logspterodactyl::admin.partials.stat', [
                                'icon' => 'stop', 'value' => $followUp['resumen']['total'],
                                'label' => 'instalaciones detenidas', 'tone' => '',
                            ])
                            @include('logspterodactyl::admin.partials.stat', [
                                'icon' => 'check-circle', 'value' => $followUp['resumen']['resueltas'],
                                'label' => 'se instalaron bien despues', 'tone' => 'ok',
                            ])
                            @include('logspterodactyl::admin.partials.stat', [
                                'icon' => 'x-circle', 'value' => $followUp['resumen']['fallidas'],
                                'label' => 'volvieron a fallar', 'tone' => $followUp['resumen']['fallidas'] ? 'danger' : '',
                            ])
                            @include('logspterodactyl::admin.partials.stat', [
                                'icon' => 'clock', 'value' => $followUp['resumen']['pendientes'],
                                'label' => 'sin reintentar todavia', 'tone' => 'warning',
                            ])
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover logspterodactyl-table">
                                <thead>
                                    <tr>
                                        <th>Servidor</th>
                                        <th>Cliente</th>
                                        <th>Se detuvo</th>
                                        <th>Puerto</th>
                                        <th>¿Y despues?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach($followUp['rows'] as $row)
                                    <tr>
                                        <td>
                                            <strong>{{ $row['server_name'] ?: '(sin nombre)' }}</strong>
                                            <span class="logspterodactyl-attempt" title="Numero de intento">#{{ $row['attempt'] }}</span>
                                            <div class="logspterodactyl-muted logspterodactyl-small">{{ $row['node_name'] }}</div>
                                        </td>
                                        <td>
                                            {{ $row['user_name'] ?: '-' }}
                                            <div class="logspterodactyl-muted logspterodactyl-small">{{ $row['user_email'] }}</div>
                                        </td>
                                        <td>
                                            tras <strong>{{ $row['minutos'] }} min</strong>
                                            <div class="logspterodactyl-muted logspterodactyl-small">
                                                {{ $row['detenida_por'] === 'sistema' ? 'sistema automatico' : $row['detenida_por'] }}
                                                @if($row['forzada'])
                                                    &middot; forzada
                                                @endif
                                            </div>
                                        </td>
                                        <td class="logspterodactyl-small">
                                            @if($row['puerto_despues'] && $row['puerto_despues'] !== $row['puerto_antes'])
                                                <span class="logspterodactyl-muted">{{ $row['puerto_antes'] }}</span>
                                                <div>@logsicon('arrow-right', 12) <strong>{{ $row['puerto_despues'] }}</strong></div>
                                            @else
                                                <span class="logspterodactyl-muted">sin cambio</span>
                                            @endif
                                        </td>
                                        <td>
                                            @switch($row['desenlace'])
                                                @case('resuelta')
                                                    <span class="logspterodactyl-state logspterodactyl-state-ok">Se instalo bien</span>
                                                    @if($row['siguiente_duracion'] !== null)
                                                        <div class="logspterodactyl-muted logspterodactyl-small">
                                                            el reintento tardo {{ $row['siguiente_duracion'] }} min
                                                        </div>
                                                    @endif
                                                    @break
                                                @case('volvio_a_fallar')
                                                    <span class="logspterodactyl-state logspterodactyl-state-danger">Volvio a fallar</span>
                                                    <div class="logspterodactyl-muted logspterodactyl-small">
                                                        el cliente sigue con datos mal puestos
                                                    </div>
                                                    @break
                                                @case('reintentando')
                                                    <span class="logspterodactyl-state logspterodactyl-state-info">Reinstalando ahora</span>
                                                    @break
                                                @default
                                                    <span class="logspterodactyl-state logspterodactyl-state-warning">Aun no lo ha reintentado</span>
                                            @endswitch
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Historial</h3>
                </div>
                <div class="box-body">
                    <form method="GET" class="logspterodactyl-filters" style="margin-bottom:12px;">
                        <div class="logspterodactyl-field">
                            <label for="status">Estado</label>
                            <select name="status" id="status" class="form-control" onchange="this.form.submit()">
                                <option value="">Todos</option>
                                @foreach([
                                    'installing' => 'En curso',
                                    'success' => 'Terminadas',
                                    'failed' => 'Fallidas',
                                    'timeout' => 'Detenidas por tiempo',
                                    'cancelled' => 'Detenidas a mano',
                                ] as $key => $label)
                                    <option value="{{ $key }}" @if($status === $key) selected @endif>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-grow">
                            <label for="search">Buscar</label>
                            <input type="text" name="search" id="search" class="form-control"
                                   value="{{ $search }}" placeholder="servidor, cliente, correo o nodo">
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-actions">
                            <button type="submit" class="btn btn-primary">@logsicon('search', 14) Buscar</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover logspterodactyl-table">
                            <thead>
                                <tr>
                                    <th>Servidor</th>
                                    <th>Cliente</th>
                                    <th>Nodo / Egg</th>
                                    <th>Estado</th>
                                    <th>Duracion</th>
                                    <th>Puerto</th>
                                    <th>Inicio</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($history as $row)
                                <tr>
                                    <td>
                                        <strong>{{ $row->server_name ?: '(sin nombre)' }}</strong>
                                        <span class="logspterodactyl-attempt" title="Numero de intento de este servidor">#{{ $row->attempt ?: 1 }}</span>
                                        @if($row->is_reinstall)
                                            <span class="logspterodactyl-chip">reinstalacion</span>
                                        @endif
                                        @if($row->forced)
                                            <span class="logspterodactyl-chip">forzada</span>
                                        @endif
                                        @if($row->notes)
                                            <div class="logspterodactyl-muted logspterodactyl-small">{{ $row->notes }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $row->user_name ?: '-' }}
                                        <div class="logspterodactyl-muted logspterodactyl-small">{{ $row->user_email ?: '-' }}</div>
                                    </td>
                                    <td>
                                        {{ $row->node_name ?: '-' }}
                                        <div class="logspterodactyl-muted logspterodactyl-small">{{ $row->egg_name ?: '-' }}</div>
                                    </td>
                                    <td>
                                        @php
                                            $tone = match($row->status) {
                                                'success' => 'ok',
                                                'failed' => 'danger',
                                                'timeout' => 'warning',
                                                'cancelled' => 'warning',
                                                default => 'info',
                                            };
                                            $label = match($row->status) {
                                                'success' => 'Terminada',
                                                'failed' => 'Fallida',
                                                'timeout' => 'Detenida por tiempo',
                                                'cancelled' => 'Detenida a mano',
                                                default => 'En curso',
                                            };
                                        @endphp
                                        <span class="logspterodactyl-state logspterodactyl-state-{{ $tone }}">{{ $label }}</span>
                                        @if($row->cancelled_by)
                                            <div class="logspterodactyl-muted logspterodactyl-small">por {{ $row->cancelled_by }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        @if($row->duration_seconds)
                                            {{ $row->duration_seconds >= 60
                                                ? intdiv($row->duration_seconds, 60) . ' min'
                                                : $row->duration_seconds . ' s' }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td>
                                        @if($row->new_allocation && $row->new_allocation !== $row->old_allocation)
                                            <span class="logspterodactyl-muted logspterodactyl-small">{{ $row->old_allocation }}</span>
                                            <div>@logsicon('arrow-right', 12) <strong>{{ $row->new_allocation }}</strong></div>
                                        @else
                                            <span class="logspterodactyl-muted">{{ $row->old_allocation ?: '-' }}</span>
                                        @endif
                                    </td>
                                    <td class="logspterodactyl-small">
                                        {{ optional($row->started_at)->format('d/m/Y H:i') ?: '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><p class="logspterodactyl-empty">No hay instalaciones registradas todavia.</p></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $history->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection

@section('logspterodactyl-scripts')
    <script>
        (function () {
            'use strict';

            var liveUrl = @json(route('admin.logspterodactyl.installs.live'));
            var stopUrlTemplate = @json(route('admin.logspterodactyl.installs.stop', ['server' => '__ID__']));
            var recreateUrlTemplate = @json(route('admin.logspterodactyl.installs.recreate', ['server' => '__ID__']));
            var token = @json(csrf_token());
            var container = document.getElementById('logspterodactyl-live');
            var meta = document.getElementById('logspterodactyl-live-meta');

            function escapeHtml(text) {
                return String(text === null || text === undefined ? '' : text)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function form(action, id, extra, label, cls, confirmText) {
                var html = '<form method="POST" action="' + action.replace('__ID__', id) + '" style="display:inline-block;margin-right:4px;"'
                    + ' onsubmit="return confirm(' + JSON.stringify(confirmText).replace(/"/g, '&quot;') + ');">'
                    + '<input type="hidden" name="_token" value="' + escapeHtml(token) + '">';

                for (var key in extra) {
                    if (Object.prototype.hasOwnProperty.call(extra, key)) {
                        html += '<input type="hidden" name="' + key + '" value="' + escapeHtml(extra[key]) + '">';
                    }
                }

                return html + '<button type="submit" class="btn btn-xs ' + cls + '">' + label + '</button></form>';
            }

            function render(data) {
                if (!data.servers.length) {
                    container.innerHTML = '<p class="logspterodactyl-empty">No hay ningun servidor instalando en este momento.</p>';
                    return;
                }

                var html = '<div class="table-responsive"><table class="table table-hover logspterodactyl-table">'
                    + '<thead><tr><th>Servidor</th><th>Cliente</th><th>Nodo</th><th>Puerto</th>'
                    + '<th>Tiempo</th><th>Acciones</th></tr></thead><tbody>';

                for (var i = 0; i < data.servers.length; i++) {
                    var s = data.servers[i];

                    html += '<tr class="' + (s.over_limit ? 'logspterodactyl-row-warning' : '') + '">'
                        + '<td><a href="' + escapeHtml(s.admin_url) + '"><strong>' + escapeHtml(s.name) + '</strong></a>'
                        + (s.is_reinstall ? ' <span class="logspterodactyl-chip">reinstalacion</span>' : '')
                        + '<div class="logspterodactyl-muted logspterodactyl-small">' + escapeHtml(s.egg) + '</div></td>'
                        + '<td>' + escapeHtml(s.owner) + '<div class="logspterodactyl-muted logspterodactyl-small">' + escapeHtml(s.owner_email) + '</div></td>'
                        + '<td>' + escapeHtml(s.node)
                        + '<div class="logspterodactyl-muted logspterodactyl-small">' + s.free_ports + ' puertos libres</div></td>'
                        + '<td><code>' + escapeHtml(s.allocation) + '</code></td>'
                        + '<td><strong class="' + (s.over_limit ? 'logspterodactyl-text-warning' : '') + '">'
                        + s.minutes + ' min</strong>'
                        + '<div class="logspterodactyl-muted logspterodactyl-small">desde ' + escapeHtml(s.started_at) + '</div></td>'
                        + '<td>'
                        + form(stopUrlTemplate, s.id, { mode: 'fail_rotate', notify: '1' },
                            'Parar y cambiar puerto', 'btn-warning',
                            'Se marcara la instalacion como fallida y se movera el servidor a otro puerto libre del nodo. ¿Continuar?')
                        + form(stopUrlTemplate, s.id, { mode: 'force_rotate', notify: '1' },
                            'Parada forzada', 'btn-danger',
                            'ADEMAS de lo anterior se borrara el servidor en el nodo para cortar el contenedor de instalacion colgado. Los archivos de esa instalacion incompleta se pierden y habra que recrear el servidor en el nodo antes de reinstalar. ¿Continuar?')
                        + '</td></tr>';
                }

                container.innerHTML = html + '</tbody></table></div>'
                    + '<p class="logspterodactyl-muted logspterodactyl-small" style="padding:10px 14px;">'
                    + (data.watchdog_enabled
                        ? 'El sistema automatico esta activo y detendra solo las que pasen de ' + data.limit_minutes + ' minutos.'
                        : 'El sistema automatico esta desactivado: estas paradas hay que hacerlas a mano.')
                    + '</p>';
            }

            function load() {
                var xhr = new XMLHttpRequest();
                xhr.open('GET', liveUrl, true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function () {
                    if (xhr.status !== 200) { return; }
                    var data;
                    try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
                    render(data);
                    if (meta) { meta.textContent = 'actualizado ' + data.generated_at; }
                };

                xhr.send();
            }

            load();
            window.setInterval(function () { if (!document.hidden) { load(); } }, 15000);
        })();
    </script>
@endsection
