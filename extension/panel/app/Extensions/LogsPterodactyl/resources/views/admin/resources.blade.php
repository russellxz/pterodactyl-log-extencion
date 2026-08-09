@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Consumo')
@section('logspterodactyl-heading', 'Consumo de recursos')
@section('logspterodactyl-subheading', 'en tiempo real, con el cliente de cada servidor')

@section('logspterodactyl-content')
    @unless($samplingEnabled)
        <div class="row">
            <div class="col-xs-12">
                <div class="logspterodactyl-alert logspterodactyl-alert-warning">
                    @logsicon('alert', 18)
                    <span>
                        El muestreo historico esta desactivado, asi que el ranking de abajo quedara vacio.
                        La tabla en vivo si funciona. Se activa en
                        <a href="{{ route('admin.logspterodactyl.settings') }}">Configuracion</a>.
                    </span>
                </div>
            </div>
        </div>
    @endunless

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Ahora mismo</h3>
                    <div class="box-tools">
                        <span class="logspterodactyl-muted" id="logspterodactyl-live-meta"></span>
                    </div>
                </div>
                <div class="box-body">
                    <div class="logspterodactyl-filters" style="margin-bottom:10px;">
                        <div class="logspterodactyl-field">
                            <label for="logspterodactyl-node">Nodo</label>
                            <select id="logspterodactyl-node" class="form-control">
                                <option value="">Todos</option>
                                @foreach($nodes as $node)
                                    <option value="{{ $node->id }}" @if($nodeId === $node->id) selected @endif>{{ $node->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-grow">
                            <label for="logspterodactyl-filter">Filtrar</label>
                            <input type="text" id="logspterodactyl-filter" class="form-control" placeholder="servidor, cliente o correo">
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-actions">
                            <label class="logspterodactyl-checkline">
                                <input type="checkbox" id="logspterodactyl-only-online" checked> Solo encendidos
                            </label>
                            <button type="button" class="btn btn-primary" id="logspterodactyl-refresh">
                                @logsicon('refresh', 14) Actualizar
                            </button>
                        </div>
                    </div>

                    <div class="logspterodactyl-stats" id="logspterodactyl-totals"></div>
                    <div id="logspterodactyl-errors"></div>
                    <div id="logspterodactyl-live"><p class="logspterodactyl-empty">Cargando...</p></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Los que mas consumen</h3>
                    <div class="box-tools">
                        <select id="logspterodactyl-top-hours" class="form-control input-sm" style="display:inline-block;width:auto;">
                            <option value="6">Ultimas 6 horas</option>
                            <option value="24" selected>Ultimas 24 horas</option>
                            <option value="168">Ultimos 7 dias</option>
                            <option value="720">Ultimos 30 dias</option>
                        </select>
                        <select id="logspterodactyl-top-order" class="form-control input-sm" style="display:inline-block;width:auto;margin-left:4px;">
                            <option value="cpu">Ordenar por CPU</option>
                            <option value="memory">Ordenar por RAM</option>
                            <option value="disk">Ordenar por disco</option>
                        </select>
                    </div>
                </div>
                <div class="box-body no-padding">
                    <div id="logspterodactyl-top"><p class="logspterodactyl-empty">Cargando...</p></div>
                </div>
                <div class="box-footer logspterodactyl-small logspterodactyl-muted">
                    Se toma una muestra por minuto de cada servidor encendido y se guardan
                    {{ $retentionDays }} dias. Esta tabla sirve para detectar quien abusa de verdad,
                    no solo quien tiene un pico puntual.
                </div>
            </div>
        </div>
    </div>
@endsection

@section('logspterodactyl-scripts')
    <script>
        (function () {
            'use strict';

            var liveUrl = @json(route('admin.logspterodactyl.resources.live'));
            var topUrl = @json(route('admin.logspterodactyl.resources.top'));
            var alertCpu = {{ $alertCpu }};
            var alertMemory = {{ $alertMemory }};

            var live = document.getElementById('logspterodactyl-live');
            var totals = document.getElementById('logspterodactyl-totals');
            var errors = document.getElementById('logspterodactyl-errors');
            var meta = document.getElementById('logspterodactyl-live-meta');
            var top = document.getElementById('logspterodactyl-top');
            var lastData = null;

            function escapeHtml(text) {
                return String(text === null || text === undefined ? '' : text)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function bytes(value) {
                if (!value) { return '0 B'; }
                var units = ['B', 'KB', 'MB', 'GB', 'TB'];
                var i = 0;
                while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
                return (Math.round(value * 10) / 10) + ' ' + units[i];
            }

            function bar(percent, danger) {
                var width = Math.max(0, Math.min(100, percent));
                return '<div class="logspterodactyl-bar"><span class="logspterodactyl-bar-fill' + (danger ? ' danger' : '')
                    + '" style="width:' + width + '%"></span></div>';
            }

            function stat(icon, value, label, tone) {
                return '<div class="logspterodactyl-stat ' + (tone || '') + '">'
                    + '<div class="logspterodactyl-stat-body"><div class="logspterodactyl-stat-value">' + escapeHtml(value) + '</div>'
                    + '<div class="logspterodactyl-stat-label">' + escapeHtml(label) + '</div></div></div>';
            }

            function renderLive() {
                if (!lastData) { return; }

                var data = lastData;
                var filter = (document.getElementById('logspterodactyl-filter').value || '').toLowerCase();
                var onlyOnline = document.getElementById('logspterodactyl-only-online').checked;

                totals.innerHTML =
                    stat('server', data.totals.online + ' / ' + data.totals.servers, 'servidores encendidos', '')
                    + stat('cpu', data.totals.cpu + ' %', 'CPU total en uso', '')
                    + stat('memory', bytes(data.totals.memory), 'RAM en uso', '')
                    + stat('hard-drive', bytes(data.totals.disk), 'disco ocupado', '');

                errors.innerHTML = '';
                if (data.errors && data.errors.length) {
                    var errHtml = '';
                    for (var e = 0; e < data.errors.length; e++) {
                        errHtml += '<div class="logspterodactyl-alert logspterodactyl-alert-warning"><span>'
                            + escapeHtml(data.errors[e]) + '</span></div>';
                    }
                    errors.innerHTML = errHtml;
                }

                var rows = [];
                for (var i = 0; i < data.servers.length; i++) {
                    var s = data.servers[i];
                    if (onlyOnline && !s.online) { continue; }
                    if (filter) {
                        var haystack = (s.name + ' ' + s.owner + ' ' + s.owner_email + ' ' + s.node).toLowerCase();
                        if (haystack.indexOf(filter) === -1) { continue; }
                    }
                    rows.push(s);
                }

                if (!rows.length) {
                    live.innerHTML = '<p class="logspterodactyl-empty">Ningun servidor coincide con el filtro.</p>';
                    return;
                }

                var html = '<div class="table-responsive"><table class="table table-hover logspterodactyl-table">'
                    + '<thead><tr><th>Servidor</th><th>Cliente</th><th>Nodo</th><th>Estado</th>'
                    + '<th style="min-width:130px;">CPU</th><th style="min-width:150px;">RAM</th>'
                    + '<th>Disco</th><th>Red</th></tr></thead><tbody>';

                for (var r = 0; r < rows.length; r++) {
                    var v = rows[r];
                    var u = v.usage || {};
                    var memLimit = u.memory_limit_bytes || (v.limits.memory * 1024 * 1024);
                    var memPercent = memLimit > 0 ? (u.memory_bytes / memLimit) * 100 : 0;
                    var cpuLimit = v.limits.cpu > 0 ? v.limits.cpu : 100;
                    var cpuPercent = (u.cpu_absolute || 0) / cpuLimit * 100;
                    var cpuHot = (u.cpu_absolute || 0) >= alertCpu;
                    var memHot = memPercent >= alertMemory;

                    html += '<tr class="' + (cpuHot || memHot ? 'logspterodactyl-row-warning' : '') + '">'
                        + '<td><strong>' + escapeHtml(v.name) + '</strong>'
                        + '<div class="logspterodactyl-muted logspterodactyl-small"><code>' + escapeHtml(v.address) + '</code></div></td>'
                        + '<td>' + escapeHtml(v.owner)
                        + '<div class="logspterodactyl-muted logspterodactyl-small">' + escapeHtml(v.owner_email) + '</div></td>'
                        + '<td>' + escapeHtml(v.node) + '</td>';

                    if (!v.reachable) {
                        html += '<td colspan="5"><span class="logspterodactyl-state logspterodactyl-state-warning">nodo sin respuesta</span></td></tr>';
                        continue;
                    }

                    html += '<td><span class="logspterodactyl-state logspterodactyl-state-'
                        + (v.online ? 'ok' : 'muted') + '">' + escapeHtml(u.state || 'offline') + '</span></td>'
                        + '<td>' + (Math.round((u.cpu_absolute || 0) * 10) / 10) + ' %'
                        + (v.limits.cpu > 0 ? ' <span class="logspterodactyl-muted logspterodactyl-small">de ' + v.limits.cpu + '%</span>' : '')
                        + bar(cpuPercent, cpuHot) + '</td>'
                        + '<td>' + bytes(u.memory_bytes) + ' <span class="logspterodactyl-muted logspterodactyl-small">de '
                        + (memLimit ? bytes(memLimit) : 'sin limite') + '</span>'
                        + bar(memPercent, memHot) + '</td>'
                        + '<td>' + bytes(u.disk_bytes) + '</td>'
                        + '<td class="logspterodactyl-small logspterodactyl-muted">' + bytes(u.network_rx_bytes) + ' entrada<br>'
                        + bytes(u.network_tx_bytes) + ' salida</td>'
                        + '</tr>';
                }

                live.innerHTML = html + '</tbody></table></div>';
            }

            function loadLive() {
                var node = document.getElementById('logspterodactyl-node').value;
                var xhr = new XMLHttpRequest();
                xhr.open('GET', liveUrl + (node ? '?node=' + encodeURIComponent(node) : ''), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function () {
                    if (xhr.status !== 200) { return; }
                    try { lastData = JSON.parse(xhr.responseText); } catch (e) { return; }
                    renderLive();
                    if (meta) { meta.textContent = 'actualizado ' + lastData.generated_at; }
                };

                xhr.send();
            }

            function loadTop() {
                var hours = document.getElementById('logspterodactyl-top-hours').value;
                var order = document.getElementById('logspterodactyl-top-order').value;

                var xhr = new XMLHttpRequest();
                xhr.open('GET', topUrl + '?hours=' + encodeURIComponent(hours) + '&order=' + encodeURIComponent(order), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function () {
                    if (xhr.status !== 200) { return; }
                    var data;
                    try { data = JSON.parse(xhr.responseText); } catch (e) { return; }

                    if (!data.rows.length) {
                        top.innerHTML = '<p class="logspterodactyl-empty">Todavia no hay muestras suficientes. '
                            + 'Se recogen una vez por minuto desde el cron del panel.</p>';
                        return;
                    }

                    var html = '<div class="table-responsive"><table class="table table-hover logspterodactyl-table">'
                        + '<thead><tr><th>#</th><th>Servidor</th><th>Cliente</th><th>Nodo</th>'
                        + '<th>CPU media</th><th>CPU maxima</th><th>RAM media</th><th>RAM maxima</th>'
                        + '<th>Disco</th><th>Muestras</th></tr></thead><tbody>';

                    for (var i = 0; i < data.rows.length; i++) {
                        var r = data.rows[i];
                        html += '<tr>'
                            + '<td class="logspterodactyl-muted">' + (i + 1) + '</td>'
                            + '<td><strong>' + escapeHtml(r.name) + '</strong></td>'
                            + '<td>' + escapeHtml(r.owner) + '<div class="logspterodactyl-muted logspterodactyl-small">'
                            + escapeHtml(r.owner_email) + '</div></td>'
                            + '<td>' + escapeHtml(r.node) + '</td>'
                            + '<td><strong>' + r.avg_cpu + ' %</strong></td>'
                            + '<td>' + r.max_cpu + ' %</td>'
                            + '<td>' + bytes(r.avg_memory) + '</td>'
                            + '<td>' + bytes(r.max_memory)
                            + (r.memory_limit ? ' <span class="logspterodactyl-muted logspterodactyl-small">de ' + bytes(r.memory_limit) + '</span>' : '')
                            + '</td>'
                            + '<td>' + bytes(r.max_disk) + '</td>'
                            + '<td class="logspterodactyl-muted">' + r.samples + '</td>'
                            + '</tr>';
                    }

                    top.innerHTML = html + '</tbody></table></div>';
                };

                xhr.send();
            }

            document.getElementById('logspterodactyl-refresh').addEventListener('click', loadLive);
            document.getElementById('logspterodactyl-node').addEventListener('change', loadLive);
            document.getElementById('logspterodactyl-filter').addEventListener('input', renderLive);
            document.getElementById('logspterodactyl-only-online').addEventListener('change', renderLive);
            document.getElementById('logspterodactyl-top-hours').addEventListener('change', loadTop);
            document.getElementById('logspterodactyl-top-order').addEventListener('change', loadTop);

            loadLive();
            loadTop();
            window.setInterval(function () { if (!document.hidden) { loadLive(); } }, 8000);
        })();
    </script>
@endsection
