<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

<head>
    <meta charset="UTF-8">
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <title>@yield('title') &mdash; LMS</title>

    <!-- General CSS Files -->
    <link rel="stylesheet" href="{{ asset('library/bootstrap/dist/css/bootstrap.min.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css"
        integrity="sha512-KfkfwYDsLkIlwQp6LFnl8zNdLGxu9YAA1QvwINks4PhcElQSvqcyVLLD9aMhXd13uQjoXtEKNosOWaZqXgel0g=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    @if(app()->getLocale() === 'ar')
    <link rel="stylesheet" href="{{ asset('css/rtl.css') }}">
    <style>
        body {
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    @endif
    @stack('style') <!-- Template CSS -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/components.css') }}">
    <!-- Start GA -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=UA-94034622-3"></script>
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());

        gtag('config', 'UA-94034622-3');
    </script>
    <!-- END GA -->
</head>

<body>
    <div id="app">
        <section class="section">
            <div class="container mt-5">
                <div class="row justify-content-center">
                    <div
                        class="{{ request()->is('auth-register') ? 'col-12 col-sm-10 col-md-8 col-lg-8 col-xl-8' : 'col-12 col-sm-8 col-md-6 col-lg-6 col-xl-4' }} mx-auto">
                        <!-- Header -->
                        @include('components.auth-header')
                        <!-- Content -->
                        @yield('main')
                        <!-- Footer -->
                        @include('components.auth-footer')
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- General JS Scripts -->
    <script src="{{ asset('library/jquery/dist/jquery.min.js') }}"></script>
    <script src="{{ asset('library/popper.js/dist/umd/popper.js') }}"></script>
    <script src="{{ asset('library/tooltip.js/dist/umd/tooltip.js') }}"></script>
    <script src="{{ asset('library/bootstrap/dist/js/bootstrap.min.js') }}"></script>
    <script src="{{ asset('library/jquery.nicescroll/dist/jquery.nicescroll.min.js') }}"></script>
    <script src="{{ asset('library/moment/min/moment.min.js') }}"></script>
    <script src="{{ asset('js/stisla.js') }}"></script>
    @stack('scripts') <!-- Template JS File -->
    <script src="{{ asset('js/scripts.js') }}"></script>
    <script src="{{ asset('js/custom.js') }}"></script>
</body>

</html>