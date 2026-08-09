@extends('dnsreverse::admin._layout')

@section('dnsreverse-title', $nuevo ? 'Anadir dominio' : 'Editar dominio')
@section('dnsreverse-heading', $nuevo ? 'Anadir dominio' : $domain->domain)
@section('dnsreverse-subheading', 'Token de Cloudflare y certificado propios de este dominio')

@section('dnsreverse-content')

<form method="POST"
      action="{{ $nuevo ? route('admin.dnsreverse.domains.store') : route('admin.dnsreverse.domains.update', $domain->id) }}"
      id="dnsreverseDomainForm">
    @csrf

    <div class="row">
        <div class="col-md-7">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Datos del dominio</h3>
                </div>
                <div class="box-body">
                    <div class="form-group">
                        <label>Dominio</label>
                        <input type="text" class="form-control" name="domain" id="dnsreverseDomain"
                               value="{{ old('domain', $domain->domain) }}" placeholder="midominio.com" required>
                        <p class="text-muted small">
                            Solo el dominio raiz, en minusculas y sin http:// ni barras. Tus clientes pediran
                            <code>loquesea.{{ $domain->domain ?: 'midominio.com' }}</code>.
                        </p>
                    </div>

                    <div class="form-group">
                        <label>Nombre para ti (opcional)</label>
                        <input type="text" class="form-control" name="label" value="{{ old('label', $domain->label) }}"
                               placeholder="Cuenta principal de Cloudflare">
                    </div>

                    <div class="form-group">
                        <label>Token de Cloudflare</label>
                        <input type="password" class="form-control" name="cf_token" id="dnsreverseToken"
                               value="" autocomplete="new-password"
                               placeholder="{{ $domain->hasToken() ? 'Guardado. Escribe uno nuevo solo si quieres cambiarlo' : 'Pega aqui el token' }}">
                        <p class="text-muted small">
                            El token tiene que tener permiso <strong>Zone &rarr; DNS &rarr; Edit</strong> sobre esta zona.
                            Se guarda cifrado y nunca se vuelve a mostrar.
                            @if($domain->hasToken())
                                <br>
                                <label style="font-weight: normal;">
                                    <input type="checkbox" name="clear_token" value="1"> Borrar el token guardado
                                </label>
                            @endif
                        </p>
                    </div>

                    <div class="form-group">
                        <label>Nube de Cloudflare (proxy naranja)</label>
                        <select class="form-control" name="proxied_mode">
                            @php $modo = old('proxied_mode', $domain->proxied_mode ?: 'auto'); @endphp
                            <option value="auto" {{ $modo === 'auto' ? 'selected' : '' }}>Automatica (recomendado)</option>
                            <option value="always" {{ $modo === 'always' ? 'selected' : '' }}>Siempre naranja</option>
                            <option value="never" {{ $modo === 'never' ? 'selected' : '' }}>Siempre gris</option>
                        </select>
                        <p class="text-muted small">
                            <strong>Automatica</strong> significa: nube naranja cuando se use el certificado de origen
                            (que solo vale pasando por Cloudflare) y nube gris cuando se use Let's Encrypt
                            (la validacion tiene que llegar al nodo, y con la nube naranja de por medio suele fallar).
                            Los registros SRV de Minecraft van siempre en gris, porque Cloudflare no sabe hablar
                            el protocolo de Minecraft.
                        </p>
                    </div>

                    <div class="form-group">
                        <label>Nombres reservados</label>
                        <input type="text" class="form-control" name="reserved" value="{{ old('reserved', $domain->reserved) }}"
                               placeholder="www,panel,admin,mail">
                        <p class="text-muted small">
                            Separados por comas. Ningun cliente podra pedir estos subdominios.
                        </p>
                    </div>

                    <div class="form-group">
                        <label>Notas (solo las ves tu)</label>
                        <textarea class="form-control" name="notes" rows="2">{{ old('notes', $domain->notes) }}</textarea>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary pull-right">
                        {{ $nuevo ? 'Anadir dominio' : 'Guardar cambios' }}
                    </button>
                    <button type="button" class="btn btn-info" onclick="dnsreverseProbar()">
                        @dnsicon('cloud', 14) Probar conexion
                    </button>
                    <span id="dnsreverseTestResult" class="dnsreverse-test-result"></span>
                </div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Que puede hacer el cliente</h3>
                </div>
                <div class="box-body">
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="active" value="1" {{ old('active', $domain->active) ? 'checked' : '' }}>
                            <strong>Activo</strong>
                        </label>
                        <p class="text-muted small">
                            Si lo desmarcas, nadie podra pedir subdominios nuevos de este dominio.
                            <strong>Lo que ya esta creado sigue funcionando</strong>: no se borra nada.
                        </p>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="allow_subdomain" value="1" {{ old('allow_subdomain', $domain->allow_subdomain) ? 'checked' : '' }}>
                            Permitir subdominios (paginas web)
                        </label>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="allow_srv" value="1" {{ old('allow_srv', $domain->allow_srv) ? 'checked' : '' }}>
                            Permitir registros SRV de Minecraft
                        </label>
                    </div>
                    <div class="checkbox">
                        <label>
                            <input type="checkbox" name="allow_letsencrypt" value="1" {{ old('allow_letsencrypt', $domain->allow_letsencrypt) ? 'checked' : '' }}>
                            Permitir certificados automaticos (Let's Encrypt)
                        </label>
                    </div>
                </div>
            </div>

            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Certificado de origen</h3>
                </div>
                <div class="box-body">
                    <p class="text-muted small">
                        En Cloudflare: <strong>SSL/TLS &rarr; Origin Server &rarr; Create Certificate</strong>.
                        Pon el comodin <code>*.{{ $domain->domain ?: 'midominio.com' }}</code> para que valga
                        para todos los subdominios de tus clientes. Dura 15 anos y solo lo acepta Cloudflare,
                        asi que el registro tiene que ir con la nube naranja.
                    </p>

                    <div class="form-group">
                        <label>Certificado</label>
                        <textarea class="form-control dnsreverse-mono" name="ssl_cert" rows="6"
                                  placeholder="-----BEGIN CERTIFICATE-----">{{ old('ssl_cert', $domain->ssl_cert) }}</textarea>
                    </div>
                    <div class="form-group">
                        <label>Clave privada</label>
                        <textarea class="form-control dnsreverse-mono" name="ssl_key" rows="6"
                                  placeholder="-----BEGIN PRIVATE KEY-----">{{ old('ssl_key', $domain->ssl_key) }}</textarea>
                        <p class="text-muted small">
                            La clave solo viaja del panel al nodo. Los clientes nunca la ven.
                        </p>
                    </div>
                </div>
            </div>

            @if(!$nuevo)
                <div class="box box-danger">
                    <div class="box-header with-border">
                        <h3 class="box-title">Dar de baja</h3>
                    </div>
                    <div class="box-body">
                        @if(($usos ?? 0) > 0)
                            <p class="text-muted small">
                                Hay <strong>{{ $usos }}</strong> DNS de clientes usando este dominio, asi que no se
                                puede borrar. Si lo que quieres es que no se creen mas, desmarca <strong>Activo</strong>
                                y guarda: lo ya creado sigue funcionando igual.
                            </p>
                        @else
                            <p class="text-muted small">
                                No hay ningun DNS usando este dominio, se puede dar de baja sin afectar a nadie.
                            </p>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>

@if(!$nuevo && ($usos ?? 0) === 0)
    <form method="POST" action="{{ route('admin.dnsreverse.domains.delete', $domain->id) }}"
          onsubmit="return confirm('¿Dar de baja el dominio {{ $domain->domain }}?');">
        @csrf
        <button type="submit" class="btn btn-danger">@dnsicon('trash', 14) Dar de baja este dominio</button>
    </form>
@endif

@endsection

@section('dnsreverse-scripts')
<script>
function dnsreverseProbar() {
    var salida = document.getElementById('dnsreverseTestResult');
    salida.className = 'dnsreverse-test-result dnsreverse-test-loading';
    salida.textContent = 'Preguntando a Cloudflare...';

    var datos = new FormData();
    datos.append('_token', '{{ csrf_token() }}');
    datos.append('domain', document.getElementById('dnsreverseDomain').value);

    var token = document.getElementById('dnsreverseToken').value;
    if (token) {
        datos.append('cf_token', token);
    }

    // Un dominio que todavia no se ha guardado no tiene ficha contra la que
    // probar, asi que se avisa en vez de mandar una peticion que fallaria.
    var url = @json($nuevo ? null : route('admin.dnsreverse.domains.test', $domain->id ?? 0));

    if (!url) {
        salida.className = 'dnsreverse-test-result dnsreverse-test-error';
        salida.textContent = 'Guarda el dominio primero y despues prueba la conexion.';
        return;
    }

    fetch(url, { method: 'POST', body: datos, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            salida.className = 'dnsreverse-test-result ' + (d.ok ? 'dnsreverse-test-ok' : 'dnsreverse-test-error');
            salida.textContent = d.message;
        })
        .catch(function (e) {
            salida.className = 'dnsreverse-test-result dnsreverse-test-error';
            salida.textContent = 'No se pudo comprobar: ' + e;
        });
}
</script>
@endsection
