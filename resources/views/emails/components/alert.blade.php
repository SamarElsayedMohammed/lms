@php
    $colors = [
        'success' => ['bg' => '#ecfdf5', 'border' => '#10b981', 'text' => '#065f46'],
        'warning' => ['bg' => '#fffbeb', 'border' => '#f59e0b', 'text' => '#92400e'],
        'error' => ['bg' => '#fef2f2', 'border' => '#ef4444', 'text' => '#991b1b'],
        'info' => ['bg' => '#eff6ff', 'border' => '#3b82f6', 'text' => '#1e40af'],
    ];
    $type = $type ?? 'info';
    $style = $colors[$type];
@endphp
<div style="background-color: {{ $style['bg'] }}; border-right: 4px solid {{ $style['border'] }}; border-radius: 8px; padding: 16px; margin: 20px 0; color: {{ $style['text'] }}; font-family: 'Cairo', 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;" class="alert alert-{{ $type }}">
    {{ $slot }}
</div>
