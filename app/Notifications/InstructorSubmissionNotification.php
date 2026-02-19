<?php

namespace App\Notifications;

use App\Helpers\FirebaseHelper;
use App\Models\Instructor;
use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class InstructorSubmissionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $instructor;
    protected $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(Instructor $instructor, User $user)
    {
        $this->instructor = $instructor;
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('New Instructor Application Submitted')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line($this->user->name . ' has submitted their instructor application for review.')
            ->line('Please review and approve or reject the application.')
            ->line('Thank you!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'instructor_submission',
            'title' => 'New Instructor Application',
            'message' => $this->user->name . ' has submitted their instructor application for review.',
            'instructor_id' => $this->instructor->id,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'instructor_type' => $this->instructor->type,
            'type_id' => $this->instructor->id,
        ];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): DatabaseMessage
    {
        // Send FCM notification after database notification is saved
        $this->sendFcmNotification($notifiable);

        return new DatabaseMessage($this->toArray($notifiable));
    }

    /**
     * Send FCM push notification to admin's devices
     */
    private function sendFcmNotification($notifiable)
    {
        try {
            $title = 'New Instructor Application';
            $message = $this->user->name . ' has submitted their instructor application for review.';

            // Prepare FCM data
            $fcmData = [
                'title' => $title,
                'body' => $message,
                'type' => 'instructor_submission',
                'instructor_id' => (string) $this->instructor->id,
                'user_id' => (string) $this->user->id,
                'user_name' => $this->user->name,
                'instructor_type' => $this->instructor->type,
            ];

            // Get admin's FCM tokens with platform type
            $adminTokens = UserFcmToken::where('user_id', $notifiable->id)->select('fcm_token', 'platform_type')->get();

            if ($adminTokens->isEmpty()) {
                Log::info('No FCM tokens found for admin', ['admin_id' => $notifiable->id]);
                return;
            }

            Log::info('Sending FCM notification for instructor submission', [
                'admin_id' => $notifiable->id,
                'instructor_id' => $this->instructor->id,
                'tokens_count' => $adminTokens->count(),
            ]);

            // Send FCM notification to each token
            foreach ($adminTokens as $adminToken) {
                try {
                    $platform = $this->mapPlatformType($adminToken->platform_type);
                    Log::debug('Sending FCM instructor submission', [
                        'token' => substr((string) $adminToken->fcm_token, 0, 20) . '...',
                        'platform' => $platform,
                    ]);
                    FirebaseHelper::send($platform, $adminToken->fcm_token, $fcmData, true);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM notification for instructor submission', [
                        'admin_id' => $notifiable->id,
                        'token' => substr((string) $adminToken->fcm_token, 0, 20) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send FCM notification for instructor submission', [
                'admin_id' => $notifiable->id ?? null,
                'instructor_id' => $this->instructor->id ?? null,
                'error' => $e->getMessage(),
            ]);

            // Don't throw - let notification complete even if FCM fails
        }
    }

    /**
     * Map platform_type from database to FirebaseHelper platform format
     *
     * @param string|null $platformType
     * @return string
     */
    private function mapPlatformType($platformType)
    {
        if (empty($platformType)) {
            return 'android'; // Default to android if platform_type is null
        }

        $platformMap = [
            'Android' => 'android',
            'iOS' => 'ios',
            'android' => 'android',
            'ios' => 'ios',
            'web' => 'web',
        ];

        return $platformMap[$platformType] ?? 'android';
    }
}
