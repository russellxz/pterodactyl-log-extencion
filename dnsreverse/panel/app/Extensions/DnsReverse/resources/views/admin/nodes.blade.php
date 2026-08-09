@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Nodos')
@section('dnsreverse-heading', 'Complemento de wings en los nodos')
@section('dnsreverse-subheading', 'wings de serie no sabe montar nginx ni pedir certificados')

@section('dnsreverse-content')

    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-notice dnsreverse-notice-info">
                <span class="dnsreverse-notice-icon">@dnsicon('hard-drive', 18)</span>
                <div class="dnsreverse-notice-body">
                    <strong>Que hace el complemento</strong>
                    <p>
                        Es un anadido al wings del nodo. Es quien escribe la configuracion de nginx del dominio,
                        guarda el certificado y pide los certificados automaticos de Let's Encrypt.
                        Sin el, el panel puede crear el registro en Cloudflare pero la pagina del cliente no
                        cargaria. Se instala una vez por nodo y se actualiza con el mismo comando.
                    </p>
                </div>
                <button type="button" class="btn btn-sm btn-primary" onclick="dnsreverseComprobarTodos()">
                    @dnsicon('refresh', 14) Comprobar todos
                </button>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Nodo</th>
                                <th>Estado</th>
                                <th>Complemento</th>
                                <th>nginx</th>
                                <th class="text-center">DNS</th>
                                <th class="text-center">Certificados</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($nodos as $fila)
                            @php $estado = $fila['estado']; @endphp
                            <tr data-nodo="{{ $fila['modelo']->id }}">
                                <td>
                                    <strong>{{ $fila['modelo']->name }}</strong>
                                    <div class="text-muted small">{{ $fila['modelo']->fqdn }}</div>
                                </td>

                                {{-- Las tres columnas siguientes las rellena el JavaScript
                                     cuando el nodo contesta. Si ya habia una comprobacion
                                     reciente guardada, se pinta de entrada. --}}
                                <td class="dnsreverse-col-estado">
                                    @if($estado === null)
                                        <span class="dnsreverse-pill">sin comprobar</span>
                                    @elseif($estado['online'])
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">responde</span>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-error">sin respuesta</span>
                                    @endif
                                </td>
                                <td class="dnsreverse-col-version">
                                    @if($estado === null)
                                        <span class="text-muted small">-</span>
                                    @elseif($estado['version'] >= $esperada)
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">v{{ $estado['version'] }} al dia</span>
                                    @elseif($estado['version'] > 0)
                                        <span class="dnsreverse-pill dnsreverse-pill-warn">v{{ $estado['version'] }} antiguo</span>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-error">no instalado</span>
                                    @endif

                                    @if($estado !== null && $estado['message'])
                                        <div class="text-muted small">{{ $estado['message'] }}</div>
                                    @endif
                                </td>
                                <td class="dnsreverse-col-nginx">
                                    @if($estado === null)
                                        <span class="text-muted small">-</span>
                                    @elseif($estado['nginx'])
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">ok</span>
                                    @elseif($estado['version'] >= $esperada)
                                        <span class="dnsreverse-pill dnsreverse-pill-error">falta</span>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>

                                <td class="text-center">{{ $fila['dns'] }}</td>
                                <td class="text-center dnsreverse-col-certs">
                                    {{ $estado === null ? '-' : count($estado['certs']) }}
                                </td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-xs btn-default"
                                            onclick="dnsreverseComprobar({{ $fila['modelo']->id }})">
                                        @dnsicon('refresh', 12) Comprobar
                                    </button>
                                    <button type="button" class="btn btn-xs btn-primary dnsreverse-boton-renovar"
                                            style="{{ ($estado !== null && $estado['version'] >= $esperada) ? '' : 'display:none;' }}"
                                            onclick="dnsreverseRenovar({{ $fila['modelo']->id }})">
                                        @dnsicon('lock', 12) Renovar certificados
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted" style="padding: 24px;">
                                    No hay nodos en el panel.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="box-footer">
                    <p class="text-muted small" style="margin: 0;">
                        El estado de cada nodo se comprueba desde tu navegador, de uno en uno. Un nodo apagado
                        solo retrasa su propia fila y no bloquea esta pantalla.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Instalar o actualizar el complemento en un nodo</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted">
                        Entra por SSH <strong>en el nodo</strong> (no en el panel) como root y ejecuta:
                    </p>
<pre class="dnsreverse-code">git clone https://github.com/russellxz/pterodactyl-log-extencion.git /opt/pterodactyl-log-extencion
sudo bash /opt/pterodactyl-log-extencion/dnsreverse/wings/install-wings.sh</pre>
                    <p class="text-muted small">
                        Si ya lo tienes clonado de antes, para actualizar:
                    </p>
<pre class="dnsreverse-code">cd /opt/pterodactyl-log-extencion && git pull
sudo bash dnsreverse/wings/install-wings.sh</pre>
                    <p class="text-muted small">
                        Si el script dice que <strong>no puede averiguar la version de wings</strong>, es porque ese
                        wings ya esta compilado a mano. Indicasela: usa la misma serie que tu panel
                        (panel 1.11.x &rarr; <code>v1.11.13</code>, 1.12.x &rarr; <code>v1.12.3</code>,
                        1.13.x &rarr; <code>v1.13.2</code>).
                    </p>
