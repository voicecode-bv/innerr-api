<?php

use App\Http\Controllers\Api\DocumentationController;
use App\Models\User;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {});

Route::get('/api/docs', [DocumentationController::class, 'ui'])->name('api.docs');
Route::get('/api/docs/openapi.json', [DocumentationController::class, 'spec'])->name('api.docs.spec');

Route::get('test', function () {
    $userId = User::query()->value('id');

    if ($userId !== null) {
        auth()->loginUsingId($userId);
    }
});

Route::get('/mail/preview', function (): string {
    $message = (new MailMessage)
        ->subject(__('Welcome to Innerr'))
        ->greeting(__('Hello!'))
        ->line(__('This is a preview of the Innerr email template.'))
        ->line(__('Headings below should render in Fraunces, body copy in DM Sans.'))
        ->action(__('Open Innerr'), config('app.url'))
        ->line(__('Thanks for being part of the circle.'));

    return app(Markdown::class)->render(
        $message->markdown ?? 'notifications::email',
        $message->data(),
    );
})->name('mail.preview');
