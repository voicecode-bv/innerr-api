<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PostCommented extends Notification
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
        return ['database'];
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
