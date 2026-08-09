@extends('arixlog::admin._layout')

@section('arixlog-title', 'Errores del panel')
@section('arixlog-heading', 'Errores del panel')
@section('arixlog-subheading', 'contenido de storage/logs')

@section('arixlog-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Filtros</h3>
                    <div class="box-tools">
                        <span class="arixlog-muted" id="arixlog-log-meta"></span>
                    </div>
                </div>
                <div class="box-body">
                    <div class="arixlog-filters">
                        <div class="arixlog-field">
                            <label for="arixlog-file">Archivo</label>
                            <select id="arixlog-file" class="form-control">
                                @forelse($files as $file)
                                    <option value="{{ $file['name'] }}" @if($file['name'] === $selected) selected @endif>
                                        {{ $file['name'] }} ({{ round($file['size'] / 1024, 1) }} KB)
                                    </option>
                                @empty
                                    <option value="">No hay archivos de log</option>
                                @endforelse
                            </select>
                        </div>

                        <div class="arixlog-field">
                            <label for="arixlog-level">Nivel</label>
                            <select id="arixlog-level" class="form-control">
                                <option value="">Todos</option>
                                @foreach($levels as $item)
                                    <option value="{{ $item }}" @if($item === $level) selected @endif>
                                        {{ ucfirst($item) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="arixlog-field arixlog-field-grow">
                            <label for="arixlog-search">Buscar en el mensaje o en la traza</label>
                            <input type="text" id="arixlog-search" class="form-control"
                                   value="{{ $search }}" placeholder="por ejemplo: SQLSTATE, wings, TokenMismatch">
                        </div>

                        <div class="arixlog-field arixlog-field-actions">
                            <button type="button" class="btn btn-primary" id="arixlog-refresh">
                                @arixicon('refresh', 14) <span>Actualizar</span>
                            </button>
                            <label class="arixlog-checkline">
                                <input type="checkbox" id="arixlog-auto"> Automatico
                            </label>
                        </div>
                    </div>

                    <div class="arixlog-filters" style="margin-top:6px;">
                        <label class="arixlog-checkline" title="Analiza mucho mas del archivo. Mas lento.">
                            <input type="checkbox" id="arixlog-deep"> Buscar en todo el archivo (mas lento)
                        </label>
                        <span class="arixlog-muted" style="margin-left:auto;">
                            Carpeta: <code>{{ $directory }}</code>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="arixlog-levelbar" id="arixlog-counts"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Entradas</h3>
                    <div class="box-tools">
                        <a href="#" class="btn btn-xs btn-default" id="arixlog-download">
                            @arixicon('download', 13) Descargar
                        </a>
                        <form method="POST" action="{{ route('admin.arixlog.logs.clear') }}"
                              style="display:inline-block;margin-left:4px;"
                              onsubmit="return confirm('Se vaciara el contenido de este archivo de log. ¿Continuar?');">
                            {{ csrf_field() }}
                            <input type="hidden" name="file" class="arixlog-file-input" value="{{ $selected }}">
                            <button type="submit" class="btn btn-xs btn-warning">@arixicon('trash', 13) Vaciar</button>
                        </form>
                        <form method="POST" action="{{ route('admin.arixlog.logs.delete') }}"
                              style="display:inline-block;margin-left:4px;"
                              onsubmit="return confirm('Se borrara el archivo entero. ¿Continuar?');">
                            {{ csrf_field() }}
                            <input type="hidden" name="file" class="arixlog-file-input" value="{{ $selected }}">
                            <button type="submit" class="btn btn-xs btn-danger">@arixicon('x', 13) Borrar archivo</button>
                        </form>
                    </div>
                </div>
                <div class="box-body no-padding">
                    <div id="arixlog-entries" class="arixlog-entries">
                        <p class="arixlog-empty">Cargando...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('arixlog-scripts')
    <script>
        (function () {
            'use strict';

            var dataUrl = @json(route('admin.arixlog.logs.data'));
            var downloadUrl = @json(route('admin.arixlog.logs.download'));
            var container = document.getElementById('arixlog-entries');
            var counts = document.getElementById('arixlog-counts');
            var meta = document.getElementById('arixlog-log-meta');
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
                return '?file=' + encodeURIComponent(value('arixlog-file'))
                    + '&level=' + encodeURIComponent(value('arixlog-level'))
                    + '&search=' + encodeURIComponent(value('arixlog-search'))
                    + '&deep=' + (checked('arixlog-deep') ? '1' : '0');
            }

            function syncFileInputs() {
                var file = value('arixlog-file');
                var inputs = document.querySelectorAll('.arixlog-file-input');
                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].value = file;
                }
                var link = document.getElementById('arixlog-download');
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
                    html += '<div class="arixlog-levelbar-item arixlog-level-' + key + '">'
                        + '<span class="arixlog-levelbar-value">' + n + '</span>'
                        + '<span class="arixlog-levelbar-label">' + LEVEL_LABEL[key] + '</span>'
                        + '</div>';
                }

                counts.innerHTML = html;
            }

            function render(data) {
                if (data.empty) {
                    container.innerHTML = '<p class="arixlog-empty">'
                        + escapeHtml(data.message || 'No hay entradas que coincidan con el filtro.')
                        + '</p>';
                    return;
                }

                var html = '';

                for (var i = 0; i < data.entries.length; i++) {
                    var e = data.entries[i];
                    var hasStack = e.stack && e.stack.length > 0;

                    html += '<article class="arixlog-entry arixlog-level-' + escapeHtml(e.level) + '">'
                        + '<header class="arixlog-entry-head"' + (hasStack ? ' data-toggle-stack="1"' : '') + '>'
                        + '<span class="arixlog-tag">' + escapeHtml(LEVEL_LABEL[e.level] || e.level_label) + '</span>'
                        + '<time class="arixlog-entry-date">' + escapeHtml(e.date) + '</time>'
                        + (e.exception ? '<span class="arixlog-chip">' + escapeHtml(e.exception) + '</span>' : '')
                        + (e.origin ? '<span class="arixlog-origin">' + escapeHtml(e.origin) + '</span>' : '')
                        + (hasStack ? '<span class="arixlog-expand">ver traza</span>' : '')
                        + '</header>'
                        + '<div class="arixlog-entry-message">' + escapeHtml(e.message) + '</div>'
                        + (hasStack ? '<pre class="arixlog-stack" hidden>' + escapeHtml(e.stack) + '</pre>' : '')
                        + '</article>';
                }

                container.innerHTML = html;

                var heads = container.querySelectorAll('[data-toggle-stack]');
                for (var j = 0; j < heads.length; j++) {
                    heads[j].addEventListener('click', function () {
                        var stack = this.parentNode.querySelector('.arixlog-stack');
                        var label = this.querySelector('.arixlog-expand');
                        if (!stack) { return; }
                        stack.hidden = !stack.hidden;
                        if (label) { label.textContent = stack.hidden ? 'ver traza' : 'ocultar traza'; }
                    });
                }
            }

            function load() {
                syncFileInputs();

                if (!value('arixlog-file')) {
                    container.innerHTML = '<p class="arixlog-empty">No hay ningun archivo de log todavia. Eso normalmente es buena senal.</p>';
                    return;
                }

                var xhr = new XMLHttpRequest();
                xhr.open('GET', dataUrl + query(), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function () {
                    if (xhr.status !== 200) {
                        container.innerHTML = '<p class="arixlog-empty">No se pudieron leer los registros (codigo ' + xhr.status + ').</p>';
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
                    container.innerHTML = '<p class="arixlog-empty">No se pudo contactar con el panel.</p>';
                };

                xhr.send();
            }

            document.getElementById('arixlog-refresh').addEventListener('click', load);
            document.getElementById('arixlog-file').addEventListener('change', load);
            document.getElementById('arixlog-level').addEventListener('change', load);
            document.getElementById('arixlog-deep').addEventListener('change', load);

            document.getElementById('arixlog-search').addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(load, 350);
            });

            document.getElementById('arixlog-auto').addEventListener('change', function () {
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
