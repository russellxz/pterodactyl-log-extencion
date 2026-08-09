@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'DNS de clientes')
@section('dnsreverse-heading', 'DNS creados por los clientes')
@section('dnsreverse-subheading', 'Pincha en un dominio para abrirlo y comprobar que responde')

@section('dnsreverse-content')

    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-stats dnsreverse-stats-small">
                <div class="dnsreverse-stat"><div><span class="dnsreverse-stat-number">{{ $stats['total'] }}</span><span class="dnsreverse-stat-label">En total</span></div></div>
                <div class="dnsreverse-stat"><div><span class="dnsreverse-stat-number">{{ $stats['subdomains'] }}</span><span class="dnsreverse-stat-label">Subdominios</span></div></div>
                <div class="dnsreverse-stat"><div><span class="dnsreverse-stat-number">{{ $stats['domains'] }}</span><span class="dnsreverse-stat-label">Dominios propios</span></div></div>
                <div class="dnsreverse-stat"><div><span class="dnsreverse-stat-number">{{ $stats['srv'] }}</span><span class="dnsreverse-stat-label">SRV Minecraft</span></div></div>
                <div class="dnsreverse-stat {{ $stats['orphans'] > 0 ? 'dnsreverse-stat-warn' : '' }}"><div><span class="dnsreverse-stat-number">{{ $stats['orphans'] }}</span><span class="dnsreverse-stat-label">Huerfanos</span></div></div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <form method="GET" class="dnsreverse-filters">
                        <input type="text" class="form-control" name="q" value="{{ $filtros['q'] }}" placeholder="Buscar dominio o servidor...">

                        <select class="form-control" name="type">
                            <option value="">Todos los tipos</option>
                            <option value="domain" {{ $filtros['type'] === 'domain' ? 'selected' : '' }}>Dominio propio</option>
                            <option value="subdomain" {{ $filtros['type'] === 'subdomain' ? 'selected' : '' }}>Subdominio</option>
                            <option value="srv" {{ $filtros['type'] === 'srv' ? 'selected' : '' }}>SRV Minecraft</option>
                        </select>

                        <select class="form-control" name="domain_id">
                            <option value="">Todos los dominios</option>
                            @foreach($domains as $dominio)
                                <option value="{{ $dominio->id }}" {{ (int) $filtros['domain_id'] === (int) $dominio->id ? 'selected' : '' }}>{{ $dominio->domain }}</option>
                            @endforeach
                        </select>

                        <select class="form-control" name="filter">
                            <option value="">Todos</option>
                            <option value="orphans" {{ $filtros['filter'] === 'orphans' ? 'selected' : '' }}>Solo huerfanos</option>
                        </select>

                        <button type="submit" class="btn btn-primary">@dnsicon('search', 14) Buscar</button>
                        <a href="{{ route('admin.dnsreverse.records') }}" class="btn btn-default">Limpiar</a>
                    </form>

                    {{-- Sin la clase "box-tools" a proposito: AdminLTE la coloca
                         en posicion absoluta y se montaria encima de los filtros. --}}
                    <div class="dnsreverse-toolbar">
                        <button type="button" class="btn btn-sm btn-default" onclick="dnsreverseSyncAll()">
                            @dnsicon('refresh', 14) Resincronizar todo
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="dnsreversePurge()">
                            @dnsicon('trash', 14) Purgar seleccionados
                        </button>
                    </div>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 32px;"><input type="checkbox" id="dnsreverseSelectAll"></th>
                                <th>Dominio</th>
                                <th>Tipo</th>
                                <th>Servidor</th>
                                <th>Cliente</th>
                                <th>Nodo / destino</th>
                                <th>Certificado</th>
                                <th>Creado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($records as $registro)
                            <tr>
                                <td><input type="checkbox" class="dnsreverse-check" value="{{ $registro->id }}"></td>
                                <td>
                                    @if($registro->proxy_type === 'srv')
                                        <strong>{{ $registro->domain }}</strong>
                                        <div class="text-muted small">registro SRV</div>
                                    @else
                                        <a href="{{ $registro->url() }}" target="_blank" rel="noopener noreferrer" class="dnsreverse-domain-link">
                                            <strong>{{ $registro->domain }}</strong> @dnsicon('external', 12)
                                        </a>
                                    @endif
                                    @if($registro->last_error)
                                        <div class="dnsreverse-inline-error">{{ $registro->last_error }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $registro->typeLabel() }}</td>
                                <td>
                                    @if($registro->server)
                                        <a href="{{ \Pterodactyl\Extensions\DnsReverse\Support\PanelLinks::server($registro->server->id) }}">{{ $registro->server->name }}</a>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-warn">huerfano</span>
                                    @endif
                                </td>
                                <td class="small">
                                    {{ optional(optional($registro->server)->user)->username ?? '-' }}
                                </td>
                                <td class="small">
                                    {{ optional(optional($registro->allocation)->node)->name ?? '-' }}
                                    @if($registro->allocation)
                                        <div class="text-muted">{{ $registro->allocation->ip }}:{{ $registro->allocation->port }}</div>
                                    @endif
                                </td>
                                <td class="small">{{ $registro->sslLabel() }}</td>
                                <td class="small">{{ optional($registro->created_at)->format('d/m/Y') ?? '-' }}</td>
                                <td class="text-right dnsreverse-actions">
                                    <form method="POST" action="{{ route('admin.dnsreverse.records.sync', $registro->id) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="btn btn-xs btn-default" title="Volver a mandar la configuracion al nodo">
                                            @dnsicon('refresh', 12)
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.dnsreverse.records.delete', $registro->id) }}" style="display:inline;"
                                          onsubmit="return confirm('¿Borrar {{ $registro->domain }}? Se quita de Cloudflare y del nodo.');">
                                        @csrf
                                        <button type="submit" class="btn btn-xs btn-danger" title="Borrar este DNS">
                                            @dnsicon('trash', 12)
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted" style="padding: 24px;">
                                    No hay ningun DNS con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if($records instanceof \Illuminate\Contracts\Pagination\Paginator)
                    <div class="box-footer">{{ $records->links() }}</div>
                @endif
            </div>
        </div>
    </div>

