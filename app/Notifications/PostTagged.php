<?php

namespace App\Notifications;

use App\Enums\NotificationPreference;
use App\Models\Post;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class PostTagged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $poster,
        public Post $post,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (! empty($notifiable->fcm_token) && $notifiable->wantsPushNotification(NotificationPreference::PostTagged)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: $this->poster->name,
            body: __('tagged you in a post'),
        )))->data([
            'type' => 'post-tagged',
            'post_id' => (string) $this->post->id,
            'user_id' => (string) $this->poster->id,
        ]);
    }

    public function databaseType(object $notifiable): string
    {
        return 'post-tagged';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->poster->id,
            'user_name' => $this->poster->name,
            'user_username' => $this->poster->username,
            'user_avatar' => $this->poster->avatar,
            'user_avatar_thumbnail' => $this->poster->avatar_thumbnail,
            'post_id' => $this->post->id,
            'post_media_url' => $this->post->media_url,
            'post_thumbnail_small_url' => $this->post->thumbnail_small_url,
        ];
    }
}
