<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

class GdprExportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $path,
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
        $hours = (int) config('gdpr.export.expiry_hours');

        $url = Storage::temporaryUrl($this->path, now()->addHours($hours));

        return (new MailMessage)
            ->subject(__('Your data export is ready'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__('Your personal data export is ready to download.'))
            ->action(__('Download your data'), $url)
            ->line(__('This link expires in :hours hours.', ['hours' => $hours]))
            ->line(__('If you did not request this, you can ignore this email.'));
    }
}
