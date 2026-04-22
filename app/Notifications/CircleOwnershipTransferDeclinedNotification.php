<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Models\CircleOwnershipTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CircleOwnershipTransferDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CircleOwnershipTransfer $transfer,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if (! empty($notifiable->fcm_token) && $notifiable->wantsPushNotification(NotificationPreference::CircleOwnershipTransferDeclined)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $toName = $this->transfer->toUser->name;
        $circleName = $this->transfer->circle->name;

        return (new MailMessage)
            ->subject(__(':name declined ownership of :circle', ['name' => $toName, 'circle' => $circleName]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':name declined the ownership transfer of the circle ":circle". You remain the owner.', [
                'name' => $toName,
                'circle' => $circleName,
            ]));
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: __('Ownership transfer declined'),
            body: __(':name declined ownership of :circle', [
                'name' => $this->transfer->toUser->name,
                'circle' => $this->transfer->circle->name,
            ]),
        )))->data([
            'type' => 'circle-ownership-transfer-declined',
            'circle_id' => (string) $this->transfer->circle_id,
            'transfer_id' => (string) $this->transfer->id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-ownership-transfer-declined';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'transfer_id' => $this->transfer->id,
            'circle_id' => $this->transfer->circle_id,
            'circle_name' => $this->transfer->circle->name,
            'to_user_id' => $this->transfer->to_user_id,
            'to_user_name' => $this->transfer->toUser->name,
            'to_user_username' => $this->transfer->toUser->username,
            'to_user_avatar' => $this->transfer->toUser->avatar,
        ];
    }
}
