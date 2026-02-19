<!-- Favicon -->
@if (!empty($settingLogos['favicon']))
<link rel="icon" type="image/x-icon" href="{{ $settingLogos['favicon'] }}" />
@else
<link rel="icon" type="image/x-icon" href="{{ url(asset('img/favicon/favicon.png')) }}" />
@endif

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />

<!--- Font Awesome --->
<link rel="stylesheet" href="{{ asset('extensions/fontawesome/css/all.min.css') }}">


<!-- Bootstrap CSS -->
<link href="{{ asset('css/bootstrap.min.css') }}" rel="stylesheet">

<!-- SweetAlert2 CSS -->
<link rel="stylesheet" href="{{ asset('css/sweetalert2.min.css') }}">


<!-- Bootstrap Table CSS -->
<link rel="stylesheet" href="{{ asset('css/bootstrap-table.min.css') }}">

<!-- Parsley CSS -->
<link href="{{ asset('css/parsley.min.css') }}" rel="stylesheet">

<!-- Select2 CSS -->
<link rel="stylesheet" href="{{ asset('extensions/select2/select2.min.css') }}" />
<link rel="stylesheet" href="{{ asset('extensions/select2/select2-bootstrap-5-theme.min.css') }}" />

<!-- Toastify CSS -->
<link rel="stylesheet" type="text/css" href="{{ asset('extensions/toastify-js/toastify.css') }}">

<!-- Magnific Popup CSS -->
<link rel="stylesheet" href="{{ asset('css/magnific-popup.css') }}">

<!-- Template CSS -->
<link rel="stylesheet" href="{{ asset('css/main/app.css') }}">
<link rel="stylesheet" href="{{ asset('css/style.css') }}">
<link rel="stylesheet" href="{{ asset('css/components.css') }}">
<link rel="stylesheet" href="{{ asset('css/pages/otherpages.css') }}" />
<link rel="stylesheet" href="{{ asset('css/custom.css') }}">

<!-- RTL Support --> @if($isRTL) <link rel="stylesheet" href="{{ asset('css/rtl.css') }}">
@endif


{{-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"> --}}


{{--JS Tree--}}
<link rel="stylesheet" href="{{asset('extensions/jstree/jstree.min.css')}}"/>

<!-- Reorder Rows Css -->
<link rel="stylesheet" href="{{ asset('css/bootstrap-table/reorder-rows.css') }}">

<!-- Jquery UI -->
<link rel="stylesheet" href="{{ asset('extensions/jquery-ui/jquery-ui.min.css') }}" type="text/css"/>
