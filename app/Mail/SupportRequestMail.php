<?php

namespace App\Mail;

use App\Mail\EmailTemplates\EmailTemplateRegistry;
use App\Mail\EmailTemplates\EmailTemplateRenderer;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportRequestMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    // The support inbox is Dutch, so the internal template is rendered in NL
    // regardless of the sender's app locale.
    private const LOCALE = 'nl';

    public function __construct(
        public string $supportMessage,
        public ?string $appVersion,
        public ?string $platform,
        public User $sender,
    ) {}

    public function envelope(): Envelope
    {
        // Reply-To the sender so support can answer the user directly.
        return new Envelope(
            subject: $this->renderedTemplate()['subject'],
            replyTo: [new Address($this->sender->email, $this->sender->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.templated',
            with: ['body' => $this->renderedTemplate()['body']],
        );
    }

    /**
     * Render the Filament-managed support template with the request details.
     *
     * @return array{subject: string, body: string}
     */
    private function renderedTemplate(): array
    {
        return app(EmailTemplateRenderer::class)->render(
            EmailTemplateRegistry::SUPPORT_REQUEST,
            [
                'sender_name' => $this->sender->name,
                'sender_email' => $this->sender->email,
                'app_version' => $this->appVersion ?: 'onbekend',
                'platform' => $this->platform ?: 'onbekend',
                'message' => $this->supportMessage,
            ],
            self::LOCALE,
        );
    }
}
