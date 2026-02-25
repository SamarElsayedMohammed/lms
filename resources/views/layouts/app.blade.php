@php
// RTL when admin panel (locale set to ar by SetAdminLocale) or view composer passed isRTL
$forceRTL = app()->getLocale() === 'ar' || (auth()->check() && !request()->routeIs('login-page'));
$layoutDir = ($forceRTL || ($isRTL ?? false)) ? 'rtl' : 'ltr';
$isRTL = $layoutDir === 'rtl';
@endphp
<!DOCTYPE html>
<html lang="{{ optional($currentLanguage)->code ?? ($forceRTL ? 'ar' : 'en') }}" dir="{{ $layoutDir }}">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') &mdash; {{ config('app.name') }}</title>
    @include('includes.css')
    @stack('style')
    {{-- Critical layout fallback so content never overlaps sidebar if external CSS fails --}}
    <style>
        body.rtl-layout .main-sidebar, body[dir="rtl"] .main-sidebar {
            position: fixed !important; top: 0 !important; right: 0 !important; left: auto !important;
            width: 250px !important; height: 100vh !important; z-index: 1000 !important;
        }
        body.rtl-layout .main-content, body[dir="rtl"] .main-content {
            padding-right: 280px !important; padding-left: 30px !important; box-sizing: border-box !important;
        }
        body.rtl-layout .main-footer, body[dir="rtl"] .main-footer {
            padding-right: 280px !important; padding-left: 30px !important;
        }
        .main-wrapper { display: block; }
        .main-content { min-height: 100vh; }
    </style>
</head>