<pre class="dnsreverse-code">sudo bash dnsreverse/wings/install-wings.sh --version v1.12.3</pre>
                    <p class="text-muted small">
                        El script guarda una copia del wings actual antes de tocar nada, asi que volver atras es
                        un comando. <strong>No borra servidores ni configuraciones de nginx existentes.</strong>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Renovacion de certificados</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted">
                        Los certificados de Let's Encrypt duran 90 dias. El panel repasa todos los nodos cada
                        madrugada y renueva los que caducan en menos de <strong>{{ $renovarDias }} dias</strong>.
                        Para que eso funcione, el cron del panel tiene que estar puesto:
                    </p>
<pre class="dnsreverse-code">* * * * * php {{ base_path('artisan') }} schedule:run >> /dev/null 2>&1</pre>
                    <p class="text-muted small">
                        Tambien se puede lanzar a mano en cualquier momento:
                    </p>
<pre class="dnsreverse-code">cd {{ base_path() }} && php artisan dnsreverse:renew</pre>
                </div>
            </div>
        </div>
    </div>

@endsection

@section('dnsreverse-scripts')
<script>
(function () {
    'use strict';

    var BASE = @json(url('admin/dnsreverse/nodes'));
    var CSRF = @json(csrf_token());
    var ESPERADA = {{ $esperada }};

    function fila(id) {
        return document.querySelector('tr[data-nodo="' + id + '"]');
    }

    function pastilla(clase, texto) {
        return '<span class="dnsreverse-pill ' + clase + '">' + texto + '</span>';
    }

    function escapar(valor) {
        return String(valor === null || valor === undefined ? '' : valor)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function pintar(id, datos) {
        var tr = fila(id);

        if (!tr) {
            return;
        }

        tr.querySelector('.dnsreverse-col-estado').innerHTML = datos.online
            ? pastilla('dnsreverse-pill-ok', 'responde')
            : pastilla('dnsreverse-pill-error', 'sin respuesta');

        var version = datos.version >= ESPERADA
            ? pastilla('dnsreverse-pill-ok', 'v' + datos.version + ' al dia')
            : (datos.version > 0
                ? pastilla('dnsreverse-pill-warn', 'v' + datos.version + ' antiguo')
                : pastilla('dnsreverse-pill-error', 'no instalado'));

        if (datos.message) {
            version += '<div class="text-muted small">' + escapar(datos.message) + '</div>';
        }

        tr.querySelector('.dnsreverse-col-version').innerHTML = version;

        tr.querySelector('.dnsreverse-col-nginx').innerHTML = datos.nginx
            ? pastilla('dnsreverse-pill-ok', 'ok')
            : (datos.version >= ESPERADA ? pastilla('dnsreverse-pill-error', 'falta') : '<span class="text-muted small">-</span>');

        tr.querySelector('.dnsreverse-col-certs').textContent = (datos.certs || []).length;

        var boton = tr.querySelector('.dnsreverse-boton-renovar');
        if (boton) {
            boton.style.display = datos.version >= ESPERADA ? '' : 'none';
        }
    }

    function comprobando(id) {
        var tr = fila(id);
        if (tr) {
            tr.querySelector('.dnsreverse-col-estado').innerHTML = pastilla('', 'comprobando...');
        }
    }

    window.dnsreverseComprobar = function (id) {
        comprobando(id);

        return fetch(BASE + '/' + id + '/check', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { pintar(id, d); })
            .catch(function () {
                var tr = fila(id);
                if (tr) {
                    tr.querySelector('.dnsreverse-col-estado').innerHTML =
                        pastilla('dnsreverse-pill-error', 'no se pudo comprobar');
                }
            });
    };

    window.dnsreverseComprobarTodos = function () {
        var filas = document.querySelectorAll('tr[data-nodo]');

        // De uno en uno a proposito: si hay muchos nodos, lanzarlos todos a la
        // vez satura tanto al navegador como al panel.
        var i = 0;
        (function siguiente() {
            if (i >= filas.length) {
                return;
            }

            var id = filas[i].getAttribute('data-nodo');
            i++;
            window.dnsreverseComprobar(id).then(siguiente);
        })();
    };

    window.dnsreverseRenovar = function (id) {
        if (!window.confirm('Se van a renovar los certificados automaticos que caduquen pronto en este nodo. Puede tardar un rato. ¿Seguir?')) {
            return;
        }

        fetch(BASE + '/' + id + '/renew', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        }).then(function (r) { return r.json(); }).then(function (d) {
            var texto = d.message || '';
            if (d.renewed && d.renewed.length) { texto += '\n\nRenovados:\n' + d.renewed.join('\n'); }
            if (d.failed && d.failed.length) { texto += '\n\nCon problemas:\n' + d.failed.join('\n'); }
            window.alert(texto || 'Sin cambios.');
        }).catch(function (e) { window.alert('No se pudo renovar: ' + e); });
    };

    // Al entrar se comprueban solos los que no tengan dato reciente.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('tr[data-nodo]').forEach(function (tr) {
            if (tr.querySelector('.dnsreverse-col-estado').textContent.indexOf('sin comprobar') !== -1) {
                window.dnsreverseComprobar(tr.getAttribute('data-nodo'));
            }
        });
    });
})();
</script>
@endsection
