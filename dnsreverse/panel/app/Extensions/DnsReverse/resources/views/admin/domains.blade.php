@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', 'Dominios')
@section('dnsreverse-heading', 'Dominios de la casa')
@section('dnsreverse-subheading', 'Cada dominio con su propio token de Cloudflare y su propio certificado')

@section('dnsreverse-content')

    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-notice dnsreverse-notice-info">
                <span class="dnsreverse-notice-icon">@dnsicon('key', 18)</span>
                <div class="dnsreverse-notice-body">
                    <strong>Un token por dominio</strong>
                    <p>
                        Cada dominio guarda su propio token de Cloudflare y su propio certificado de origen,
                        asi que puedes tener dominios repartidos en varias cuentas de Cloudflare sin que se
                        pisen entre ellos. El token se guarda cifrado y no se vuelve a mostrar.
                    </p>
                </div>
                <a href="{{ route('admin.dnsreverse.domains.new') }}" class="btn btn-sm btn-success">
                    @dnsicon('plus', 14) Anadir dominio
                </a>
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
                                <th>Dominio</th>
                                <th>Cloudflare</th>
                                <th>Certificado de origen</th>
                                <th>Permite</th>
                                <th>Nube</th>
                                <th class="text-center">DNS creados</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse($domains as $dominio)
                            <tr class="{{ $dominio->active ? '' : 'dnsreverse-row-off' }}">
                                <td>
                                    <strong>{{ $dominio->domain }}</strong>
                                    @if($dominio->label)
                                        <div class="text-muted small">{{ $dominio->label }}</div>
                                    @endif
                                    @if(!$dominio->active)
                                        <span class="dnsreverse-pill dnsreverse-pill-off">inactivo</span>
                                    @endif
                                </td>
                                <td>
                                    @if($dominio->hasToken())
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">token puesto</span>
                                        @if($dominio->cf_zone_id)
                                            <div class="text-muted small">zona comprobada</div>
                                        @endif
                                    @else
                                        <span class="dnsreverse-pill dnsreverse-pill-warn">sin token</span>
                                        <div class="text-muted small">los registros DNS habra que crearlos a mano</div>
                                    @endif
                                </td>
                                <td>
                                    @if($dominio->hasOriginCertificate())
                                        <span class="dnsreverse-pill dnsreverse-pill-ok">puesto</span>
                                    @else
                                        <span class="dnsreverse-pill">no</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @if($dominio->allow_subdomain)<div>Subdominios</div>@endif
                                    @if($dominio->allow_srv)<div>SRV de Minecraft</div>@endif
                                    @if($dominio->allow_letsencrypt)<div>Let's Encrypt</div>@endif
                                </td>
                                <td class="small">
                                    @switch($dominio->proxied_mode)
                                        @case('always') Siempre naranja @break
                                        @case('never') Siempre gris @break
                                        @default Automatica
                                    @endswitch
                                </td>
                                <td class="text-center">{{ $dominio->proxies_count }}</td>
                                <td class="text-right">
                                    <a href="{{ route('admin.dnsreverse.domains.edit', $dominio->id) }}" class="btn btn-xs btn-default">
                                        Editar
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted" style="padding: 24px;">
                                    Todavia no hay ningun dominio dado de alta.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

@endsection
