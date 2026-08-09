@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Registro')
@section('dnsreverse-heading', 'Registro de la extension')
@section('dnsreverse-subheading', 'Quien creo o borro cada DNS y cuando')

@section('dnsreverse-content')

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <form method="GET" class="dnsreverse-filters">
                        <input type="text" class="form-control" name="q" value="{{ $filtros['q'] }}" placeholder="Buscar dominio, accion o texto...">
                        <select class="form-control" name="level">
                            <option value="">Todo</option>
                            <option value="info" {{ $filtros['level'] === 'info' ? 'selected' : '' }}>Informacion</option>
                            <option value="warning" {{ $filtros['level'] === 'warning' ? 'selected' : '' }}>Avisos</option>
                            <option value="error" {{ $filtros['level'] === 'error' ? 'selected' : '' }}>Errores</option>
                        </select>
                        <button type="submit" class="btn btn-primary">@dnsicon('search', 14) Buscar</button>
                        <a href="{{ route('admin.dnsreverse.events') }}" class="btn btn-default">Limpiar</a>
                    </form>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 160px;">Cuando</th>
                                <th style="width: 120px;">Accion</th>
                                <th>Que paso</th>
                                <th>Dominio</th>
                                <th>Quien</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($eventos as $evento)
                            <tr class="dnsreverse-level-{{ $evento->level }}">
                                <td class="small">{{ optional($evento->created_at)->format('d/m/Y H:i') }}</td>
                                <td class="small"><code>{{ $evento->action }}</code></td>
                                <td>
                                    {{ $evento->message }}
                                    @php $extra = $evento->decodedContext(); @endphp
                                    @if($extra)
                                        <div class="text-muted small dnsreverse-context">
                                            @foreach($extra as $clave => $valor)
                                                <span>{{ $clave }}: {{ is_scalar($valor) ? $valor : json_encode($valor, JSON_UNESCAPED_UNICODE) }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="small">{{ $evento->domain ?? '-' }}</td>
                                <td class="small">{{ $usuarios[$evento->user_id] ?? ($evento->user_id ? '#' . $evento->user_id : 'sistema') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding: 24px;">
                                    Todavia no hay nada registrado.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="box-footer">{{ $eventos->links() }}</div>
            </div>
        </div>
    </div>

@endsection
