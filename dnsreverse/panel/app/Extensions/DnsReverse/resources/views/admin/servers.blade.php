@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Limites')
@section('dnsreverse-heading', 'Cuantos DNS puede crear cada servidor')
@section('dnsreverse-subheading', 'Poner 0 bloquea a ese cliente sin borrarle lo que ya tiene')

@section('dnsreverse-content')

@if(!$disponible)
    <div class="dnsreverse-alert dnsreverse-alert-error">
        @dnsicon('alert', 18)
        <span>
            La columna <code>proxy_limit</code> no existe en la tabla de servidores.
            Ejecuta <code>php artisan dnsreverse:install</code> en el panel y vuelve a entrar.
        </span>
    </div>
@else

    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-notice dnsreverse-notice-info">
                <span class="dnsreverse-notice-icon">@dnsicon('sliders', 18)</span>
                <div class="dnsreverse-notice-body">
                    <strong>Como funciona el limite</strong>
                    <p>
                        Es el numero maximo de DNS que ese servidor puede tener a la vez.
                        Con <strong>0</strong> el cliente no puede crear ninguno nuevo, pero
                        <strong>los que ya tenia siguen funcionando</strong>: si quieres quitarselos
                        de verdad, borralos desde la pestana <em>DNS de clientes</em>.
                        Los servidores nuevos nacen con {{ $porDefecto }} por defecto (se cambia en Configuracion).
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <form method="GET" class="dnsreverse-filters">
                        <input type="text" class="form-control" name="q" value="{{ $filtros['q'] }}" placeholder="Buscar servidor, cliente o correo...">
                        <select class="form-control" name="filter">
                            <option value="">Todos</option>
                            <option value="blocked" {{ $filtros['filter'] === 'blocked' ? 'selected' : '' }}>Solo bloqueados (0)</option>
                            <option value="allowed" {{ $filtros['filter'] === 'allowed' ? 'selected' : '' }}>Solo con limite</option>
                        </select>
                        <button type="submit" class="btn btn-primary">@dnsicon('search', 14) Buscar</button>
                        <a href="{{ route('admin.dnsreverse.servers') }}" class="btn btn-default">Limpiar</a>
                    </form>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Servidor</th>
                                <th>Cliente</th>
                                <th>Nodo</th>
                                <th class="text-center">DNS usados</th>
                                <th style="width: 220px;">Limite</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($servers as $servidor)
                            @php $limite = (int) ($servidor->proxy_limit ?? 0); @endphp
                            <tr class="{{ $limite === 0 ? 'dnsreverse-row-off' : '' }}">
                                <td>
                                    <a href="{{ route('admin.servers.view', $servidor->id) }}">{{ $servidor->name }}</a>
                                    <div class="text-muted small">{{ $servidor->uuidShort }}</div>
                                </td>
                                <td class="small">{{ optional($servidor->user)->username ?? '-' }}</td>
                                <td class="small">{{ optional($servidor->node)->name ?? '-' }}</td>
                                <td class="text-center">{{ $usados[$servidor->id] ?? 0 }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.dnsreverse.servers.limit', $servidor->id) }}" class="dnsreverse-inline-form">
                                        @csrf
                                        <input type="number" class="form-control input-sm" name="proxy_limit"
                                               value="{{ $limite }}" min="0" max="100">
                                        <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
                                        {{-- Pone el campo a 0 y envia: asi solo viaja un valor y no hay
                                             ambiguedad sobre cual gana. --}}
                                        <button type="submit" class="btn btn-sm btn-danger"
                                                title="Bloquear: no podra crear mas DNS"
                                                onclick="this.form.proxy_limit.value = 0;">0</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding: 24px;">
                                    No hay servidores con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="box-footer">
                    {{ $servers->links() }}
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">Cambiar el limite en bloque</h3>
                </div>
                <form method="POST" action="{{ route('admin.dnsreverse.servers.bulk') }}"
                      onsubmit="return confirm('Se va a cambiar el limite de varios servidores a la vez. ¿Seguir?');">
                    @csrf
                    <div class="box-body">
                        <p class="text-muted small">
                            Justo despues de instalar suele interesar subir de golpe todos los servidores que
                            estaban a 0, que es como los dejaba la version anterior. Esto <strong>no borra
                            ningun DNS</strong>: solo cambia cuantos puede crear cada servidor.
                        </p>
                        <div class="form-group">
                            <label>A que servidores</label>
                            <select class="form-control" name="scope">
                                <option value="zero">Solo a los que estan a 0 (recomendado)</option>
                                <option value="all">A todos, pisando lo que hubiera puesto a mano</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Nuevo limite</label>
                            <input type="number" class="form-control" name="proxy_limit" value="{{ $porDefecto }}" min="0" max="100">
                        </div>
                    </div>
                    <div class="box-footer">
                        <button type="submit" class="btn btn-warning pull-right">Aplicar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

@endif

@endsection
