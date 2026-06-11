<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Mail\EmailTemplates\EmailTemplateRenderer;
use App\Models\Circle;
use App\Models\User;
use App\Notifications\Concerns\SetsBadgeCount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

/**
 * Sent to the circle owner when someone joins through an invite link. When
 * the circle has no posts yet, the copy nudges the owner to share their
 * first moment so the new member does not land on an empty timeline.
 */
class CircleMemberJoinedNotification extends Notification implements ShouldQueue
{
    use Queueable, SetsBadgeCount;

    public function __construct(
        public Circle $circle,
        public User $joinedUser,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];

        if ($notifiable->deviceTokens()->exists() && $notifiable->wantsPushNotification(NotificationPreference::CircleInvitationAccepted)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    private function circleIsEmpty(): bool
    {
        // The exists-guard keeps unsaved models (unit tests) from querying.
        return $this->circle->exists && $this->circle->posts()->doesntExist();
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $body = $this->circleIsEmpty()
            ? __(':name joined :circle. Share your first moment!', [
                'name' => $this->joinedUser->name,
                'circle' => $this->circle->name,
            ])
            : __(':name joined :circle', [
                'name' => $this->joinedUser->name,
                'circle' => $this->circle->name,
            ]);

        return $this->withBadgeCount((new FcmMessage(notification: new FcmNotification(
            title: __('New member'),
            body: $body,
        )))->data([
            'type' => 'circle-member-joined',
            'link' => $this->circleIsEmpty()
                ? '/posts/create'
                : '/circles/'.$this->circle->id,
            'circle_id' => (string) $this->circle->id,
        ]), $notifiable);
    }

    public function databaseType(object $notifiable): string
    {
        return 'circle-member-joined';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->joinedUser->id,
            'user_name' => $this->joinedUser->name,
            'user_username' => $this->joinedUser->username,
            'user_avatar' => $this->joinedUser->avatar,
            'user_avatar_thumbnail' => $this->joinedUser->avatar_thumbnail,
            'circle_id' => $this->circle->id,
            'circle_name' => $this->circle->name,
            'circle_was_empty' => $this->circleIsEmpty(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = app(EmailTemplateRenderer::class)->render(
            EmailTemplateRegistry::CIRCLE_INVITATION_ACCEPTED,
            [
                'recipient_name' => $notifiable->name,
                'accepted_by_name' => $this->joinedUser->name,
                'circle_name' => $this->circle->name,
            ],
        );

        return (new MailMessage)
            ->subject($rendered['subject'])
            ->markdown('emails.templated', ['body' => $rendered['body']]);
    }
}
