@extends('arixlog::admin._layout')

@section('arixlog-title', 'Instalaciones')
@section('arixlog-heading', 'Instalaciones')
@section('arixlog-subheading', 'en curso ahora mismo e historial completo')

@section('arixlog-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="arixlog-stats">
                @include('arixlog::admin.partials.stat', ['icon' => 'server', 'value' => $summary['total'], 'label' => 'instalaciones (30 dias)', 'tone' => ''])
                @include('arixlog::admin.partials.stat', ['icon' => 'check-circle', 'value' => $summary['success'], 'label' => 'terminadas bien', 'tone' => 'ok'])
                @include('arixlog::admin.partials.stat', ['icon' => 'x-circle', 'value' => $summary['failed'], 'label' => 'fallidas', 'tone' => $summary['failed'] ? 'danger' : ''])
                @include('arixlog::admin.partials.stat', ['icon' => 'clock', 'value' => $summary['timeout'], 'label' => 'detenidas por tiempo', 'tone' => $summary['timeout'] ? 'warning' : ''])
                @include('arixlog::admin.partials.stat', ['icon' => 'stop', 'value' => $summary['cancelled'], 'label' => 'detenidas a mano', 'tone' => ''])
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Instalando ahora mismo</h3>
                    <div class="box-tools">
                        <span class="arixlog-muted" id="arixlog-live-meta"></span>
                    </div>
                </div>
                <div class="box-body no-padding">
                    <div id="arixlog-live"><p class="arixlog-empty">Cargando...</p></div>
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
                    <form method="GET" class="arixlog-filters" style="margin-bottom:12px;">
                        <div class="arixlog-field">
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
                        <div class="arixlog-field arixlog-field-grow">
                            <label for="search">Buscar</label>
                            <input type="text" name="search" id="search" class="form-control"
                                   value="{{ $search }}" placeholder="servidor, cliente, correo o nodo">
                        </div>
                        <div class="arixlog-field arixlog-field-actions">
                            <button type="submit" class="btn btn-primary">@arixicon('search', 14) Buscar</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover arixlog-table">
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
                                        @if($row->is_reinstall)
                                            <span class="arixlog-chip">reinstalacion</span>
                                        @endif
                                        @if($row->notes)
                                            <div class="arixlog-muted arixlog-small">{{ $row->notes }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        {{ $row->user_name ?: '-' }}
                                        <div class="arixlog-muted arixlog-small">{{ $row->user_email ?: '-' }}</div>
                                    </td>
                                    <td>
                                        {{ $row->node_name ?: '-' }}
                                        <div class="arixlog-muted arixlog-small">{{ $row->egg_name ?: '-' }}</div>
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
                                        <span class="arixlog-state arixlog-state-{{ $tone }}">{{ $label }}</span>
                                        @if($row->cancelled_by)
                                            <div class="arixlog-muted arixlog-small">por {{ $row->cancelled_by }}</div>
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
                                            <span class="arixlog-muted arixlog-small">{{ $row->old_allocation }}</span>
                                            <div>@arixicon('arrow-right', 12) <strong>{{ $row->new_allocation }}</strong></div>
                                        @else
                                            <span class="arixlog-muted">{{ $row->old_allocation ?: '-' }}</span>
                                        @endif
                                    </td>
                                    <td class="arixlog-small">
                                        {{ optional($row->started_at)->format('d/m/Y H:i') ?: '-' }}
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7"><p class="arixlog-empty">No hay instalaciones registradas todavia.</p></td></tr>
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

@section('arixlog-scripts')
    <script>
        (function () {
            'use strict';

            var liveUrl = @json(route('admin.arixlog.installs.live'));
            var stopUrlTemplate = @json(route('admin.arixlog.installs.stop', ['server' => '__ID__']));
            var recreateUrlTemplate = @json(route('admin.arixlog.installs.recreate', ['server' => '__ID__']));
            var token = @json(csrf_token());
            var container = document.getElementById('arixlog-live');
            var meta = document.getElementById('arixlog-live-meta');

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
                    container.innerHTML = '<p class="arixlog-empty">No hay ningun servidor instalando en este momento.</p>';
                    return;
                }

                var html = '<div class="table-responsive"><table class="table table-hover arixlog-table">'
                    + '<thead><tr><th>Servidor</th><th>Cliente</th><th>Nodo</th><th>Puerto</th>'
                    + '<th>Tiempo</th><th>Acciones</th></tr></thead><tbody>';

                for (var i = 0; i < data.servers.length; i++) {
                    var s = data.servers[i];

                    html += '<tr class="' + (s.over_limit ? 'arixlog-row-warning' : '') + '">'
                        + '<td><a href="' + escapeHtml(s.admin_url) + '"><strong>' + escapeHtml(s.name) + '</strong></a>'
                        + (s.is_reinstall ? ' <span class="arixlog-chip">reinstalacion</span>' : '')
                        + '<div class="arixlog-muted arixlog-small">' + escapeHtml(s.egg) + '</div></td>'
                        + '<td>' + escapeHtml(s.owner) + '<div class="arixlog-muted arixlog-small">' + escapeHtml(s.owner_email) + '</div></td>'
                        + '<td>' + escapeHtml(s.node)
                        + '<div class="arixlog-muted arixlog-small">' + s.free_ports + ' puertos libres</div></td>'
                        + '<td><code>' + escapeHtml(s.allocation) + '</code></td>'
                        + '<td><strong class="' + (s.over_limit ? 'arixlog-text-warning' : '') + '">'
                        + s.minutes + ' min</strong>'
                        + '<div class="arixlog-muted arixlog-small">desde ' + escapeHtml(s.started_at) + '</div></td>'
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
                    + '<p class="arixlog-muted arixlog-small" style="padding:10px 14px;">'
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
