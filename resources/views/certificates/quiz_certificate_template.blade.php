<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title> {{ __('Quiz Certificate') }} </title>
</head>
<body>
    <div class="title"> {{ __('Certificate of Achievement') }} </div>
    <div class="subtitle"> {{ __('This is to certify that') }} </div>
    <h2>{{ $name }}</h2>
    <div class="subtitle"> {{ __('has successfully completed the quiz:') }} </div>
    <h3>{{ $quiz }}</h3>
    <div class="score">Score: {{ $score }}%</div>
    <div>Date: {{ $date }}</div>
    <div>Certificate No: {{ $certificate_number }}</div>
</body>
</html>
