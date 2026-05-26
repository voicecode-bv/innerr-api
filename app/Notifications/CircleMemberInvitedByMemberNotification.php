<?php

namespace App\Notifications;

use App\Models\CircleInvitation;
use App\Notifications\Concerns\SetsBadgeCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CircleMemberInvitedByMemberNotification extends Notification implements ShouldQueue
{
    use Queueable, SetsBadgeCount;

    public function __construct(
        public CircleInvitation $invitation,
        public string $inviterName,
        public string $inviteeLabel,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->deviceTokens()->exists()) {
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
            body: __(':inviter invited :invitee', [
                'inviter' => $this->inviterName,
                'invitee' => $this->inviteeLabel,
            ]),
        )))->data([
            'type' => 'circle-member-invited-by-member',
            'link' => '/circles/'.$this->invitation->circle_id,
            'circle_id' => (string) $this->invitation->circle_id,
        ]), $notifiable);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-member-invited-by-member';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'circle_id' => $this->invitation->circle_id,
            'circle_name' => $this->invitation->circle->name,
            'inviter_id' => $this->invitation->inviter_id,
            'inviter_name' => $this->inviterName,
            'invitee_label' => $this->inviteeLabel,
        ];
    }
}
