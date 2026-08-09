{{--
    Base de todas las pantallas de la extension.

    Extiende layouts.admin, que es el layout de administracion del panel. Si
    hay tema Arix instalado, ese archivo es el suyo y estas pantallas heredan
    su aspecto automaticamente; si no lo hay, se ve con el AdminLTE de siempre.
    En ninguno de los dos casos se toca el archivo del layout.
--}}
@extends('layouts.admin')

@section('title')
    LogsPterodactyl | @yield('logspterodactyl-title')
@endsection

@section('content-header')
    <h1>
        @yield('logspterodactyl-heading')
        <small>@yield('logspterodactyl-subheading')</small>
    </h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.logspterodactyl.index') }}">LogsPterodactyl</a></li>
        <li class="active">@yield('logspterodactyl-title')</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <nav class="logspterodactyl-tabs">
                @php
                    $tabs = [
                        ['route' => 'admin.logspterodactyl.index', 'label' => 'Resumen', 'icon' => 'gauge'],
                        ['route' => 'admin.logspterodactyl.logs', 'label' => 'Errores del panel', 'icon' => 'bug'],
                        ['route' => 'admin.logspterodactyl.installs', 'label' => 'Instalaciones', 'icon' => 'server'],
                        ['route' => 'admin.logspterodactyl.resources', 'label' => 'Consumo', 'icon' => 'activity'],
                        ['route' => 'admin.logspterodactyl.mails', 'label' => 'Correos', 'icon' => 'mail'],
                        ['route' => 'admin.logspterodactyl.compose', 'label' => 'Enviar correo', 'icon' => 'send'],
                        ['route' => 'admin.logspterodactyl.updater', 'label' => 'Actualizar panel', 'icon' => 'upload-cloud'],
                        ['route' => 'admin.logspterodactyl.events', 'label' => 'Registro', 'icon' => 'file-text'],
                        ['route' => 'admin.logspterodactyl.settings', 'label' => 'Configuracion', 'icon' => 'settings'],
                    ];
                    $currentRoute = (string) Route::currentRouteName();
                @endphp

                @foreach($tabs as $tab)
                    <a href="{{ route($tab['route']) }}"
                       class="logspterodactyl-tab {{ str_starts_with($currentRoute, $tab['route']) ? 'active' : '' }}">
                        @logsicon($tab['icon'], 15)
                        <span>{{ $tab['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </div>
    </div>

    @include('logspterodactyl::admin.partials.flash')

    @yield('logspterodactyl-content')
@endsection

@section('footer-scripts')
    @parent
    @yield('logspterodactyl-scripts')
@endsection
