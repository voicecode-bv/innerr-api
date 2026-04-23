<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CommentLiked extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $liker,
        public Comment $comment,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->fcm_token) && $notifiable->wantsPushNotification(NotificationPreference::CommentLiked)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: $this->liker->name,
            body: __('liked your comment'),
        )))->data([
            'type' => 'comment-liked',
            'comment_id' => (string) $this->comment->id,
            'post_id' => (string) $this->comment->post_id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'comment-liked';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->liker->id,
            'user_name' => $this->liker->name,
            'user_username' => $this->liker->username,
            'user_avatar' => $this->liker->avatar,
            'user_avatar_thumbnail' => $this->liker->avatar_thumbnail,
            'comment_id' => $this->comment->id,
            'comment_body' => $this->comment->body,
            'post_id' => $this->comment->post_id,
        ];
    }
}
