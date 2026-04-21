<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\GdprExportReady;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ExportUserData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public User $user,
    ) {}

    public function handle(): void
    {
        $payload = $this->user->portable();

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $path = sprintf(
            '%s/%s/%s.json',
            config('gdpr.export.directory'),
            $this->user->id,
            Str::ulid(),
        );

        Storage::disk(config('gdpr.export.disk'))->put($path, $json, 'private');

        $this->user->notify(new GdprExportReady($path));
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("ExportUserData: job permanently failed for user {$this->user->id}", [
            'message' => $exception?->getMessage(),
        ]);
    }
}
