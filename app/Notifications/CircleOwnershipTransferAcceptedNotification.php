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

class CircleOwnershipTransferAcceptedNotification extends Notification implements ShouldQueue
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

        if ($notifiable->deviceTokens()->exists() && $notifiable->wantsPushNotification(NotificationPreference::CircleOwnershipTransferAccepted)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(EmailTemplateRenderer::class)->render(
            EmailTemplateRegistry::CIRCLE_OWNERSHIP_TRANSFER_ACCEPTED,
            [
                'recipient_name' => $notifiable->name,
                'to_name' => $this->transfer->toUser->name,
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
            title: __('Ownership transfer accepted'),
            body: __(':name is now the owner of :circle', [
                'name' => $this->transfer->toUser->name,
                'circle' => $this->transfer->circle->name,
            ]),
        )))->data([
            'type' => 'circle-ownership-transfer-accepted',
            'link' => '/circles/'.$this->transfer->circle_id,
            'circle_id' => (string) $this->transfer->circle_id,
            'transfer_id' => (string) $this->transfer->id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-ownership-transfer-accepted';
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
            'to_user_avatar_thumbnail' => $this->transfer->toUser->avatar_thumbnail,
        ];
    }
}
