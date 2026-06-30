\Illuminate\Support\Facades\Config::set('mail.from.address', 'skillso.egypt@gmail.com');
\Illuminate\Support\Facades\Config::set('mail.from.name', 'Skillso LMS');

try {
    \Illuminate\Support\Facades\Mail::raw('This is a test email from Skillso LMS to verify the sender address after adding it to Brevo.', function ($message) {
        $message->to('samar.e.mohammed.94@gmail.com')
                ->subject('Test Email from Skillso - Brevo Verified');
    });
    echo "Email sent successfully!\n";
} catch (\Exception $e) {
    echo "Failed to send email. Error: " . $e->getMessage() . "\n";
}
