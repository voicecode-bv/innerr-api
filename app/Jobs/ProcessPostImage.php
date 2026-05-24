<?php

namespace App\Jobs;

use App\Enums\MediaStatus;
use App\Models\PostMedia;
use App\Services\MediaUploadService;
use App\Support\MediaUrl;
use App\Support\UserStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate the display image and thumbnails for a post photo that was stored
 * untouched during upload. Running this on the queue keeps the HEIC decode and
 * resizes out of the create-post request, so multi-photo posts no longer blow
 * the request execution-time limit. Mirrors the TranscodeVideo flow: the row
 * starts as `Processing` with `path` pointing at the raw upload and flips to
 * `Ready` once the variants exist.
 */
class ProcessPostImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    // Stays under the redis connection's retry_after (90s) so a slow job is
    // never re-dispatched while still running. Image processing takes seconds;
    // this is purely a safety ceiling.
    public int $timeout = 60;

    public function __construct(
        public PostMedia $postMedia,
    ) {}

    public function handle(MediaUploadService $media): void
    {
        $disk = MediaUrl::disk();
        $rawPath = $this->postMedia->path;

        if (! $disk->exists($rawPath)) {
            Log::error("ProcessPostImage: source file not found for post media {$this->postMedia->id}", [
                'path' => $rawPath,
            ]);
            $this->postMedia->update(['status' => MediaStatus::Failed]);

            return;
        }

        try {
            $result = $media->processStoredPostImage($rawPath, $this->postMedia->post->user_id, 'posts');
        } catch (\Throwable $e) {
            Log::error("ProcessPostImage: processing failed for post media {$this->postMedia->id}", [
                'message' => $e->getMessage(),
            ]);

            // Fall back to serving the untouched upload so the user keeps their
            // photo; just mark it ready instead of leaving it stuck processing.
            $this->postMedia->update(['status' => MediaStatus::Ready]);

            return;
        }

        $this->postMedia->update([
            'path' => $result['path'],
            'original_path' => $result['original_path'],
            'thumbnail_path' => $result['thumbnail_path'],
            'thumbnail_small_path' => $result['thumbnail_small_path'],
            'status' => MediaStatus::Ready,
        ]);

        // The pre-conversion upload is superseded by the generated display
        // image and the archived JPEG original; drop it to reclaim the quota.
        UserStorage::trackDelete($rawPath, $disk);
        $disk->delete($rawPath);
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("ProcessPostImage: job permanently failed for post media {$this->postMedia->id}", [
            'message' => $exception?->getMessage(),
        ]);

        $this->postMedia->update(['status' => MediaStatus::Ready]);
    }
}
