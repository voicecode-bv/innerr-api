<?php

namespace App\Mail;

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
            subject: 'Support request from '.$this->sender->name,
            replyTo: [new Address($this->sender->email, $this->sender->name)],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support-request',
            with: [
                'supportMessage' => $this->supportMessage,
                'appVersion' => $this->appVersion,
                'platform' => $this->platform,
                'sender' => $this->sender,
            ],
        );
    }
}
