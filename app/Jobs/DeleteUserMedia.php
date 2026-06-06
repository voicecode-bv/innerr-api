<?php

namespace App\Jobs;

use App\Support\MediaUrl;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Wipes a deleted user's entire media folder (`users/{id}`) from object
 * storage. Runs on the queue because the folder can hold many objects and the
 * deletion shouldn't block the request that removed the account. Takes the raw
 * user id (not the model) since the user row no longer exists when this runs.
 */
class DeleteUserMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public string $userId) {}

    public function handle(): void
    {
        MediaUrl::disk()->deleteDirectory("users/{$this->userId}");
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("DeleteUserMedia: failed to wipe media for user {$this->userId}", [
            'message' => $exception?->getMessage(),
        ]);
    }
}
