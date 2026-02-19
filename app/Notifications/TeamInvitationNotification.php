<?php

namespace App\Notifications;

use App\Helpers\FirebaseHelper;
use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class TeamInvitationNotification extends Notification
{
    use Queueable;

    protected $instructor;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        User $instructor,
        protected $teamMember,
    ) {
        $this->instructor = $instructor;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database']; // Only database notifications for now
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // Ensure token is available - refresh if needed
        $token = $this->teamMember->invitation_token;
        if (empty($token)) {
            // Refresh the model to get the latest token
            $this->teamMember->refresh();
            $token = $this->teamMember->invitation_token;
        }

        return (new MailMessage())
            ->subject('Team Invitation Received')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line($this->instructor->name . ' has invited you to join their team.')
            ->action('View Invitation', url('/api/accept-team-invitation/' . $token))
            ->line('Thank you for being part of our platform!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // Ensure token is available - refresh if needed
        $token = $this->teamMember->invitation_token;
        if (empty($token)) {
            // Refresh the model to get the latest token
            $this->teamMember->refresh();
            $token = $this->teamMember->invitation_token;
        }

        return [
            'type' => 'team_invitation',
            'title' => 'Team Invitation Received',
            'message' => $this->instructor->name . ' has invited you to join their team.',
            'instructor_id' => $this->instructor->id,
            'instructor_name' => $this->instructor->name,
            'team_member_id' => $this->teamMember->id,
            'invitation_token' => $token,
            'type_id' => $this->teamMember->id,
        ];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): DatabaseMessage
    {
        // Return database message - this will save the notification to database
        // FCM notification will be sent separately after database save to avoid blocking
        return new DatabaseMessage($this->toArray($notifiable));
    }

    /**
     * Send FCM push notification to user's devices
     */
    private function sendFcmNotification($notifiable)
    {
        try {
            // Ensure token is available
            $invitationToken = $this->teamMember->invitation_token;
            if (empty($invitationToken)) {
                $this->teamMember->refresh();
                $invitationToken = $this->teamMember->invitation_token;
            }

            // Prepare FCM data
            $fcmData = [
                'title' => 'Team Invitation Received',
                'body' => $this->instructor->name . ' has invited you to join their team.',
                'type' => 'team_invitation',
                'instructor_id' => (string) $this->instructor->id,
                'instructor_name' => $this->instructor->name,
                'team_member_id' => (string) $this->teamMember->id,
                'invitation_token' => $invitationToken,
            ];

            // Get user's FCM tokens with platform type
            $userTokens = UserFcmToken::where('user_id', $notifiable->id)->select('fcm_token', 'platform_type')->get();

            if ($userTokens->isEmpty()) {
                Log::info('No FCM tokens found for user', ['user_id' => $notifiable->id]);
                return;
            }

            Log::info('Sending FCM notification for team invitation', [
                'user_id' => $notifiable->id,
                'tokens_count' => $userTokens->count(),
            ]);

            // Send FCM notification to each token
            foreach ($userTokens as $userToken) {
                try {
                    $platform = $this->mapPlatformType($userToken->platform_type);
                    Log::debug('Sending FCM team invitation', [
                        'token' => substr((string) $userToken->fcm_token, 0, 20) . '...',
                        'platform' => $platform,
                    ]);
                    FirebaseHelper::send($platform, $userToken->fcm_token, $fcmData, true);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM notification for team invitation', [
                        'user_id' => $notifiable->id,
                        'token' => substr((string) $userToken->fcm_token, 0, 20) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send FCM notification for team invitation', [
                'user_id' => $notifiable->id ?? null,
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
