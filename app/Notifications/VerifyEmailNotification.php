<?php

namespace App\Notifications;

use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Mail\EmailTemplates\EmailTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $code,
        public int $minutes,
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
        $rendered = app(EmailTemplateRenderer::class)->render(
            EmailTemplateRegistry::EMAIL_VERIFICATION,
            [
                'recipient_name' => $notifiable->name,
                'code' => $this->code,
                'minutes' => $this->minutes,
            ],
        );

        return (new MailMessage)
            ->subject($rendered['subject'])
            ->markdown('emails.templated', ['body' => $rendered['body']]);
    }
}
