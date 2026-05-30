<?php

namespace App\Jobs;

use App\Models\PostMedia;
use App\Support\MediaUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Backfill orientation-corrected width/height for a batch of existing
 * post_media rows that predate dimension capture.
 *
 * Reads the dimensions off a file we already store rather than re-deriving
 * them from the source: for images that is the display variant (stored
 * post-orientation, so its pixels match what the client renders); for videos
 * it is the poster thumbnail, which is generated with `scaleDown` and so keeps
 * the upright frame's aspect ratio. Both are decoded straight from the disk
 * stream via getimagesizefromstring, avoiding any ffmpeg/Imagick round-trip.
 */
class BackfillMediaDimensions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<int, string>  $mediaIds
     */
    public function __construct(public array $mediaIds) {}

    public function handle(): void
    {
        $disk = MediaUrl::disk();

        PostMedia::query()
            ->whereIn('id', $this->mediaIds)
            ->whereNull('width')
            ->get()
            ->each(function (PostMedia $media) use ($disk): void {
                $source = $media->type === 'video' ? $media->thumbnail_path : $media->path;

                if (! is_string($source) || $source === '' || ! $disk->exists($source)) {
                    return;
                }

                $info = @getimagesizefromstring((string) $disk->get($source));

                if ($info === false || ($info[0] ?? 0) === 0 || ($info[1] ?? 0) === 0) {
                    return;
                }

                $media->update(['width' => $info[0], 'height' => $info[1]]);
            });
    }
}
