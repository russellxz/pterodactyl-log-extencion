{{-- Avisos de la extension. No se usa Alert::* del panel para no mezclarse
     con los mensajes que muestra el propio panel en otras pantallas. --}}
@if(session('arixlog_success'))
    <div class="row">
        <div class="col-xs-12">
            <div class="arixlog-alert arixlog-alert-ok">
                @arixicon('check-circle', 18)
                <span>{{ session('arixlog_success') }}</span>
            </div>
        </div>
    </div>
@endif

@if(session('arixlog_error'))
    <div class="row">
        <div class="col-xs-12">
            <div class="arixlog-alert arixlog-alert-error">
                @arixicon('alert', 18)
                <span>{{ session('arixlog_error') }}</span>
            </div>
        </div>
    </div>
@endif
