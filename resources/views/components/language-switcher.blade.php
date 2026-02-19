@php
    // Languages are now shared via ViewServiceProvider
    // $languages and $currentLanguage are available globally
@endphp

@if($languages && $languages->count() > 0)
@php
    // Ensure we have a current language, default to English if none set
    $currentLang = $currentLanguage ?? $languages->where('code', 'en')->first();
    $currentLangCode = $currentLang ? $currentLang->code : 'en';
@endphp
<div class="language-switcher">
    <div class="dropdown">
        <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
            <img src="{{ $currentLang->image ?? asset('img/flags/en.png') }}" 
                 alt="{{ $currentLang->name ?? 'English' }}" 
                 class="language-flag" 
                 style="width: 20px; height: 15px; margin-right: 8px; flex-shrink: 0;">
            <span class="d-sm-none d-lg-inline-block language-name">{{ $currentLang->name ?? 'English' }}</span>
        </a>
        <div class="dropdown-menu dropdown-menu-right language-dropdown">
            @foreach($languages as $language)
            <a href="{{ route('language.set-current', $language->code) }}" 
               class="dropdown-item language-item {{ $currentLangCode === $language->code ? 'active' : '' }}">
                <img src="{{ $language->image ?? asset('img/flags/' . $language->code . '.png') }}" 
                     alt="{{ $language->name }}" 
                     class="language-flag" 
                     style="width: 20px; height: 15px; margin-right: 8px;">
                <span>{{ $language->name }}</span>
                @if($language->rtl)
                <span class="rtl-indicator" title="RTL Language">{{ __('←') }}</span>
                @endif
                @if($currentLangCode === $language->code)
                <i class="fas fa-check text-success ml-auto"></i>
                @endif
            </a>
            @endforeach
        </div>
    </div>
</div>
@endif
