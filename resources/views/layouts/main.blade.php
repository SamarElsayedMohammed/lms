<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>@yield('title', 'LMS')</title>
    @include('includes.css')
    @yield('additional_css')
</head>

<body class="@yield('body_class', '')">
    @yield('content')
    @include('includes.js')
    @yield('additional_js')
</body>

</html>
