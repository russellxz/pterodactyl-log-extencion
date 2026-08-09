@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Configuracion')
@section('dnsreverse-heading', 'Configuracion de DNS Reverse')
@section('dnsreverse-subheading', 'Lo que afecta a todos los dominios')

@section('dnsreverse-content')

<form method="POST" action="{{ route('admin.dnsreverse.settings.update') }}">
    @csrf

    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Limites</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label>DNS que puede crear un servidor recien creado</label>
                        <input type="number" class="form-control" name="default_proxy_limit"
                               value="{{ $ajustes['default_proxy_limit'] }}" min="0" max="100">
                        <p class="text-muted small">
                            La version anterior dejaba todos los servidores a 0, asi que habia que entrar
                            servidor por servidor. Con 1 (o mas) cada servidor nuevo ya puede crear su DNS
                            sin que tengas que tocar nada.
                        </p>
                    </div>

                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="allow_custom_domains" value="1"
                                   {{ filter_var($ajustes['allow_custom_domains'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                            Permitir que los clientes traigan su propio dominio
                        </label>
                        <p class="text-muted small">
                            Si lo desmarcas, solo podran pedir subdominios de los dominios que tu hayas dado de alta.
                        </p>
                    </div>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Certificados automaticos (Let's Encrypt)</h3>
                </div>
                <div class="box-body">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="letsencrypt_enabled" value="1"
                                   {{ filter_var($ajustes['letsencrypt_enabled'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                            Ofrecer certificados automaticos a los clientes
                        </label>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="letsencrypt_auto_renew" value="1"
                                   {{ filter_var($ajustes['letsencrypt_auto_renew'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' }}>
                            Renovarlos solos antes de que caduquen
                        </label>
                        <p class="text-muted small">
                            Estos certificados duran 90 dias. Sin renovacion automatica, las paginas de tus
                            clientes empiezan a dar error de seguridad a los tres meses.
                        </p>
                    </div>
                    <div class="form-group">
                        <label>Renovar cuando falten menos de</label>
                        <div class="input-group">
                            <input type="number" class="form-control" name="letsencrypt_renew_days"
                                   value="{{ $ajustes['letsencrypt_renew_days'] }}" min="1" max="89">
                            <span class="input-group-addon">dias</span>
                        </div>
                        <p class="text-muted small">
                            21 dias es lo recomendado: da margen de sobra para reintentar si un dia falla.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Instrucciones para dominios propios</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label>Texto que ve el cliente</label>
                        <textarea class="form-control" name="dns_instructions" rows="4">{{ $ajustes['dns_instructions'] }}</textarea>
                        <p class="text-muted small">
                            Escribe <code>[ip]</code> donde quieras que aparezca la direccion a la que tiene que
                            apuntar. Se sustituye sola por la del servidor de cada cliente.
                        </p>
                    </div>
                    <div class="form-group">
                        <label>Que se pone en [ip]</label>
                        <select class="form-control" name="dns_instruction_source">
                            <option value="ip" {{ $ajustes['dns_instruction_source'] === 'ip' ? 'selected' : '' }}>La IP del nodo</option>
                            <option value="alias" {{ $ajustes['dns_instruction_source'] === 'alias' ? 'selected' : '' }}>El alias de la asignacion</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="box box-warning">
                <div class="box-header with-border">
                    <h3 class="box-title">Dominios prohibidos</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label>Nadie puede usar estos dominios</label>
                        <textarea class="form-control" name="blocked_domains" rows="3">{{ $ajustes['blocked_domains'] }}</textarea>
                        <p class="text-muted small">
                            Separados por comas. <strong>Ademas de estos</strong>, la extension bloquea siempre
                            el dominio del propio panel y el FQDN de todos los nodos, para que nadie pueda
                            secuestrarlos por accidente.
                        </p>
                    </div>

                    <p class="text-muted small">Bloqueados ahora mismo:</p>
                    <div class="dnsreverse-chips">
                        @foreach($bloqueados as $bloqueado)
                            <span class="dnsreverse-chip">{{ $bloqueado }}</span>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="box">
                <div class="box-body">
                    <p class="text-muted small" style="margin: 0;">
                        DNS Reverse v{{ $version }} &middot;
                        Comprobacion completa: <code>php artisan dnsreverse:doctor</code>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xs-12">
            <button type="submit" class="btn btn-primary">Guardar configuracion</button>
        </div>
    </div>
</form>

@endsection
