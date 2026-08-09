@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Resumen')
@section('dnsreverse-heading', 'DNS Reverse')
@section('dnsreverse-subheading', 'Dominios y subdominios de tus clientes')

@section('dnsreverse-content')

    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-stats">
                <div class="dnsreverse-stat">
                    <span class="dnsreverse-stat-icon">@dnsicon('cloud', 20)</span>
                    <div>
                        <span class="dnsreverse-stat-number">{{ $stats['total'] }}</span>
                        <span class="dnsreverse-stat-label">DNS en total</span>
                    </div>
                </div>
                <div class="dnsreverse-stat">
                    <span class="dnsreverse-stat-icon">@dnsicon('globe', 20)</span>
                    <div>
                        <span class="dnsreverse-stat-number">{{ $stats['base_domains'] }}</span>
                        <span class="dnsreverse-stat-label">Dominios de la casa</span>
                    </div>
                </div>
                <div class="dnsreverse-stat">
                    <span class="dnsreverse-stat-icon">@dnsicon('lock', 20)</span>
                    <div>
                        <span class="dnsreverse-stat-number">{{ $stats['ssl'] }}</span>
                        <span class="dnsreverse-stat-label">Con certificado</span>
                    </div>
                </div>
                <div class="dnsreverse-stat {{ $stats['orphans'] > 0 ? 'dnsreverse-stat-warn' : '' }}">
                    <span class="dnsreverse-stat-icon">@dnsicon('trash', 20)</span>
                    <div>
                        <span class="dnsreverse-stat-number">{{ $stats['orphans'] }}</span>
                        <span class="dnsreverse-stat-label">Huerfanos</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if(count($avisos))
        <div class="row">
            <div class="col-xs-12">
                @foreach($avisos as $aviso)
                    <div class="dnsreverse-notice dnsreverse-notice-{{ $aviso['nivel'] }}">
                        <span class="dnsreverse-notice-icon">
                            @dnsicon($aviso['nivel'] === 'error' ? 'x-circle' : ($aviso['nivel'] === 'aviso' ? 'alert' : 'check-circle'), 18)
                        </span>
                        <div class="dnsreverse-notice-body">
                            <strong>{{ $aviso['titulo'] }}</strong>
                            <p>{{ $aviso['texto'] }}</p>
                        </div>
                        @if(!empty($aviso['enlace']))
                            <a href="{{ $aviso['enlace'] }}" class="btn btn-sm btn-primary">{{ $aviso['enlace_texto'] }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Dominios de la casa</h3>
                    <div class="box-tools">
                        <a href="{{ route('admin.dnsreverse.domains.new') }}" class="btn btn-sm btn-success">
                            @dnsicon('plus', 14) Anadir dominio
                        </a>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Dominio</th>
                                <th>Cloudflare</th>
                                <th>Certificado</th>
                                <th class="text-center">En uso</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($domains as $dominio)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.dnsreverse.domains.edit', $dominio->id) }}">{{ $dominio->domain }}</a>
                                    @if(!$dominio->active)
                                        <span class="dnsreverse-pill dnsreverse-pill-off">inactivo</span>
                                    @endif
                                </td>
                                <td>
                                    @if($dominio->hasToken())
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">token puesto</span>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-warn">sin token</span>
                                    @endif
                                </td>
                                <td>
                                    @if($dominio->hasOriginCertificate())
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">de origen</span>
                                    @elseif($dominio->allow_letsencrypt)
                                        <span class="dnsreverse-pill">Let's Encrypt</span>
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-warn">ninguno</span>
                                    @endif
                                </td>
                                <td class="text-center">{{ $dominio->proxies_count }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted">
                                    Todavia no hay dominios. Anade uno para que tus clientes puedan pedir subdominios.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Ultimos DNS creados</h3>
                    <div class="box-tools">
                        <a href="{{ route('admin.dnsreverse.records') }}" class="btn btn-sm btn-default">Ver todos</a>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Dominio</th>
                                <th>Servidor</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($recientes as $registro)
                            <tr>
                                <td>{{ $registro->domain }}</td>
                                <td>
                                    @if($registro->server)
                                        <a href="{{ \Pterodactyl\Extensions\DnsReverse\Support\PanelLinks::server($registro->server->id) }}">{{ $registro->server->name }}</a>
                                    @else
                                        <span class="text-muted">servidor borrado</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($registro->proxy_type !== 'srv')
                                        <a href="{{ $registro->url() }}" target="_blank" rel="noopener noreferrer"
                                           class="dnsreverse-link" title="Abrir en una pestana nueva">
                                            @dnsicon('external', 14)
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="text-center text-muted">Todavia no hay DNS creados.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Ultimos movimientos</h3>
                    <div class="box-tools">
                        <a href="{{ route('admin.dnsreverse.events') }}" class="btn btn-sm btn-default">Ver registro</a>
                    </div>
                </div>
                <div class="box-body no-padding">
                    <ul class="dnsreverse-feed">
                        @forelse($eventos as $evento)
                            <li class="dnsreverse-feed-{{ $evento->level }}">
                                <span class="dnsreverse-feed-time">{{ optional($evento->created_at)->diffForHumans() }}</span>
                                <span class="dnsreverse-feed-text">
                                    {{ $evento->message }}
                                    @if($evento->domain)<code>{{ $evento->domain }}</code>@endif
                                </span>
                            </li>
                        @empty
                            <li class="text-muted" style="padding: 12px 16px;">Sin movimientos todavia.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

@endsection
