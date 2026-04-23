<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class CommentReplied extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $replier,
        public Post $post,
        public Comment $reply,
        public Comment $parentComment,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->fcm_token) && $notifiable->wantsPushNotification(NotificationPreference::CommentReplied)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: $this->replier->name,
            body: Str::limit($this->reply->body, 120),
        )))->data([
            'type' => 'comment-replied',
            'post_id' => (string) $this->post->id,
            'comment_id' => (string) $this->reply->id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'comment-replied';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->replier->id,
            'user_name' => $this->replier->name,
            'user_username' => $this->replier->username,
            'user_avatar' => $this->replier->avatar,
            'user_avatar_thumbnail' => $this->replier->avatar_thumbnail,
            'post_id' => $this->post->id,
            'post_media_url' => $this->post->media_url,
            'post_thumbnail_small_url' => $this->post->thumbnail_small_url,
            'comment_id' => $this->reply->id,
            'comment_body' => $this->reply->body,
        ];
    }
}
