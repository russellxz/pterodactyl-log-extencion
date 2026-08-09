@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Errores del panel')
@section('logspterodactyl-heading', 'Errores del panel')
@section('logspterodactyl-subheading', 'contenido de storage/logs')

@section('logspterodactyl-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Filtros</h3>
                    <div class="box-tools">
                        <span class="logspterodactyl-muted" id="logspterodactyl-log-meta"></span>
                    </div>
                </div>
                <div class="box-body">
                    <div class="logspterodactyl-filters">
                        <div class="logspterodactyl-field">
                            <label for="logspterodactyl-file">Archivo</label>
                            <select id="logspterodactyl-file" class="form-control">
                                @forelse($files as $file)
                                    <option value="{{ $file['name'] }}" @if($file['name'] === $selected) selected @endif>
                                        {{ $file['name'] }} ({{ round($file['size'] / 1024, 1) }} KB)
                                    </option>
                                @empty
                                    <option value="">No hay archivos de log</option>
                                @endforelse
                            </select>
                        </div>

                        <div class="logspterodactyl-field">
                            <label for="logspterodactyl-level">Nivel</label>
                            <select id="logspterodactyl-level" class="form-control">
                                <option value="">Todos</option>
                                @foreach($levels as $item)
                                    <option value="{{ $item }}" @if($item === $level) selected @endif>
                                        {{ ucfirst($item) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="logspterodactyl-field logspterodactyl-field-grow">
                            <label for="logspterodactyl-search">Buscar en el mensaje o en la traza</label>
                            <input type="text" id="logspterodactyl-search" class="form-control"
                                   value="{{ $search }}" placeholder="por ejemplo: SQLSTATE, wings, TokenMismatch">
                        </div>

                        <div class="logspterodactyl-field logspterodactyl-field-actions">
                            <button type="button" class="btn btn-primary" id="logspterodactyl-refresh">
                                @logsicon('refresh', 14) <span>Actualizar</span>
                            </button>
                            <label class="logspterodactyl-checkline">
                                <input type="checkbox" id="logspterodactyl-auto"> Automatico
                            </label>
                        </div>
                    </div>

                    <div class="logspterodactyl-filters" style="margin-top:6px;">
                        <label class="logspterodactyl-checkline" title="Analiza mucho mas del archivo. Mas lento.">
                            <input type="checkbox" id="logspterodactyl-deep"> Buscar en todo el archivo (mas lento)
                        </label>
                        <span class="logspterodactyl-muted" style="margin-left:auto;">
                            Carpeta: <code>{{ $directory }}</code>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="logspterodactyl-levelbar" id="logspterodactyl-counts"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Entradas</h3>
                    <div class="box-tools">
                        <a href="#" class="btn btn-xs btn-default" id="logspterodactyl-download">
                            @logsicon('download', 13) Descargar
                        </a>
                        <form method="POST" action="{{ route('admin.logspterodactyl.logs.clear') }}"
                              style="display:inline-block;margin-left:4px;"
                              onsubmit="return confirm('Se vaciara el contenido de este archivo de log. ¿Continuar?');">
                            {{ csrf_field() }}
                            <input type="hidden" name="file" class="logspterodactyl-file-input" value="{{ $selected }}">
                            <button type="submit" class="btn btn-xs btn-warning">@logsicon('trash', 13) Vaciar</button>
                        </form>
                        <form method="POST" action="{{ route('admin.logspterodactyl.logs.delete') }}"
                              style="display:inline-block;margin-left:4px;"
                              onsubmit="return confirm('Se borrara el archivo entero. ¿Continuar?');">
                            {{ csrf_field() }}
                            <input type="hidden" name="file" class="logspterodactyl-file-input" value="{{ $selected }}">
                            <button type="submit" class="btn btn-xs btn-danger">@logsicon('x', 13) Borrar archivo</button>
                        </form>
                    </div>
                </div>
                <div class="box-body no-padding">
                    <div id="logspterodactyl-entries" class="logspterodactyl-entries">
                        <p class="logspterodactyl-empty">Cargando...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('logspterodactyl-scripts')
    <script>
        (function () {
            'use strict';

            var dataUrl = @json(route('admin.logspterodactyl.logs.data'));
            var downloadUrl = @json(route('admin.logspterodactyl.logs.download'));
            var container = document.getElementById('logspterodactyl-entries');
            var counts = document.getElementById('logspterodactyl-counts');
            var meta = document.getElementById('logspterodactyl-log-meta');
            var timer = null;
            var searchTimer = null;

            var LEVEL_LABEL = {
                emergency: 'Emergencia', alert: 'Alerta', critical: 'Critico', error: 'Error',
                warning: 'Aviso', notice: 'Nota', info: 'Info', debug: 'Depuracion'
            };

            function value(id) {
                var el = document.getElementById(id);
                return el ? el.value : '';
            }

            function checked(id) {
                var el = document.getElementById(id);
                return el ? el.checked : false;
            }

            function escapeHtml(text) {
                return String(text === null || text === undefined ? '' : text)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function query() {
                return '?file=' + encodeURIComponent(value('logspterodactyl-file'))
                    + '&level=' + encodeURIComponent(value('logspterodactyl-level'))
                    + '&search=' + encodeURIComponent(value('logspterodactyl-search'))
                    + '&deep=' + (checked('logspterodactyl-deep') ? '1' : '0');
            }

            function syncFileInputs() {
                var file = value('logspterodactyl-file');
                var inputs = document.querySelectorAll('.logspterodactyl-file-input');
                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].value = file;
                }
                var link = document.getElementById('logspterodactyl-download');
                if (link) {
                    link.setAttribute('href', downloadUrl + '?file=' + encodeURIComponent(file));
                }
            }

            function renderCounts(data) {
                if (!data.counts) {
                    counts.innerHTML = '';
                    return;
                }

                var order = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
                var html = '';

                for (var i = 0; i < order.length; i++) {
                    var key = order[i];
                    var n = data.counts[key] || 0;
                    if (n === 0 && ['notice', 'debug', 'alert', 'emergency'].indexOf(key) !== -1) {
                        continue;
                    }
                    html += '<div class="logspterodactyl-levelbar-item logspterodactyl-level-' + key + '">'
                        + '<span class="logspterodactyl-levelbar-value">' + n + '</span>'
                        + '<span class="logspterodactyl-levelbar-label">' + LEVEL_LABEL[key] + '</span>'
                        + '</div>';
                }

                counts.innerHTML = html;
            }

            function render(data) {
                if (data.empty) {
                    container.innerHTML = '<p class="logspterodactyl-empty">'
                        + escapeHtml(data.message || 'No hay entradas que coincidan con el filtro.')
                        + '</p>';
                    return;
                }

                var html = '';

                for (var i = 0; i < data.entries.length; i++) {
                    var e = data.entries[i];
                    var hasStack = e.stack && e.stack.length > 0;

                    html += '<article class="logspterodactyl-entry logspterodactyl-level-' + escapeHtml(e.level) + '">'
                        + '<header class="logspterodactyl-entry-head"' + (hasStack ? ' data-toggle-stack="1"' : '') + '>'
                        + '<span class="logspterodactyl-tag">' + escapeHtml(LEVEL_LABEL[e.level] || e.level_label) + '</span>'
                        + '<time class="logspterodactyl-entry-date">' + escapeHtml(e.date) + '</time>'
                        + (e.exception ? '<span class="logspterodactyl-chip">' + escapeHtml(e.exception) + '</span>' : '')
                        + (e.origin ? '<span class="logspterodactyl-origin">' + escapeHtml(e.origin) + '</span>' : '')
                        + (hasStack ? '<span class="logspterodactyl-expand">ver traza</span>' : '')
                        + '</header>'
                        + '<div class="logspterodactyl-entry-message">' + escapeHtml(e.message) + '</div>'
                        + (hasStack ? '<pre class="logspterodactyl-stack" hidden>' + escapeHtml(e.stack) + '</pre>' : '')
                        + '</article>';
                }

                container.innerHTML = html;

                var heads = container.querySelectorAll('[data-toggle-stack]');
                for (var j = 0; j < heads.length; j++) {
                    heads[j].addEventListener('click', function () {
                        var stack = this.parentNode.querySelector('.logspterodactyl-stack');
                        var label = this.querySelector('.logspterodactyl-expand');
                        if (!stack) { return; }
                        stack.hidden = !stack.hidden;
                        if (label) { label.textContent = stack.hidden ? 'ver traza' : 'ocultar traza'; }
                    });
                }
            }

            function load() {
                syncFileInputs();

                if (!value('logspterodactyl-file')) {
                    container.innerHTML = '<p class="logspterodactyl-empty">No hay ningun archivo de log todavia. Eso normalmente es buena senal.</p>';
                    return;
                }

                var xhr = new XMLHttpRequest();
                xhr.open('GET', dataUrl + query(), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function () {
                    if (xhr.status !== 200) {
                        container.innerHTML = '<p class="logspterodactyl-empty">No se pudieron leer los registros (codigo ' + xhr.status + ').</p>';
                        return;
                    }

                    var data;
                    try { data = JSON.parse(xhr.responseText); } catch (err) { return; }

                    render(data);
                    renderCounts(data);

                    if (meta) {
                        meta.textContent = data.size_human
                            ? ('archivo ' + data.size_human + (data.truncated ? ' - se analizaron los ultimos ' + data.bytes_read_human : ''))
                            : '';
                    }
                };

                xhr.onerror = function () {
                    container.innerHTML = '<p class="logspterodactyl-empty">No se pudo contactar con el panel.</p>';
                };

                xhr.send();
            }

            document.getElementById('logspterodactyl-refresh').addEventListener('click', load);
            document.getElementById('logspterodactyl-file').addEventListener('change', load);
            document.getElementById('logspterodactyl-level').addEventListener('change', load);
            document.getElementById('logspterodactyl-deep').addEventListener('change', load);

            document.getElementById('logspterodactyl-search').addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(load, 350);
            });

            document.getElementById('logspterodactyl-auto').addEventListener('change', function () {
                window.clearInterval(timer);
                if (this.checked) {
                    timer = window.setInterval(function () {
                        if (!document.hidden) { load(); }
                    }, 10000);
                }
            });

            load();
        })();
    </script>
@endsection
