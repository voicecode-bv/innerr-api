<?php

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\Post;
use App\Services\MediaUploadService;
use App\Support\MediaUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class TranscodeVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 300;

    public function __construct(
        public Post $post,
    ) {}

    public function handle(MediaUploadService $media): void
    {
        $disk = MediaUrl::disk();
        $originalPath = $this->post->media_url;

        if (! $disk->exists($originalPath)) {
            Log::error("TranscodeVideo: source file not found for post {$this->post->id}", [
                'path' => $originalPath,
            ]);
            $this->post->update(['media_status' => MediaStatus::Failed]);

            return;
        }

        // Download the original to a temp file for FFmpeg.
        $tempInput = tempnam(sys_get_temp_dir(), 'transcode_in_');
        file_put_contents($tempInput, $disk->get($originalPath));

        $tempOutput = tempnam(sys_get_temp_dir(), 'transcode_out_').'.mp4';

        try {
            $result = Process::timeout($this->timeout)->run([
                'ffmpeg',
                '-i', $tempInput,
                '-vf', "scale=-2:'min(1080,ih)'",
                '-c:v', 'libx264',
                '-preset', 'medium',
                '-crf', '23',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-movflags', '+faststart',
                '-y',
                $tempOutput,
            ]);

            if ($result->failed() || ! file_exists($tempOutput) || filesize($tempOutput) === 0) {
                Log::error("TranscodeVideo: FFmpeg failed for post {$this->post->id}", [
                    'stderr' => $result->errorOutput(),
                ]);

                // Keep the original as-is; mark as ready so the user can still view it.
                $this->post->update(['media_status' => MediaStatus::Ready]);

                return;
            }

            // Store the transcoded file and move the original to the originals folder.
            $userFolder = "users/{$this->post->user_id}";
            $transcodedFilename = Str::random(40).'.mp4';
            $transcodedPath = "{$userFolder}/posts/{$transcodedFilename}";

            $disk->put($transcodedPath, file_get_contents($tempOutput));

            // Move the original to the originals folder (if it isn't there already).
            if (! str_contains($originalPath, '/originals/')) {
                $originalExtension = pathinfo($originalPath, PATHINFO_EXTENSION) ?: 'mp4';
                $originalFilename = Str::random(40).'.'.$originalExtension;
                $archivedPath = "{$userFolder}/originals/posts/{$originalFilename}";

                $disk->move($originalPath, $archivedPath);
            }

            $this->post->update([
                'media_url' => $transcodedPath,
                'media_status' => MediaStatus::Ready,
            ]);
        } catch (\Throwable $e) {
            Log::error("TranscodeVideo: exception for post {$this->post->id}", [
                'message' => $e->getMessage(),
            ]);

            // On failure, mark ready anyway so the original remains viewable.
            $this->post->update(['media_status' => MediaStatus::Ready]);
        } finally {
            @unlink($tempInput);
            @unlink($tempOutput);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("TranscodeVideo: job permanently failed for post {$this->post->id}", [
            'message' => $exception?->getMessage(),
        ]);

        $this->post->update(['media_status' => MediaStatus::Ready]);
    }
}
