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
                        @foreach($nodos as $fila)
                            @php $estado = $fila['estado']; @endphp
                            <tr id="dnsreverse-node-{{ $fila['modelo']->id }}">
                                <td>
                                    <a href="{{ route('admin.nodes.view', $fila['modelo']->id) }}">{{ $fila['modelo']->name }}</a>
                                    <div class="text-muted small">{{ $fila['modelo']->fqdn }}</div>
                                </td>
                                <td>
                                    @if($estado['online'])
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">responde</span>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-error">sin respuesta</span>
                                    @endif
                                </td>
                                <td>
                                    @if($estado['version'] >= $esperada)
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">v{{ $estado['version'] }} al dia</span>
                                    @elseif($estado['version'] > 0)
                                        <span class="dnsreverse-pill dnsreverse-pill-warn">v{{ $estado['version'] }} antiguo</span>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-error">no instalado</span>
                                    @endif
                                    @if($estado['message'])
                                        <div class="text-muted small">{{ $estado['message'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if($estado['nginx'])
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">ok</span>
                                    @elseif($estado['version'] >= $esperada)
                                        <span class="dnsreverse-pill dnsreverse-pill-error">falta</span>
                                    @else
                                        <span class="text-muted small">-</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $fila['dns'] }}</td>
                                <td class="text-center">{{ count($estado['certs']) }}</td>
                                <td class="text-right">
                                    <button type="button" class="btn btn-xs btn-default"
                                            onclick="dnsreverseCheck({{ $fila['modelo']->id }})">
                                        @dnsicon('refresh', 12) Comprobar
                                    </button>
                                    @if($estado['version'] >= $esperada)
                                        <button type="button" class="btn btn-xs btn-primary"
                                                onclick="dnsreverseRenew({{ $fila['modelo']->id }})">
                                            @dnsicon('lock', 12) Renovar certificados
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
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
                        El script descarga el codigo de wings de la misma version que tienes instalada, le anade
                        el complemento, lo compila y reinicia el servicio. Antes de tocar nada guarda una copia
                        del wings actual, asi que si algo sale mal se vuelve atras en un comando.
                        <strong>No borra servidores ni configuraciones de nginx existentes.</strong>
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
function dnsreverseCheck(id) {
    fetch('{{ url('admin/dnsreverse/nodes') }}/' + id + '/check', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            alert('Complemento: v' + d.version + '\nnginx: ' + (d.nginx ? 'ok' : 'no')
                + '\nCertificados: ' + (d.certs ? d.certs.length : 0)
                + (d.message ? '\n\n' + d.message : ''));
            window.location.reload();
        })
        .catch(function (e) { alert('No se pudo comprobar: ' + e); });
}

function dnsreverseRenew(id) {
    if (!confirm('Se van a renovar los certificados automaticos que caduquen pronto en este nodo. Puede tardar un rato. ¿Seguir?')) {
        return;
    }

    fetch('{{ url('admin/dnsreverse/nodes') }}/' + id + '/renew', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    }).then(function (r) { return r.json(); }).then(function (d) {
        var texto = d.message || '';
        if (d.renewed && d.renewed.length) { texto += '\n\nRenovados:\n' + d.renewed.join('\n'); }
        if (d.failed && d.failed.length) { texto += '\n\nCon problemas:\n' + d.failed.join('\n'); }
        alert(texto || 'Sin cambios.');
    }).catch(function (e) { alert('No se pudo renovar: ' + e); });
}
</script>
@endsection
