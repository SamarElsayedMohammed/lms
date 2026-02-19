<?php

namespace App\Notifications;

use App\Helpers\FirebaseHelper;
use App\Models\Instructor;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class InstructorStatusUpdateNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $instructor;
    protected $status;
    protected $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct(Instructor $instructor, string $status, null|string $reason = null)
    {
        $this->instructor = $instructor;
        $this->status = $status;
        $this->reason = $reason;
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
        $title = ucfirst((string) $this->status) . ' - Instructor Application';
        $message = $this->getStatusMessage();

        return (new MailMessage())
            ->subject($title)
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line($message)
            ->when($this->reason, fn($mail) => $mail->line('Reason: ' . $this->reason))
            ->line('Thank you for being part of our platform!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $title = $this->getStatusTitle();
        $message = $this->getStatusMessage();

        return [
            'type' => 'instructor_status_update',
            'title' => $title,
            'message' => $message,
            'instructor_id' => $this->instructor->id,
            'status' => $this->status,
            'reason' => $this->reason,
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
     * Get status title
     */
    private function getStatusTitle()
    {
        return match ($this->status) {
            'approved' => 'Instructor Application Approved',
            'rejected' => 'Instructor Application Rejected',
            'suspended' => 'Instructor Account Suspended',
            default => 'Instructor Status Updated',
        };
    }

    /**
     * Get status message
     */
    private function getStatusMessage()
    {
        return match ($this->status) {
            'approved'
                => 'Congratulations! Your instructor application has been approved. You can now start creating courses.',
            'rejected' => 'Your instructor application has been rejected.',
            'suspended' => 'Your instructor account has been suspended.',
            default => 'Your instructor status has been updated.',
        };
    }

    /**
     * Send FCM push notification to instructor's devices
     */
    private function sendFcmNotification($notifiable)
    {
        try {
            $title = $this->getStatusTitle();
            $message = $this->getStatusMessage();

            // Prepare FCM data
            $fcmData = [
                'title' => $title,
                'body' => $message,
                'type' => 'instructor_status_update',
                'instructor_id' => (string) $this->instructor->id,
                'status' => $this->status,
                'reason' => $this->reason ?? '',
            ];

            // Get instructor's FCM tokens with platform type
            $instructorTokens = UserFcmToken::where('user_id', $notifiable->id)->select(
                'fcm_token',
                'platform_type',
            )->get();

            if ($instructorTokens->isEmpty()) {
                Log::info('No FCM tokens found for instructor', ['instructor_id' => $notifiable->id]);
                return;
            }

            Log::info('Sending FCM notification for instructor status update', [
                'instructor_id' => $notifiable->id,
                'status' => $this->status,
                'tokens_count' => $instructorTokens->count(),
            ]);

            // Send FCM notification to each token
            foreach ($instructorTokens as $instructorToken) {
                try {
                    $platform = $this->mapPlatformType($instructorToken->platform_type);
                    Log::debug('Sending FCM instructor status update', [
                        'token' => substr((string) $instructorToken->fcm_token, 0, 20) . '...',
                        'platform' => $platform,
                        'status' => $this->status,
                    ]);
                    FirebaseHelper::send($platform, $instructorToken->fcm_token, $fcmData, true);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM notification for instructor status update', [
                        'instructor_id' => $notifiable->id,
                        'token' => substr((string) $instructorToken->fcm_token, 0, 20) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send FCM notification for instructor status update', [
                'instructor_id' => $notifiable->id ?? null,
                'status' => $this->status ?? null,
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
