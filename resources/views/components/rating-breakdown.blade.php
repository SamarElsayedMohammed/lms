@props([
    'label' => __('Ratings'),
    'average' => 0,
    'count' => 0,
    'breakdown' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
])

@php
    $maxCount = max($breakdown) ?: 1;
    $fullStars = floor($average);
    $hasHalfStar = ($average - $fullStars) >= 0.25 && ($average - $fullStars) < 0.75;
    $roundUp = ($average - $fullStars) >= 0.75;
    if ($roundUp) {
        $fullStars++;
        $hasHalfStar = false;
    }
@endphp

<div class="border rounded p-3 h-100">
    <div class="d-flex align-items-start">
        <div class="text-center mr-3" style="min-width: 70px;">
            <div class="h2 font-weight-bold mb-0">{{ $average }}</div>
            <div class="mb-1">
                @for($i = 1; $i <= 5; $i++)
                    @if($i <= $fullStars)
                        <i class="fas fa-star text-warning" style="font-size: 10px;"></i>
                    @elseif($i == $fullStars + 1 && $hasHalfStar)
                        <i class="fas fa-star-half-alt text-warning" style="font-size: 10px;"></i>
                    @else
                        <i class="far fa-star text-muted" style="font-size: 10px;"></i>
                    @endif
                @endfor
            </div>
            <small class="text-muted">{{ number_format($count) }} {{ __('ratings') }}</small>
        </div>
        <div class="flex-grow-1">
            <p class="text-muted small mb-2 font-weight-bold">{{ $label }}</p>
            @foreach([5, 4, 3, 2, 1] as $star)
                @php
                    $starCount = $breakdown[$star] ?? 0;
                    $percent = round(($starCount / $maxCount) * 100);
                @endphp
                <div class="d-flex align-items-center mb-1">
                    <span class="text-muted small" style="width: 10px;">{{ $star }}</span>
                    <div class="progress flex-grow-1 mx-2" style="height: 6px; border-radius: 3px;">
                        <div class="progress-bar bg-warning" style="width: {{ $percent }}%; border-radius: 3px;"></div>
                    </div>
                    <span class="text-muted small" style="width: 25px; text-align: right;">{{ $starCount }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
