<?php

namespace App\Notifications;

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

class PostCommented extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $commenter,
        public Post $post,
        public Comment $comment,
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
            title: $this->commenter->name,
            body: Str::limit($this->comment->body, 120),
        )))->data([
            'type' => 'post-commented',
            'post_id' => (string) $this->post->id,
            'comment_id' => (string) $this->comment->id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'post-commented';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->commenter->id,
            'user_name' => $this->commenter->name,
            'user_username' => $this->commenter->username,
            'user_avatar' => $this->commenter->avatar,
            'post_id' => $this->post->id,
            'post_media_url' => $this->post->media_url,
            'comment_id' => $this->comment->id,
            'comment_body' => $this->comment->body,
        ];
    }
}
