@extends('logspterodactyl::admin._layout')

@section('logspterodactyl-title', 'Registro')
@section('logspterodactyl-heading', 'Registro de la extension')
@section('logspterodactyl-subheading', 'todo lo que ha hecho LogsPterodactyl')

@section('logspterodactyl-content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-body">
                    <form method="GET" class="logspterodactyl-filters" style="margin-bottom:12px;">
                        <div class="logspterodactyl-field">
                            <label for="channel">Seccion</label>
                            <select name="channel" id="channel" class="form-control" onchange="this.form.submit()">
                                <option value="">Todas</option>
                                @foreach($channels as $item)
                                    <option value="{{ $item }}" @if($channel === $item) selected @endif>{{ $item }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="logspterodactyl-field">
                            <label for="level">Nivel</label>
                            <select name="level" id="level" class="form-control" onchange="this.form.submit()">
                                <option value="">Todos</option>
                                @foreach(['info' => 'Informacion', 'warning' => 'Aviso', 'error' => 'Error'] as $key => $label)
                                    <option value="{{ $key }}" @if($level === $key) selected @endif>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-grow">
                            <label for="search">Buscar</label>
                            <input type="text" name="search" id="search" class="form-control" value="{{ $search }}">
                        </div>
                        <div class="logspterodactyl-field logspterodactyl-field-actions">
                            <button type="submit" class="btn btn-primary">@logsicon('search', 14) Buscar</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-hover logspterodactyl-table">
                            <thead>
                                <tr><th>Fecha</th><th>Seccion</th><th>Mensaje</th><th>Detalles</th></tr>
                            </thead>
                            <tbody>
                            @forelse($events as $event)
                                <tr class="logspterodactyl-level-{{ $event->level }}">
                                    <td class="logspterodactyl-small">{{ optional($event->created_at)->format('d/m/Y H:i:s') }}</td>
                                    <td><span class="logspterodactyl-chip">{{ $event->channel }}</span></td>
                                    <td>{{ $event->message }}</td>
                                    <td class="logspterodactyl-small logspterodactyl-muted">
                                        @if($event->context)
                                            @foreach($event->context as $key => $item)
                                                <div>
                                                    <strong>{{ $key }}:</strong>
                                                    {{ is_scalar($item) || $item === null
                                                        ? var_export($item, true)
                                                        : json_encode($item, JSON_UNESCAPED_UNICODE) }}
                                                </div>
                                            @endforeach
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><p class="logspterodactyl-empty">No hay nada registrado todavia.</p></td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>

                    {{ $events->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
