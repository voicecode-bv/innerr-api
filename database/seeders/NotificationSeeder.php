<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use App\Notifications\CommentLiked;
use App\Notifications\PostCommented;
use App\Notifications\PostLiked;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::all();
        $posts = Post::with('user')->get();
        $comments = Comment::with('user')->get();

        // Post liked notifications
        Like::where('likeable_type', Post::class)
            ->with(['user', 'likeable.user'])
            ->get()
            ->each(function (Like $like) {
                $post = $like->likeable;

                if ($like->user_id !== $post->user_id) {
                    $post->user->notify(new PostLiked($like->user, $post));
                }
            });

        // Post commented notifications
        $comments->each(function (Comment $comment) use ($posts) {
            $post = $posts->find($comment->post_id);

            if ($comment->user_id !== $post->user_id) {
                $post->user->notify(new PostCommented($comment->user, $post, $comment));
            }
        });

        // Comment liked notifications
        Like::where('likeable_type', Comment::class)
            ->with(['user', 'likeable.user'])
            ->get()
            ->each(function (Like $like) {
                $comment = $like->likeable;

                if ($like->user_id !== $comment->user_id) {
                    $comment->user->notify(new CommentLiked($like->user, $comment));
                }
            });

        // Mark ~40% as read
        $notifications = DatabaseNotification::all();
        $notifications->random((int) ($notifications->count() * 0.4))
            ->each->markAsRead();
    }
}
