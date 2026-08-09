@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Tipos de servidor')
@section('dnsreverse-heading', 'Que DNS admite cada tipo de servidor')
@section('dnsreverse-subheading', 'Un Minecraft quiere SRV; una pagina web quiere dominio con certificado')

@section('dnsreverse-content')

@if(!$disponible)
    <div class="dnsreverse-alert dnsreverse-alert-error">
        @dnsicon('alert', 18)
        <span>
            La columna <code>proxy_mode</code> no existe en la tabla de eggs.
            Ejecuta <code>php artisan dnsreverse:install</code> en el panel y vuelve a entrar.
        </span>
    </div>
@else

    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-notice dnsreverse-notice-info">
                <span class="dnsreverse-notice-icon">@dnsicon('server', 18)</span>
                <div class="dnsreverse-notice-body">
                    <strong>Para que sirve esto</strong>
                    <p>
                        El cliente solo vera las opciones que tengan sentido para su servidor. Si un egg esta
                        en <em>Solo SRV de Minecraft</em>, quien tenga ese servidor no podra pedir un dominio
                        web; y si esta en <em>Desactivado</em>, no vera la seccion de DNS.
                        Cambiar esto <strong>no borra nada</strong> de lo ya creado.
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
                                <th>Tipo de servidor</th>
                                <th>Grupo</th>
                                <th style="width: 420px;">Que puede crear</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($eggs as $egg)
                            @php $modoActual = $egg->proxy_mode ?: 'normal'; @endphp
                            <tr class="{{ $modoActual === 'disabled' ? 'dnsreverse-row-off' : '' }}">
                                <td><strong>{{ $egg->name }}</strong></td>
                                <td class="small text-muted">{{ optional($egg->nest)->name ?? '-' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('admin.dnsreverse.eggs.update', $egg->id) }}" class="dnsreverse-inline-form">
                                        @csrf
                                        <select class="form-control input-sm" name="proxy_mode">
                                            @foreach($modos as $valor => $texto)
                                                <option value="{{ $valor }}" {{ $modoActual === $valor ? 'selected' : '' }}>{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

@endif

@endsection
