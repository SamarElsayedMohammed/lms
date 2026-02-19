<!DOCTYPE html>
<html lang="{{ $page->language->code ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page->meta_title ?? $page->title }} | {{ config('app.name') }}</title>
    
    {{-- Meta Tags --}}
    @if($page->meta_description)
    <meta name="description" content="{{ $page->meta_description }}">
    @endif
    @if($page->meta_keywords)
    <meta name="keywords" content="{{ $page->meta_keywords }}">
    @endif
    
    {{-- Open Graph --}}
    <meta property="og:title" content="{{ $page->meta_title ?? $page->title }}">
    @if($page->meta_description)
    <meta property="og:description" content="{{ $page->meta_description }}">
    @endif
    @if($page->og_image)
    <meta property="og:image" content="{{ asset('storage/' . $page->og_image) }}">
    @endif
    <meta property="og:type" content="website">
    
    {{-- Schema Markup --}}
    @if($page->schema_markup)
    <script type="application/ld+json">
    {!! $page->schema_markup !!}
    </script>
    @endif
    
    {{-- Google Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,100..1000;1,9..40,100..1000&family=Playfair+Display:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    {{-- Page View CSS --}}
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'DM Sans', sans-serif;
            background-color: #f8fafc;
            color: #1f2937;
        }
        
        .page-footer {
            background: var(--bg-secondary, #f8fafc);
            padding: 40px 24px;
            text-align: center;
            margin-top: 60px;
        }
        
        .footer-content {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .footer-content p {
            margin: 0;
            color: var(--text-light, #6b7280);
            font-size: 14px;
        }
    </style>
</head>
<body>
    {{-- Header Section --}}
    <header class="page-header">
        <div class="header-content">
            
            <span class="page-type-badge">
                @switch($page->page_type)
                    @case('about-us')
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        @break
                    @case('privacy-policy')
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        @break
                    @case('terms-and-conditions')
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        @break
                    @case('cookies-policy')
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11c0 3.517-1.009 6.799-2.753 9.571m-3.44-2.04l.054-.09A13.916 13.916 0 008 11a4 4 0 118 0c0 1.017-.07 2.019-.203 3m-2.118 6.844A21.88 21.88 0 0015.171 17m3.839 1.132c.645-2.266.99-4.659.99-7.132A8 8 0 008 4.07M3 15.364c.64-1.319 1-2.8 1-4.364 0-1.457.39-2.823 1.07-4" />
                        </svg>
                        @break
                    @default
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                @endswitch
                {{ str_replace('-', ' ', $page->page_type) }}
            </span>
            
            <h1 class="page-title">{{ $page->title }}</h1>
            
            <div class="page-meta">
                @if($page->language)
                <span class="meta-item">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                    </svg>
                    {{ $page->language->name }}
                </span>
                @endif
                
                <span class="meta-item">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    {{ __('Updated') }}: {{ $page->updated_at->format('M d, Y') }}
                </span>
            </div>
        </div>
    </header>
    
    {{-- Main Content --}}
    <main class="page-container">
        <article class="content-card">
            @if($page->page_icon)
            <div class="page-icon-container">
                <img src="{{ asset('storage/' . $page->page_icon) }}" alt="{{ $page->title }}" class="page-icon">
            </div>
            @endif
            
            <div class="page-content">
                {!! $page->page_content !!}
            </div>
        </article>
    </main>
    
    {{-- Footer --}}
    <footer class="page-footer">
        <div class="footer-content">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
        </div>
    </footer>
</body>
</html>
