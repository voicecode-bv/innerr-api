<?php

namespace App\Notifications;

use App\Models\CircleInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CircleInvitationAcceptedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CircleInvitation $invitation,
        public string $acceptedByName,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->invitation->user_id,
            'user_name' => $this->acceptedByName,
            'user_username' => $this->invitation->user?->username,
            'user_avatar' => $this->invitation->user?->avatar,
            'circle_id' => $this->invitation->circle_id,
            'circle_name' => $this->invitation->circle->name,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $circleName = $this->invitation->circle->name;

        return (new MailMessage)
            ->subject("{$this->acceptedByName} has joined {$circleName}")
            ->greeting('Good news!')
            ->line("{$this->acceptedByName} has accepted your invitation and joined the circle \"{$circleName}\".");
    }
}
