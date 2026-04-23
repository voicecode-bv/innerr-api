<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Models\CircleInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CircleInvitationAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CircleInvitation $invitation,
        public string $acceptedByName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if (! empty($notifiable->fcm_token) && $notifiable->wantsPushNotification(NotificationPreference::CircleInvitationAccepted)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: __('Invitation accepted'),
            body: __(':name joined :circle', [
                'name' => $this->acceptedByName,
                'circle' => $this->invitation->circle->name,
            ]),
        )))->data([
            'type' => 'circle-invitation-accepted',
            'circle_id' => (string) $this->invitation->circle_id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-invitation-accepted';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->invitation->user_id,
            'user_name' => $this->acceptedByName,
            'user_username' => $this->invitation->user?->username,
            'user_avatar' => $this->invitation->user?->avatar,
            'user_avatar_thumbnail' => $this->invitation->user?->avatar_thumbnail,
            'circle_id' => $this->invitation->circle_id,
            'circle_name' => $this->invitation->circle->name,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $circleName = $this->invitation->circle->name;

        return (new MailMessage)
            ->subject(__(':name has joined :circle', ['name' => $this->acceptedByName, 'circle' => $circleName]))
            ->greeting(__('Good news!'))
            ->line(__(':name has accepted your invitation and joined the circle ":circle".', [
                'name' => $this->acceptedByName,
                'circle' => $circleName,
            ]));
    }
}
