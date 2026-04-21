<?php

namespace App\Notifications;

use App\Models\CircleInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CircleMemberInvitedByMemberNotification extends Notification implements ShouldQueue
{
    use Queueable;

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

        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: __('New invitation in :circle', [
                'circle' => $this->invitation->circle->name,
            ]),
            body: __(':inviter invited :invitee', [
                'inviter' => $this->inviterName,
                'invitee' => $this->inviteeLabel,
            ]),
        )))->data([
            'type' => 'circle-member-invited-by-member',
            'circle_id' => (string) $this->invitation->circle_id,
        ]);
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
