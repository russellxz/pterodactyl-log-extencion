{{--
    Base de todas las pantallas de DNS Reverse.

    Extiende layouts.admin, que es el layout de administracion del panel. Si
    hay tema Arix instalado, ese archivo es el suyo y estas pantallas heredan
    su aspecto automaticamente; si no lo hay, se ve con el AdminLTE de
    siempre. En ninguno de los dos casos se toca el archivo del layout.

    Los iconos van como SVG en linea (@dnsicon) y no con clases de Font
    Awesome: Arix no carga Font Awesome, asi que un <i class="fa fa-..."> se
    queda en un hueco vacio. Con SVG se ve igual en los dos temas.
--}}
@extends('layouts.admin')

@section('title')
    DNS Reverse | @yield('dnsreverse-title')
@endsection

@section('content-header')
    <h1>
        @yield('dnsreverse-heading')
        <small>@yield('dnsreverse-subheading')</small>
    </h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.dnsreverse.index') }}">DNS Reverse</a></li>
        <li class="active">@yield('dnsreverse-title')</li>
    </ol>
@endsection

@section('content')
    {{--
        El css de la extension se carga AQUI, en su propia pantalla.

        Antes lo metia un middleware en todas las paginas del panel. Eso es
        inyectar, y es justo lo que causaba el conflicto con Cloudflare. Ahora
        no se toca ni una pagina que no sea de DNS Reverse.
    --}}
    <link rel="stylesheet" href="/extensions/dnsreverse/admin.css?v={{ \Pterodactyl\Extensions\DnsReverse\DnsReverseServiceProvider::VERSION }}">

    <div class="row">
        <div class="col-xs-12">
            <nav class="dnsreverse-tabs">
                @php
                    $tabs = [
                        ['route' => 'admin.dnsreverse.index', 'label' => 'Resumen', 'icon' => 'gauge'],
                        ['route' => 'admin.dnsreverse.domains', 'label' => 'Dominios', 'icon' => 'globe'],
                        ['route' => 'admin.dnsreverse.records', 'label' => 'DNS de clientes', 'icon' => 'cloud'],
                        ['route' => 'admin.dnsreverse.servers', 'label' => 'Limites', 'icon' => 'sliders'],
                        ['route' => 'admin.dnsreverse.eggs', 'label' => 'Tipos de servidor', 'icon' => 'server'],
                        ['route' => 'admin.dnsreverse.nodes', 'label' => 'Nodos', 'icon' => 'hard-drive'],
                        ['route' => 'admin.dnsreverse.events', 'label' => 'Registro', 'icon' => 'file-text'],
                        ['route' => 'admin.dnsreverse.settings', 'label' => 'Configuracion', 'icon' => 'settings'],
                    ];
                    $currentRoute = (string) Route::currentRouteName();
                @endphp

                @foreach($tabs as $tab)
                    <a href="{{ route($tab['route']) }}"
                       class="dnsreverse-tab {{ str_starts_with($currentRoute, $tab['route']) ? 'active' : '' }}">
                        @dnsicon($tab['icon'], 15)
                        <span>{{ $tab['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
    </div>

    @include('dnsreverse::admin.partials.flash')

    @yield('dnsreverse-content')
@endsection

@section('footer-scripts')
    @parent
    <script src="/extensions/dnsreverse/admin.js?v={{ \Pterodactyl\Extensions\DnsReverse\DnsReverseServiceProvider::VERSION }}"></script>
    @yield('dnsreverse-scripts')
@endsection
