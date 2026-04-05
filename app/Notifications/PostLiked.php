<?php

namespace App\Notifications;

use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PostLiked extends Notification
{
    use Queueable;

    public function __construct(
        public User $liker,
        public Post $post,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'post-liked';
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
            'post_id' => $this->post->id,
            'post_media_url' => $this->post->media_url,
        ];
    }
}
