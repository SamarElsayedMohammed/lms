<td class="hero" style="padding: 0 40px 30px;">
    @if(isset($image))
        <img src="{{ $image }}" alt="{{ $title }}" style="width: 100%; border-radius: 8px; height: 240px; object-fit: cover; box-shadow: 0 4px 6px rgba(50,50,93,0.11), 0 1px 3px rgba(0,0,0,0.08); display: block; margin-bottom: 30px;">
    @endif
    <h1 style="color: #0A2540; font-size: 24px; font-weight: 700; margin-bottom: 16px; line-height: 1.3;">{{ $title }}</h1>
    @if(isset($subtitle))
        <p style="font-size: 16px; line-height: 24px; color: #425466; margin-bottom: 24px;">{{ $subtitle }}</p>
    @endif
</td>