<body dir="{{ $layoutDir }}" class="{{ $layoutDir === 'rtl' ? 'rtl-layout' : '' }}">
    <script>
        (function() {
            var theme = localStorage.getItem('admin_theme') || 'light';
            document.body.setAttribute('data-theme', theme);
        })();
    </script>
    <div id="app">
        <div class="main-wrapper">
            <!-- Header -->
            <div class="navbar-bg"></div>
            <nav class="navbar navbar-expand-lg main-navbar">
                <form class="form-inline mr-auto">
                    <ul class="navbar-nav mr-3">
                        <li><a href="#" data-toggle="sidebar" class="nav-link nav-link-lg"><i
                                    class="fas fa-bars"></i></a></li>
                    </ul>
                </form>
                <ul class="navbar-nav navbar-right">
                    <!-- Dark Mode Toggle -->
                    <li class="nav-item d-flex align-items-center mr-2">
                        <a href="#" id="admin-theme-toggle" class="nav-link nav-link-lg" title="{{ __('Dark Mode') }}" aria-label="{{ __('Toggle dark mode') }}">
                            <i class="fas fa-moon" id="admin-theme-icon-dark" style="display: none;"></i>
                            <i class="fas fa-sun" id="admin-theme-icon-light"></i>
                        </a>
                    </li>
                    <!-- Language Switcher and User Profile in same line -->
                    <li class="nav-item d-flex align-items-center">
                        @include('components.language-switcher')

                        <!-- User Profile Dropdown -->
                        <div class="dropdown ml-2">
                            <a href="#" data-toggle="dropdown"
                                class="nav-link dropdown-toggle nav-link-lg nav-link-user">
                                <img alt="image" src="{{ auth()->user()->profile ?? asset('img/avatar/avatar-1.png') }}"
                                    class="rounded-circle mr-1" style="width: 30px; height: 30px; object-fit: cover;">
                                <div class="d-sm-none d-lg-inline-block">{{ __('Hi') }}, {{ auth()->user()->name }}
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-right">
                                <a href="{{ route('admin.profile') }}" class="dropdown-item has-icon">
                                    <i class="far fa-user"></i> {{ __('Profile') }} </a>
                                <div class="dropdown-divider"></div>
                                <a href="{{ route('admin.logout') }}" class="dropdown-item has-icon text-danger">
                                    <i class="fas fa-sign-out-alt"></i> {{ __('Logout') }} </a>
                            </div>
                        </div>
                    </li>
                </ul>
            </nav>

            <!-- Sidebar -->
            @include('components.sidebar')
            <!-- Main Content -->
            <div class="main-content">
                <!-- Page Title Section -->
                @hasSection('page-title')
                <section class="section">
                    <div class="section-header">
                        @yield('page-title')
                    </div>
                </section>
                @endif

                @yield('main')
            </div>

            <!-- Footer -->
            <footer class="main-footer">
                <div class="footer-left">
                    Copyright &copy; {{ date('Y') }} <div class="bullet"></div> {{ config('app.name') }}
                </div>
                <div class="footer-right"> {{ __('1.0.0') }} </div>
            </footer>
        </div>
    </div>

    @include('includes.js')
    @stack('scripts')
    @yield('script')
    @yield('js')

    <!-- Admin theme (Dark Mode) toggle -->
    <script>
        (function() {
            function getTheme() { return localStorage.getItem('admin_theme') || 'light'; }
            function setTheme(theme) {
                localStorage.setItem('admin_theme', theme);
                document.documentElement.setAttribute('data-theme', theme);
                document.body.setAttribute('data-theme', theme);
                var isDark = theme === 'dark';
                document.getElementById('admin-theme-icon-dark').style.display = isDark ? 'inline' : 'none';
                document.getElementById('admin-theme-icon-light').style.display = isDark ? 'none' : 'inline';
            }
            document.getElementById('admin-theme-toggle').addEventListener('click', function(e) {
                e.preventDefault();
                setTheme(getTheme() === 'dark' ? 'light' : 'dark');
            });
            var theme = getTheme();
            document.getElementById('admin-theme-icon-dark').style.display = theme === 'dark' ? 'inline' : 'none';
            document.getElementById('admin-theme-icon-light').style.display = theme === 'dark' ? 'none' : 'inline';
        })();
    </script>

    <!-- RTL Sidebar Toggle Script -->
    @if($isRTL)
    <script>
        $(document).ready(function () {
            var SIDEBAR_WIDTH = 250;
            var SIDEBAR_OFFSET = 280; // sidebar 250px + 30px gap, matches CSS padding-right:280px

            function isMobile() {
                return window.innerWidth <= 1024;
            }

            function applyRTLLayout() {
                var isMini = $('body').hasClass('sidebar-mini');
                var sidebarW = isMini ? 65 : SIDEBAR_WIDTH;
                var contentPR = isMini ? 90 : SIDEBAR_OFFSET;

                if (isMobile()) {
                    // Mobile: sidebar hidden off-screen right, content fills full width
                    $('.main-sidebar').css({
                        'position': 'fixed',
                        'top': '0',
                        'right': '-' + SIDEBAR_WIDTH + 'px',
                        'left': 'auto',
                        'width': SIDEBAR_WIDTH + 'px',
                        'height': '100vh',
                        'z-index': '9999',
                        'transition': 'right 0.3s ease'
                    });
                    $('.main-content').css({ 'padding-right': '30px', 'padding-left': '30px' });
                    $('.main-footer').css({ 'padding-right': '30px', 'padding-left': '30px' });
                    $('.navbar').css({ 'right': '0', 'left': '0' });
                    $('.navbar-bg').css({ 'right': '0', 'left': '0', 'width': 'auto' });
                } else {
                    // Desktop: sidebar fixed on right, content padding-right = sidebarW + 30 — لا مسافة شمالية
                    $('.main-sidebar').css({
                        'position': 'fixed',
                        'top': '0',
                        'right': '0',
                        'left': 'auto',
                        'width': sidebarW + 'px',
                        'height': '100vh',
                        'z-index': '880',
                        'transition': 'right 0.3s ease, width 0.5s'
                    });
                    $('#app').css({ 'margin-left': '0', 'padding-left': '0', 'margin-right': '0', 'padding-right': '0' });
                    $('.main-wrapper').css({ 'margin-left': '0', 'padding-left': '0', 'margin-right': '0', 'padding-right': '0', 'width': '100%' });
                    $('.main-content').css({
                        'padding-right': contentPR + 'px',
                        'padding-left': '30px',
                        'margin-right': '0',
                        'margin-left': '0'
                    });
                    $('.main-footer').css({
                        'padding-right': contentPR + 'px',
                        'padding-left': '30px',
                        'margin-right': '0',
                        'margin-left': '0'
                    });
                    $('.navbar').css({ 'right': sidebarW + 'px', 'left': '0' });
                    $('.navbar-bg').css({ 'right': sidebarW + 'px', 'left': '0', 'width': 'auto' });
                }
            }

            // Apply layout immediately and after DOM settles
            applyRTLLayout();
            setTimeout(applyRTLLayout, 100);

            // Reapply on resize
            $(window).on('resize', applyRTLLayout);

            // Handle sidebar toggle button (hamburger) — take full control so scripts.js doesn't double-toggle
            $('[data-toggle="sidebar"]').off('click').on('click', function (e) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (isMobile()) {
                    // Mobile: show/hide sidebar off-screen
                    var isVisible = parseInt($('.main-sidebar').css('right'), 10) === 0;
                    $('.main-sidebar').css('right', isVisible ? '-' + SIDEBAR_WIDTH + 'px' : '0');
                } else {
                    // Desktop: toggle mini mode
                    $('body').toggleClass('sidebar-mini');
                    applyRTLLayout();
                }
                return false;
            });

            // Close sidebar on outside click (mobile only)
            $(document).on('click', function (e) {
                if (isMobile() && !$(e.target).closest('.main-sidebar, [data-toggle="sidebar"]').length) {
                    $('.main-sidebar').css('right', '-' + SIDEBAR_WIDTH + 'px');
                }
            });

            // Watch for any external code toggling body classes (e.g. stisla.js toggle)
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    if (m.attributeName === 'class') {
                        applyRTLLayout();
                    }
                });
            });
            observer.observe(document.body, { attributes: true });

        });
    </script>
    @endif

</body>

</html>