{{-- Tarjeta de cifra: @include('logspterodactyl::admin.partials.stat', [...]) --}}
<div class="logspterodactyl-stat {{ $tone ?? '' }}">
    <div class="logspterodactyl-stat-icon">@logsicon($icon ?? 'activity', 18)</div>
    <div class="logspterodactyl-stat-body">
        <div class="logspterodactyl-stat-value">{{ $value }}</div>
        <div class="logspterodactyl-stat-label">{{ $label }}</div>
        @if(!empty($hint))
            <div class="logspterodactyl-stat-hint">{{ $hint }}</div>
        @endif
    </div>
</div>
