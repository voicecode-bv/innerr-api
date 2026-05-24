<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Mail\EmailTemplates\EmailTemplateRenderer;
use App\Models\CircleOwnershipTransfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CircleOwnershipTransferRequestedNotification extends Notification implements ShouldQueue
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

        if ($notifiable->deviceTokens()->exists() && $notifiable->wantsPushNotification(NotificationPreference::CircleOwnershipTransferRequested)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(EmailTemplateRenderer::class)->render(
            EmailTemplateRegistry::CIRCLE_OWNERSHIP_TRANSFER_REQUESTED,
            [
                'recipient_name' => $notifiable->name,
                'from_name' => $this->transfer->fromUser->name,
                'circle_name' => $this->transfer->circle->name,
            ],
        );

        return (new MailMessage)
            ->subject($rendered['subject'])
            ->markdown('emails.templated', ['body' => $rendered['body']]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: __('Ownership transfer requested'),
            body: __(':name wants to transfer ":circle" to you', [
                'name' => $this->transfer->fromUser->name,
                'circle' => $this->transfer->circle->name,
            ]),
        )))->data([
            'type' => 'circle-ownership-transfer-requested',
            'link' => '/notifications',
            'circle_id' => (string) $this->transfer->circle_id,
            'transfer_id' => (string) $this->transfer->id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-ownership-transfer-requested';
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
            'from_user_id' => $this->transfer->from_user_id,
            'from_user_name' => $this->transfer->fromUser->name,
            'from_user_username' => $this->transfer->fromUser->username,
            'from_user_avatar' => $this->transfer->fromUser->avatar,
            'from_user_avatar_thumbnail' => $this->transfer->fromUser->avatar_thumbnail,
        ];
    }
}
