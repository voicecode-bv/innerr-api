<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\GdprExportReady;
use App\Support\MediaUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ExportUserData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public User $user,
    ) {}

    public function handle(): void
    {
        $workDir = storage_path('app/private/tmp/gdpr-'.Str::ulid());
        File::ensureDirectoryExists($workDir);
        $zipPath = $workDir.'/export.zip';

        try {
            $this->buildArchive($zipPath, $workDir);

            $remotePath = sprintf(
                '%s/%s/%s.zip',
                config('gdpr.export.directory'),
                $this->user->id,
                Str::ulid(),
            );

            $stream = fopen($zipPath, 'r');
            Storage::writeStream($remotePath, $stream, ['visibility' => 'private']);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $this->user->notify(new GdprExportReady($remotePath));
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function buildArchive(string $zipPath, string $workDir): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create GDPR export archive.');
        }

        $json = json_encode(
            $this->user->portable(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $zip->addFromString('data.json', $json);

        $mediaDisk = MediaUrl::disk();
        $userPrefix = "users/{$this->user->id}";

        foreach ($mediaDisk->allFiles($userPrefix) as $remotePath) {
            $localTemp = tempnam($workDir, 'm_');
            $in = $mediaDisk->readStream($remotePath);
            $out = fopen($localTemp, 'w');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $relative = 'media/'.ltrim(substr($remotePath, strlen($userPrefix)), '/');
            $zip->addFile($localTemp, $relative);
        }

        $zip->close();
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("ExportUserData: job permanently failed for user {$this->user->id}", [
            'message' => $exception?->getMessage(),
        ]);
    }
}
