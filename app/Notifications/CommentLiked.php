<?php

namespace App\Notifications;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CommentLiked extends Notification
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
        return ['database'];
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
            'comment_id' => $this->comment->id,
            'comment_body' => $this->comment->body,
            'post_id' => $this->comment->post_id,
        ];
    }
}
