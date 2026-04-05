<?php

namespace App\Notifications;

use App\Models\CircleInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CircleInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public CircleInvitation $invitation,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviterName = $this->invitation->inviter->name;
        $circleName = $this->invitation->circle->name;

        return (new MailMessage)
            ->subject("You've been invited to join {$circleName}")
            ->greeting('Hello!')
            ->line("{$inviterName} has invited you to join the circle \"{$circleName}\".")
            ->line('If you don\'t have an account yet, please register first.');
    }
}
