{{-- Tarjeta de cifra: @include('arixlog::admin.partials.stat', [...]) --}}
<div class="arixlog-stat {{ $tone ?? '' }}">
    <div class="arixlog-stat-icon">@arixicon($icon ?? 'activity', 18)</div>
    <div class="arixlog-stat-body">
        <div class="arixlog-stat-value">{{ $value }}</div>
        <div class="arixlog-stat-label">{{ $label }}</div>
        @if(!empty($hint))
            <div class="arixlog-stat-hint">{{ $hint }}</div>
        @endif
    </div>
</div>