@endsection

@section('dnsreverse-scripts')
<script>
(function () {
    var todos = document.getElementById('dnsreverseSelectAll');
    if (todos) {
        todos.addEventListener('change', function () {
            document.querySelectorAll('.dnsreverse-check').forEach(function (c) { c.checked = todos.checked; });
        });
    }
})();

function dnsreverseSeleccionados() {
    return Array.prototype.slice.call(document.querySelectorAll('.dnsreverse-check:checked'))
        .map(function (c) { return c.value; });
}

function dnsreversePurge() {
    var ids = dnsreverseSeleccionados();

    if (ids.length === 0) {
        alert('Selecciona al menos un DNS.');
        return;
    }

    if (!confirm('Se van a borrar ' + ids.length + ' DNS: registro en Cloudflare, configuracion en el nodo y ficha en el panel. ¿Seguir?')) {
        return;
    }

    fetch('{{ route('admin.dnsreverse.records.purge') }}', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids: ids })
    }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) {
            alert('Error: ' + (d.error || 'desconocido'));
            return;
        }

        var texto = 'Borrados: ' + d.purged + '. Fallidos: ' + d.failed + '.';
        if (d.warnings && d.warnings.length) {
            texto += '\n\n' + d.warnings.join('\n');
        }
        alert(texto);
        window.location.reload();
    }).catch(function (e) { alert('No se pudo purgar: ' + e); });
}

function dnsreverseSyncAll() {
    if (!confirm('Se va a volver a mandar la configuracion de TODOS los DNS a sus nodos. No se borra nada y los certificados que sigan validos se reutilizan. ¿Seguir?')) {
        return;
    }

    fetch('{{ route('admin.dnsreverse.records.syncall') }}', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
        body: JSON.stringify({})
    }).then(function (r) { return r.json(); }).then(function (d) {
        var texto = 'Resincronizados: ' + d.synced + '.';
        if (d.failed && d.failed.length) {
            texto += '\n\nCon problemas:\n' + d.failed.join('\n');
        }
        alert(texto);
        window.location.reload();
    }).catch(function (e) { alert('No se pudo resincronizar: ' + e); });
}
</script>
@endsection
