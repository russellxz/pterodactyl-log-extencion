{{-- Avisos de la extension. No se usa Alert::* del panel para no mezclarse
     con los mensajes que muestra el propio panel en otras pantallas. --}}
@if(session('logspterodactyl_success'))
    <div class="row">
        <div class="col-xs-12">
            <div class="logspterodactyl-alert logspterodactyl-alert-ok">
                @logsicon('check-circle', 18)
                <span>{{ session('logspterodactyl_success') }}</span>
            </div>
        </div>
    </div>
@endif

@if(session('logspterodactyl_error'))
    <div class="row">
        <div class="col-xs-12">
            <div class="logspterodactyl-alert logspterodactyl-alert-error">
                @logsicon('alert', 18)
                <span>{{ session('logspterodactyl_error') }}</span>
            </div>
        </div>
    </div>
@endif
