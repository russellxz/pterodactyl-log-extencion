@extends('arixlog::admin._layout')

@section('arixlog-title', 'Configuracion')
@section('arixlog-heading', 'Configuracion de ArixLog')
@section('arixlog-subheading', 'tiempos, acciones automaticas y retencion de datos')

@section('arixlog-content')
    <form method="POST" action="{{ route('admin.arixlog.settings.update') }}">
        {{ csrf_field() }}

        <div class="row">
            <div class="col-md-6">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Instalaciones colgadas</h3>
                    </div>
                    <div class="box-body">
                        <p class="text-muted">
                            Este es el sistema que corta las instalaciones que se quedan atascadas.
                            Se ejecuta cada minuto desde el cron del panel.
                        </p>

                        <div class="form-group">
                            <label class="arixlog-switch">
                                <input type="checkbox" name="watchdog_enabled" value="1"
                                       @if($settings->bool('watchdog_enabled')) checked @endif>
                                <span>Detener automaticamente las instalaciones colgadas</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="watchdog_minutes">Cortar cuando pase de</label>
                            <div class="input-group">
                                <input type="number" min="1" max="1440" name="watchdog_minutes"
                                       id="watchdog_minutes" class="form-control"
                                       value="{{ $settings->int('watchdog_minutes') }}">
                                <span class="input-group-addon">minutos</span>
                            </div>
                            <p class="text-muted arixlog-small" style="margin-top:6px;">
                                Una instalacion normal tarda entre 1 y 5 minutos. Lo habitual es poner 10.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="watchdog_action">Que hacer al cortarla</label>
                            <select name="watchdog_action" id="watchdog_action" class="form-control">
                                @foreach($modes as $key => $label)
                                    <option value="{{ $key }}" @if($settings->get('watchdog_action') === $key) selected @endif>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="arixlog-note arixlog-note-info">
                            @arixicon('alert', 16)
                            <span>
                                wings no tiene ninguna orden para cancelar una instalacion en marcha.
                                En los dos primeros modos el panel marca la instalacion como fallida
                                (que es lo que desbloquea la pantalla del cliente), pero el contenedor
                                de instalacion del nodo puede seguir corriendo hasta que termine solo.
                                El modo <strong>forzado</strong> borra el servidor en el nodo, que es la
                                unica forma de cortar el contenedor de verdad; despues hay que usar
                                "Recrear en el nodo" antes de volver a instalar.
                            </span>
                        </div>

                        <div class="form-group" style="margin-top:14px;">
                            <label class="arixlog-switch">
                                <input type="checkbox" name="watchdog_same_ip" value="1"
                                       @if($settings->bool('watchdog_same_ip')) checked @endif>
                                <span>Intentar mantener la misma IP al cambiar de puerto</span>
                            </label>
                            <label class="arixlog-switch">
                                <input type="checkbox" name="watchdog_notify_owner" value="1"
                                       @if($settings->bool('watchdog_notify_owner')) checked @endif>
                                <span>Avisar al cliente por correo cuando se corte su instalacion</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Aviso en el area de cliente</h3>
                    </div>
                    <div class="box-body">
                        <p class="text-muted">
                            Tarjeta que ve el cliente en la pantalla de su servidor cuando la instalacion
                            se alarga, con el aviso sobre el token, el usuario y los repositorios privados,
                            y un boton para detenerla.
                        </p>

                        <div class="form-group">
                            <label class="arixlog-switch">
                                <input type="checkbox" name="client_cancel_enabled" value="1"
                                       @if($settings->bool('client_cancel_enabled')) checked @endif>
                                <span>Mostrar el aviso y el boton al cliente</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="client_cancel_minutes">Aparece a partir de</label>
                            <div class="input-group">
                                <input type="number" min="1" max="1440" name="client_cancel_minutes"
                                       id="client_cancel_minutes" class="form-control"
                                       value="{{ $settings->int('client_cancel_minutes') }}">
                                <span class="input-group-addon">minutos</span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="arixlog-switch">
                                <input type="checkbox" name="client_cancel_rotate_port" value="1"
                                       @if($settings->bool('client_cancel_rotate_port')) checked @endif>
                                <span>Cambiar tambien el puerto cuando lo detiene el cliente</span>
                            </label>
                            <p class="text-muted arixlog-small">
                                El cliente nunca puede usar el modo forzado: eso queda solo para administradores.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Registro de correos</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="arixlog-switch">
                                <input type="checkbox" name="mail_log_enabled" value="1"
                                       @if($settings->bool('mail_log_enabled')) checked @endif>
                                <span>Guardar los correos que envia el panel</span>
                            </label>
                            <label class="arixlog-switch">
                                <input type="checkbox" name="mail_log_store_body" value="1"
                                       @if($settings->bool('mail_log_store_body')) checked @endif>
                                <span>Guardar tambien el cuerpo (necesario para poder reenviarlos)</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="mail_log_retention_days">Conservar durante</label>
                            <div class="input-group">
                                <input type="number" min="1" max="3650" name="mail_log_retention_days"
                                       id="mail_log_retention_days" class="form-control"
                                       value="{{ $settings->int('mail_log_retention_days') }}">
                                <span class="input-group-addon">dias</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Consumo de recursos</h3>
                    </div>
                    <div class="box-body">
                        <div class="form-group">
                            <label class="arixlog-switch">
                                <input type="checkbox" name="resources_enabled" value="1"
                                       @if($settings->bool('resources_enabled')) checked @endif>
                                <span>Guardar una muestra por minuto de cada servidor encendido</span>
                            </label>
                        </div>

                        <div class="form-group">
                            <label for="resources_retention_days">Conservar el historico</label>
                            <div class="input-group">
                                <input type="number" min="1" max="3650" name="resources_retention_days"
                                       id="resources_retention_days" class="form-control"
                                       value="{{ $settings->int('resources_retention_days') }}">
                                <span class="input-group-addon">dias</span>
                            </div>
                            <p class="text-muted arixlog-small" style="margin-top:6px;">
                                Cada servidor encendido genera 1.440 filas al dia. Con 14 dias y 100 servidores
                                son unos dos millones de filas: suficiente para ver patrones sin engordar la
                                base de datos.
                            </p>
                        </div>

                        <div class="row">
                            <div class="col-xs-6">
                                <div class="form-group">
                                    <label for="resources_alert_cpu">Marcar en rojo desde</label>
                                    <div class="input-group">
                                        <input type="number" min="1" max="100000" name="resources_alert_cpu"
                                               id="resources_alert_cpu" class="form-control"
                                               value="{{ $settings->int('resources_alert_cpu') }}">
                                        <span class="input-group-addon">% CPU</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xs-6">
                                <div class="form-group">
                                    <label for="resources_alert_memory">Marcar en rojo desde</label>
                                    <div class="input-group">
                                        <input type="number" min="1" max="100" name="resources_alert_memory"
                                               id="resources_alert_memory" class="form-control"
                                               value="{{ $settings->int('resources_alert_memory') }}">
                                        <span class="input-group-addon">% RAM</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Retencion y respaldos</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-xs-6">
                                <div class="form-group">
                                    <label for="events_retention_days">Registro propio</label>
                                    <div class="input-group">
                                        <input type="number" min="1" max="3650" name="events_retention_days"
                                               id="events_retention_days" class="form-control"
                                               value="{{ $settings->int('events_retention_days') }}">
                                        <span class="input-group-addon">dias</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-xs-6">
                                <div class="form-group">
                                    <label for="installs_retention_days">Historial de instalaciones</label>
                                    <div class="input-group">
                                        <input type="number" min="1" max="3650" name="installs_retention_days"
                                               id="installs_retention_days" class="form-control"
                                               value="{{ $settings->int('installs_retention_days') }}">
                                        <span class="input-group-addon">dias</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="backup_path">Carpeta para los respaldos del panel</label>
                            <input type="text" name="backup_path" id="backup_path" class="form-control"
                                   value="{{ $settings->get('backup_path') }}">
                            <p class="text-muted arixlog-small" style="margin-top:6px;">
                                Aqui se guardan los respaldos que se hacen antes de actualizar el panel.
                                Debe estar fuera de la carpeta del panel.
                            </p>
                        </div>

                        <div class="form-group">
                            <label for="update_keep_backups">Respaldos que se conservan</label>
                            <input type="number" min="1" max="20" name="update_keep_backups"
                                   id="update_keep_backups" class="form-control"
                                   value="{{ $settings->int('update_keep_backups') }}">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-xs-12">
                <div class="box">
                    <div class="box-body text-right">
                        <button type="submit" class="btn btn-primary">@arixicon('check', 14) Guardar configuracion</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
