<?php

namespace App\Notifications;

use App\Helpers\FirebaseHelper;
use App\Models\User;
use App\Models\UserFcmToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class TeamInvitationResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $user;
    protected $action;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        User $user,
        string $action,
        protected $teamMember,
    ) {
        $this->user = $user;
        $this->action = $action;
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
        $message = $this->action === 'accepted'
            ? $this->user->name . ' has accepted your team invitation.'
            : $this->user->name . ' has rejected your team invitation.';

        return (new MailMessage())
            ->subject('Team Invitation ' . ucfirst((string) $this->action))
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line($message)
            ->line('Thank you for being part of our platform!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $title = $this->action === 'accepted' ? 'Team Invitation Accepted' : 'Team Invitation Rejected';

        $message = $this->action === 'accepted'
            ? $this->user->name . ' has accepted your team invitation and has been added to your team.'
            : $this->user->name . ' has rejected your team invitation.';

        return [
            'type' => 'team_invitation_response',
            'title' => $title,
            'message' => $message,
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'action' => $this->action,
            'team_member_id' => $this->teamMember->id,
            'type_id' => $this->teamMember->id,
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
     * Send FCM push notification to instructor's devices
     */
    private function sendFcmNotification($notifiable)
    {
        try {
            $title = $this->action === 'accepted' ? 'Team Invitation Accepted' : 'Team Invitation Rejected';

            $message = $this->action === 'accepted'
                ? $this->user->name . ' has accepted your team invitation and has been added to your team.'
                : $this->user->name . ' has rejected your team invitation.';

            // Prepare FCM data
            $fcmData = [
                'title' => $title,
                'body' => $message,
                'type' => 'team_invitation_response',
                'user_id' => (string) $this->user->id,
                'user_name' => $this->user->name,
                'action' => $this->action,
                'team_member_id' => (string) $this->teamMember->id,
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

            Log::info('Sending FCM notification for team invitation response', [
                'instructor_id' => $notifiable->id,
                'action' => $this->action,
                'tokens_count' => $instructorTokens->count(),
            ]);

            // Send FCM notification to each token
            foreach ($instructorTokens as $instructorToken) {
                try {
                    $platform = $this->mapPlatformType($instructorToken->platform_type);
                    Log::debug('Sending FCM team invitation response', [
                        'token' => substr((string) $instructorToken->fcm_token, 0, 20) . '...',
                        'platform' => $platform,
                        'action' => $this->action,
                    ]);
                    FirebaseHelper::send($platform, $instructorToken->fcm_token, $fcmData, true);
                } catch (\Throwable $e) {
                    Log::warning('Failed to send FCM notification for team invitation response', [
                        'instructor_id' => $notifiable->id,
                        'token' => substr((string) $instructorToken->fcm_token, 0, 20) . '...',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to send FCM notification for team invitation response', [
                'instructor_id' => $notifiable->id ?? null,
                'action' => $this->action ?? null,
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
