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

        return (new MailMessage)
            ->subject(__(':name has invited you', ['name' => $inviterName]))
            ->greeting(__('Hello!'))
            ->line(__(':name has invited you to join their circles.', ['name' => $inviterName]))
            ->line(__("If you don't have an account yet, please register first."));
    }
}
