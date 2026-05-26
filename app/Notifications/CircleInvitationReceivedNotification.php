<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Models\CircleInvitation;
use App\Notifications\Concerns\SetsBadgeCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CircleInvitationReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable, SetsBadgeCount;

    public function __construct(
        public CircleInvitation $invitation,
        public string $inviterName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->deviceTokens()->exists() && $notifiable->wantsPushNotification(NotificationPreference::CircleInvitationReceived)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return $this->withBadgeCount((new FcmMessage(notification: new FcmNotification(
            title: __('New invitation in :circle', [
                'circle' => $this->invitation->circle->name,
            ]),
            body: __(':name invited you', [
                'name' => $this->inviterName,
            ]),
        )))->data([
            'type' => 'circle-invitation-received',
            'link' => '/notifications',
            'circle_id' => (string) $this->invitation->circle_id,
            'invitation_id' => (string) $this->invitation->id,
        ]), $notifiable);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-invitation-received';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'circle_id' => $this->invitation->circle_id,
            'circle_name' => $this->invitation->circle->name,
            'inviter_id' => $this->invitation->inviter_id,
            'inviter_name' => $this->inviterName,
        ];
    }
}
