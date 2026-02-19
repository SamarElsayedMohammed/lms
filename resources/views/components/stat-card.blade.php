@props([
    'icon' => 'fas fa-chart-bar',
    'color' => 'primary',
    'title' => '',
    'titleId' => null,
    'value' => '-',
    'valueId' => null,
    'growth' => null,
    'growthId' => null,
    'growthLabel' => null,
])

@once
@push('style')
<style>
    .stat-card {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        padding: 20px;
        height: 100%;
        min-height: 110px;
    }

    .stat-card__inner {
        display: flex;
        align-items: flex-start;
        gap: 14px;
        min-width: 0;
    }

    .stat-card__icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 20px;
        color: #fff;
    }

    .stat-card__content {
        flex: 1;
        min-width: 0;
    }

    .stat-card__title {
        font-size: 13px;
        font-weight: 500;
        color: #6c757d;
        margin: 0 0 4px 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .stat-card__value {
        font-size: 24px;
        font-weight: 600;
        color: #1a1a2e;
        line-height: 1.2;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .stat-card__growth {
        font-size: 12px;
        margin-top: 6px;
        color: #6c757d;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .stat-card__growth-value {
        font-weight: 600;
    }

    .stat-card__growth-value.positive { color: #28a745; }
    .stat-card__growth-value.negative { color: #dc3545; }

    /* Tablet */
    @media (max-width: 1199.98px) {
        .stat-card { padding: 16px; }
        .stat-card__icon { width: 42px; height: 42px; font-size: 18px; }
        .stat-card__value { font-size: 20px; }
        .stat-card__inner { gap: 12px; }
    }

    /* Mobile */
    @media (max-width: 575.98px) {
        .stat-card { padding: 14px; }
        .stat-card__inner { flex-direction: column; align-items: center; text-align: center; gap: 10px; }
        .stat-card__content { width: 100%; }
        .stat-card__title, .stat-card__value, .stat-card__growth { white-space: normal; }
    }
</style>
@endpush
@endonce

<div {{ $attributes->merge(['class' => 'stat-card']) }}>
    <div class="stat-card__inner">
        <div class="stat-card__icon bg-{{ $color }}">
            <i class="{{ $icon }}"></i>
        </div>
        <div class="stat-card__content">
            <p class="stat-card__title" @if($titleId) id="{{ $titleId }}" @endif title="{{ $title }}">{{ $title }}</p>
            <div class="stat-card__value" @if($valueId) id="{{ $valueId }}" @endif>{{ $value }}</div>
            @if($growth !== null || $growthId)
            <div class="stat-card__growth">
                <span class="stat-card__growth-value positive" @if($growthId) id="{{ $growthId }}" @endif>{{ $growth ?? '+0%' }}</span>
                @if($growthLabel) {{ $growthLabel }} @endif
            </div>
            @endif
        </div>
    </div>
</div>
