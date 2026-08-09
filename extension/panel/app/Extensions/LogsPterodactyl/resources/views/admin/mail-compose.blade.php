@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Enviar correo')
@section('logspterodactyl-heading', 'Enviar correo a los clientes')
@section('logspterodactyl-subheading', 'a todos, a uno o a los que elijas')

@section('logspterodactyl-content')
    @unless($logEnabled)
        <div class="row">
            <div class="col-xs-12">
                <div class="logspterodactyl-alert logspterodactyl-alert-warning">
                    @logsicon('alert', 18)
                    <span>
                        El registro de correos esta desactivado, asi que estos envios se mandaran
                        pero no quedaran anotados y no podras reenviarlos ni ver si fallaron.
                        Se activa en <a href="{{ route('admin.logspterodactyl.settings') }}">Configuracion</a>.
                    </span>
                </div>
            </div>
        </div>
    @endunless

    <form method="POST" action="{{ route('admin.logspterodactyl.compose.send') }}" id="lp-form">
        {{ csrf_field() }}

        <div class="row">
            <div class="col-md-7">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Mensaje</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label for="subject">Asunto</label>
                            <input type="text" name="subject" id="subject" class="form-control" maxlength="200"
                                   required value="{{ old('subject') }}" placeholder="Mantenimiento programado del servidor">
                        </div>

                        <div class="form-group">
                            <label for="body">Contenido (admite HTML)</label>
                            @php
                                // Se construye aparte a proposito: los marcadores llevan llaves
                                // dobles y dentro de una expresion de Blade cerrarian el echo.
                                $marcadorNombre = '{' . '{nombre}' . '}';
                                $marcadorPanel = '{' . '{panel}' . '}';
                                $cuerpoPorDefecto = old('body', "<p>Hola {$marcadorNombre},</p>\n\n<p>Escribe aqui tu mensaje.</p>\n\n<p>Un saludo,<br>El equipo de {$marcadorPanel}</p>");
                            @endphp
                            <textarea name="body" id="body" class="form-control logspterodactyl-editor"
                                      rows="14" required>{{ $cuerpoPorDefecto }}</textarea>
                            <p class="text-muted logspterodactyl-small" style="margin-top:6px;">
                                Puedes usar etiquetas HTML normales: <code>&lt;p&gt;</code>, <code>&lt;strong&gt;</code>,
                                <code>&lt;a href="..."&gt;</code>, <code>&lt;ul&gt;</code>, <code>&lt;img src="..."&gt;</code>...
                            </p>
                        </div>

                        <div class="logspterodactyl-note logspterodactyl-note-info">
                            @logsicon('user', 16)
                            <span>
                                Marcadores que se sustituyen en cada correo:
                                <code>@{{nombre}}</code> (nombre del cliente),
                                <code>@{{correo}}</code> (su direccion) y
                                <code>@{{panel}}</code> (nombre del panel).
                            </span>
                        </div>

                        <div class="logspterodactyl-toolbar">
                            <button type="button" class="btn btn-sm btn-default" id="lp-preview">
                                @logsicon('search', 14) Ver como queda
                            </button>
                            <span class="logspterodactyl-muted logspterodactyl-small" id="lp-count"></span>
                        </div>

                        <iframe id="lp-preview-frame" class="logspterodactyl-mail-preview" hidden
                                sandbox="" title="Vista previa del correo"></iframe>
                    </div>
                </div>

                <div class="box box-danger">
                    <div class="box-header with-border">
                        <h3 class="box-title">Enviar</h3>
                    </div>
                    <div class="box-body">
                        <label class="logspterodactyl-switch">
                            <input type="checkbox" name="confirm" value="1" required>
                            <span id="lp-confirm-label">Confirmo que quiero enviar este correo</span>
                        </label>

                        <p class="text-muted logspterodactyl-small">
                            Los correos se mandan de uno en uno, asi ningun cliente ve la direccion de
                            los demas. Un envio grande tarda: no cierres esta pestana hasta que termine.
                        </p>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary" id="lp-send">
                            @logsicon('send', 14) <span>Enviar</span>
                        </button>
                        <a href="{{ route('admin.logspterodactyl.mails') }}" class="btn btn-default">Volver al registro</a>
                    </div>
                </div>
            </div>

            <div class="col-md-5">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Destinatarios</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="logspterodactyl-switch">
                                <input type="radio" name="audience" value="all" id="aud-all"
                                       @if(old('audience') === 'all') checked @endif>
                                <span><strong>Todos los clientes</strong> ({{ $totalUsers }} cuentas)</span>
                            </label>
                            <label class="logspterodactyl-switch">
                                <input type="radio" name="audience" value="one" id="aud-one"
                                       @if(old('audience') === 'one') checked @endif>
                                <span><strong>Una sola direccion</strong></span>
                            </label>
                            <label class="logspterodactyl-switch">
                                <input type="radio" name="audience" value="selected" id="aud-selected"
                                       @if(old('audience', 'selected') === 'selected') checked @endif>
                                <span><strong>Elegir clientes</strong></span>
                            </label>
                        </div>

                        <div class="form-group" id="lp-one-box" hidden>
                            <label for="email">Direccion de correo</label>
                            <input type="email" name="email" id="email" class="form-control"
                                   value="{{ old('email') }}" placeholder="cliente@ejemplo.com">
                        </div>

                        <div id="lp-selected-box">
                            <div class="form-group">
                                <label for="lp-search">Buscar cliente</label>
                                <input type="text" id="lp-search" class="form-control"
                                       placeholder="nombre, usuario o correo" autocomplete="off">
                            </div>

                            <div id="lp-results" class="logspterodactyl-picker"></div>

                            <div class="form-group">
                                <label>Seleccionados (<span id="lp-selected-count">0</span>)</label>
                                <div id="lp-chips" class="logspterodactyl-chips">
                                    <span class="logspterodactyl-muted logspterodactyl-small">Todavia no has elegido a nadie.</span>
                                </div>
                            </div>
                        </div>

                        <div id="lp-hidden-users"></div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Logo de los correos</h3>
                    </div>
                    <div class="box-body">
                        @if($logoUrl)
                            <div class="logspterodactyl-logo-preview">
                                <img src="{{ $logoUrl }}" alt="Logo actual">
                            </div>
                        @else
                            <p class="text-muted">
                                No hay logo. Los correos muestran el nombre del panel en la cabecera.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Ultimos envios</h3>
                    </div>
                    <div class="box-body no-padding">
                        @if($campaigns->isEmpty())
                            <p class="logspterodactyl-empty">Todavia no has enviado ninguno.</p>
                        @else
                            <table class="table logspterodactyl-table">
                                <thead><tr><th>Asunto</th><th>Enviados</th><th>Fallidos</th><th>Fecha</th></tr></thead>
                                <tbody>
                                @foreach($campaigns as $campaign)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Str::limit($campaign->subject, 34) }}</td>
                                        <td><strong>{{ $campaign->sent }}</strong> / {{ $campaign->total }}</td>
                                        <td>
                                            @if($campaign->failed > 0)
                                                <span class="logspterodactyl-state logspterodactyl-state-danger">{{ $campaign->failed }}</span>
                                            @else
                                                0
                                            @endif
                                        </td>
                                        <td class="logspterodactyl-small">{{ optional($campaign->created_at)->format('d/m H:i') }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- Formulario del logo aparte: no puede ir dentro del de arriba --}}
    <div class="row">
        <div class="col-md-5 col-md-offset-7">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Cambiar el logo</h3>
                </div>
                <form method="POST" action="{{ route('admin.logspterodactyl.compose.logo') }}" enctype="multipart/form-data">
                    {{ csrf_field() }}
                    <div class="box-body">
                        <div class="form-group">
                            <label for="logo">Imagen (JPG, PNG, GIF o WEBP, maximo 2 MB)</label>
                            <input type="file" name="logo" id="logo" class="form-control" accept="image/*" required>
                            <p class="text-muted logspterodactyl-small" style="margin-top:6px;">
                                Se vera a un maximo de 190 px de ancho en la cabecera del correo.
                                Un PNG con fondo transparente queda bien en todos los clientes de correo.
                            </p>
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary">@logsicon('upload-cloud', 14) Subir logo</button>
                        @if($logoUrl)
                            <button type="submit" name="remove" value="1" class="btn btn-default"
                                    formnovalidate onclick="return confirm('¿Quitar el logo actual?');">
                                Quitar logo
                            </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('logspterodactyl-scripts')
    <script>
        (function () {
            'use strict';

            var searchUrl = @json(route('admin.logspterodactyl.compose.search'));
            var previewUrl = @json(route('admin.logspterodactyl.compose.preview'));
            var token = @json(csrf_token());
            var totalUsers = {{ $totalUsers }};

            var seleccionados = {};
            var searchTimer = null;

            function el(id) { return document.getElementById(id); }

            function escapeHtml(text) {
                return String(text === null || text === undefined ? '' : text)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            // --- Modo de destinatarios ---

            function modo() {
                var marcado = document.querySelector('input[name="audience"]:checked');
                return marcado ? marcado.value : 'selected';
            }

            function refrescarModo() {
                var actual = modo();
                el('lp-one-box').hidden = actual !== 'one';
                el('lp-selected-box').hidden = actual !== 'selected';
                el('email').required = actual === 'one';
                actualizarContador();
            }

            function actualizarContador() {
                var actual = modo();
                var n = actual === 'all' ? totalUsers
                    : (actual === 'one' ? 1 : Object.keys(seleccionados).length);

                var texto = actual === 'all'
                    ? 'Se enviara a los ' + totalUsers + ' clientes del panel.'
                    : (actual === 'one'
                        ? 'Se enviara a una sola direccion.'
                        : 'Se enviara a ' + n + ' cliente(s).');

                el('lp-count').textContent = texto;
                el('lp-confirm-label').textContent = 'Confirmo que quiero enviar este correo. ' + texto;
                el('lp-send').disabled = (actual === 'selected' && n === 0);
            }

            var radios = document.querySelectorAll('input[name="audience"]');
            for (var r = 0; r < radios.length; r++) {
                radios[r].addEventListener('change', refrescarModo);
            }

            // --- Selector de clientes ---

            function pintarChips() {
                var contenedor = el('lp-chips');
                var ids = Object.keys(seleccionados);
                el('lp-selected-count').textContent = ids.length;

                if (!ids.length) {
                    contenedor.innerHTML = '<span class="logspterodactyl-muted logspterodactyl-small">Todavia no has elegido a nadie.</span>';
                } else {
                    var html = '';
                    for (var i = 0; i < ids.length; i++) {
                        var u = seleccionados[ids[i]];
                        html += '<span class="logspterodactyl-chip-user" data-id="' + escapeHtml(ids[i]) + '">'
                            + escapeHtml(u.name) + ' <small>' + escapeHtml(u.email) + '</small>'
                            + '<button type="button" aria-label="Quitar">&times;</button></span>';
                    }
                    contenedor.innerHTML = html;

                    var botones = contenedor.querySelectorAll('button');
                    for (var b = 0; b < botones.length; b++) {
                        botones[b].addEventListener('click', function () {
                            delete seleccionados[this.parentNode.getAttribute('data-id')];
                            pintarChips();
                        });
                    }
                }

                // Campos ocultos que viajan con el formulario
                var ocultos = '';
                for (var k = 0; k < ids.length; k++) {
                    ocultos += '<input type="hidden" name="users[]" value="' + escapeHtml(ids[k]) + '">';
                }
                el('lp-hidden-users').innerHTML = ocultos;

                actualizarContador();
            }

            function buscar() {
                var termino = el('lp-search').value;
                var xhr = new XMLHttpRequest();
                xhr.open('GET', searchUrl + '?q=' + encodeURIComponent(termino), true);
                xhr.setRequestHeader('Accept', 'application/json');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function () {
                    if (xhr.status !== 200) { return; }
                    var data;
                    try { data = JSON.parse(xhr.responseText); } catch (e) { return; }

                    if (!data.users.length) {
                        el('lp-results').innerHTML = '<p class="logspterodactyl-empty">Ningun cliente coincide.</p>';
                        return;
                    }

                    var html = '';
                    for (var i = 0; i < data.users.length; i++) {
                        var u = data.users[i];
                        var puesto = Object.prototype.hasOwnProperty.call(seleccionados, String(u.id));
                        html += '<button type="button" class="logspterodactyl-picker-item' + (puesto ? ' activo' : '') + '"'
                            + ' data-id="' + u.id + '"'
                            + ' data-name="' + escapeHtml(u.name) + '"'
                            + ' data-email="' + escapeHtml(u.email) + '">'
                            + '<span>' + escapeHtml(u.name) + '</span>'
                            + '<small>' + escapeHtml(u.email) + '</small></button>';
                    }
                    el('lp-results').innerHTML = html;

                    var items = el('lp-results').querySelectorAll('.logspterodactyl-picker-item');
                    for (var j = 0; j < items.length; j++) {
                        items[j].addEventListener('click', function () {
                            var id = this.getAttribute('data-id');
                            if (Object.prototype.hasOwnProperty.call(seleccionados, id)) {
                                delete seleccionados[id];
                                this.classList.remove('activo');
                            } else {
                                seleccionados[id] = {
                                    name: this.getAttribute('data-name'),
                                    email: this.getAttribute('data-email')
                                };
                                this.classList.add('activo');
                            }
                            pintarChips();
                        });
                    }
                };

                xhr.send();
            }

            el('lp-search').addEventListener('input', function () {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(buscar, 280);
            });

            el('lp-search').addEventListener('focus', function () {
                if (!el('lp-results').innerHTML) { buscar(); }
            });

            // --- Vista previa ---

            el('lp-preview').addEventListener('click', function () {
                var marco = el('lp-preview-frame');
                marco.hidden = false;

                // Se manda por POST porque el cuerpo puede ser largo y llevar
                // caracteres que no caben bien en una URL.
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = previewUrl;
                form.target = 'lp-preview-frame';
                form.style.display = 'none';
                form.innerHTML = '<input type="hidden" name="_token" value="' + escapeHtml(token) + '">';

                var campo = document.createElement('input');
                campo.type = 'hidden';
                campo.name = 'body';
                campo.value = el('body').value;
                form.appendChild(campo);

                marco.setAttribute('name', 'lp-preview-frame');
                document.body.appendChild(form);
                form.submit();
                document.body.removeChild(form);
            });

            // --- Aviso al enviar a todos ---

            el('lp-form').addEventListener('submit', function (event) {
                if (modo() === 'all') {
                    var texto = 'Vas a enviar este correo a los ' + totalUsers + ' clientes del panel. ¿Seguro?';
                    if (!window.confirm(texto)) {
                        event.preventDefault();
                        return;
                    }
                }

                var boton = el('lp-send');
                boton.disabled = true;
                boton.querySelector('span').textContent = 'Enviando, no cierres la pagina...';
            });

            refrescarModo();
            pintarChips();
        })();
    </script>
@endsection
