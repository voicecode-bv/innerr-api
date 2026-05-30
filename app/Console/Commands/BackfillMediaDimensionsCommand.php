<?php

namespace App\Console\Commands;

use App\Enums\MediaStatus;
use App\Jobs\BackfillMediaDimensions;
use App\Models\PostMedia;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Queue dimension backfill for every ready post_media row missing width/height.
 *
 * Walks the table with a keyset cursor (chunkById) so it scales to millions of
 * rows without loading them all, dispatching one BackfillMediaDimensions job
 * per chunk. The actual file reads happen on the queue, keeping this command's
 * footprint to id lookups only.
 */
class BackfillMediaDimensionsCommand extends Command
{
    protected $signature = 'media:backfill-dimensions {--chunk=200 : Number of media rows per queued job}';

    protected $description = 'Queue jobs to backfill width/height on existing post media that predates dimension capture.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dispatched = 0;

        PostMedia::query()
            ->whereNull('width')
            ->where('status', MediaStatus::Ready)
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $rows) use (&$dispatched): void {
                BackfillMediaDimensions::dispatch($rows->pluck('id')->all());
                $dispatched += $rows->count();
            });

        $this->info("Queued dimension backfill for {$dispatched} media rows.");

        return self::SUCCESS;
    }
}
