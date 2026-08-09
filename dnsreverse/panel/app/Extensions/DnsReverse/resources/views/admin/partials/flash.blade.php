{{-- Avisos de la extension, con su propio estilo para no mezclarse con los
     mensajes que el panel muestra en otras pantallas. --}}
@if(session('dnsreverse_success'))
    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-alert dnsreverse-alert-ok">
                @dnsicon('check-circle', 18)
                <span>{{ session('dnsreverse_success') }}</span>
            </div>
        </div>
    </div>
@endif

@if(session('dnsreverse_error'))
    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-alert dnsreverse-alert-error">
                @dnsicon('alert', 18)
                <span>{{ session('dnsreverse_error') }}</span>
            </div>
        </div>
    </div>
@endif

@if($errors->any())
    <div class="row">
        <div class="col-xs-12">
            <div class="dnsreverse-alert dnsreverse-alert-error">
                @dnsicon('alert', 18)
                <span>
                    @foreach($errors->all() as $error)
                        {{ $error }}@if(!$loop->last) <br>@endif
                    @endforeach
                </span>
            </div>
        </div>
    </div>
@endif
