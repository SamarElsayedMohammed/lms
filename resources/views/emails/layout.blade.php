<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skillso</title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; padding: 0; background-color: #F6F9FC; font-family: 'IBM Plex Sans Arabic', -apple-system, sans-serif; color: #425466; -webkit-font-smoothing: antialiased; }
        table { border-spacing: 0; font-family: 'IBM Plex Sans Arabic', -apple-system, sans-serif; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #F6F9FC; padding-bottom: 60px; }
        .main { background-color: #ffffff; margin: 0 auto; width: 100%; max-width: 600px; border-radius: 8px; box-shadow: 0 13px 27px -5px rgba(50,50,93,0.25), 0 8px 16px -8px rgba(0,0,0,0.3); overflow: hidden; margin-top: 40px; }
        .header { padding: 40px 40px 20px; }
        .logo-text { font-size: 28px; font-weight: 700; color: #eb2027; letter-spacing: -0.5px; text-decoration: none; display: inline-block; }
        .content { padding: 0 40px 40px; }
        h1 { color: #0A2540; font-size: 24px; font-weight: 700; margin-bottom: 16px; line-height: 1.3; margin-top: 0; }
        p { font-size: 16px; line-height: 24px; color: #425466; margin-bottom: 24px; margin-top: 0; }
        ul { margin-top: 0; margin-bottom: 24px; padding-right: 24px; color: #425466; }
        li { margin-bottom: 8px; font-size: 15px; }
        .footer { text-align: center; padding: 40px; color: #8898AA; font-size: 13px; }
        .footer a { color: #8898AA; text-decoration: none; margin: 0 8px; transition: color 0.2s; }
        .footer a:hover { color: #0A2540; }
        @media screen and (max-width: 600px) {
            .main { border-radius: 0; box-shadow: none; margin-top: 0; }
            .header, .content, .hero { padding-left: 24px !important; padding-right: 24px !important; }
        }
        @media (prefers-color-scheme: dark) {
            body, .wrapper { background-color: #0B0F19 !important; color: #A0ABBB !important; }
            .main { background-color: #111827 !important; box-shadow: 0 13px 27px -5px rgba(0,0,0,0.5) !important; }
            h1, h2, h3, h4, h5, h6, strong, b { color: #F3F4F6 !important; }
            p, li, .content > div, .card > div { color: #A0ABBB !important; }
            .card { background-color: #1F2937 !important; border-color: #374151 !important; }
            .invoice-label { color: #A0ABBB !important; border-top-color: #374151 !important; }
            .invoice-value { color: #F3F4F6 !important; border-top-color: #374151 !important; }
            .invoice-amount { color: #F3F4F6 !important; }
            .footer, .footer p, .footer a { color: #6B7280 !important; }
        }
    </style>
</head>
<body dir="rtl">
    <center class="wrapper">
        <table class="main" width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td class="header" style="text-align: right;">
                    <a href="{{ config('app.url') }}" style="display: inline-block;">
                        <img src="{{ asset('logo.png') }}" alt="Skillso Logo" style="height: 35px; border: none; display: block; margin: 0;">
                    </a>
                </td>
            </tr>
            
            @yield('content')
            
        </table>
        
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto;">
            <tr>
                <td class="footer">
                    <p>&copy; {{ date('Y') }} Skillso Inc. جميع الحقوق محفوظة.</p>
                    <p>
                        <a href="{{ config('app.url') }}/support">الدعم الفني</a> | 
                        <a href="{{ config('app.url') }}/terms">الشروط والأحكام</a> | 
                        <a href="{{ config('app.url') }}/privacy">الخصوصية</a>
                    </p>
                </td>
            </tr>
        </table>
    </center>
</body>
</html>
