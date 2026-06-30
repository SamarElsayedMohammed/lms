@php
    $bg = '#F6F9FC';
    $color = '#425466';
    if($type === 'success') { $bg = '#E3F8EB'; $color = '#008744'; }
    if($type === 'warning') { $bg = '#FFF3CD'; $color = '#856404'; }
    if($type === 'error') { $bg = '#FCE8E6'; $color = '#D93025'; }
    if($type === 'primary') { $bg = 'rgba(235, 32, 39, 0.1)'; $color = '#eb2027'; }
@endphp
<span style="display: inline-block; padding: 6px 12px; border-radius: 4px; background-color: {{ $bg }}; color: {{ $color }}; font-size: 13px; font-weight: 600; margin-bottom: 16px;">
    {{ $slot }}
</span>
