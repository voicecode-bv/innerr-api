<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('thumbnails:backfill-small {--chunk=200 : Number of records to process per chunk}')]
#[Description('Generate 150x150 thumbnails for image posts and user avatars that do not have one yet.')]
class BackfillSmallThumbnails extends Command
{
    public function handle(MediaUploadService $media): int
    {
        $chunk = (int) $this->option('chunk');

        $this->backfillPosts($media, $chunk);
        $this->backfillAvatars($media, $chunk);

        return self::SUCCESS;
    }

    private function backfillPosts(MediaUploadService $media, int $chunk): void
    {
        $query = Post::query()
            ->where('media_type', 'image')
            ->whereNull('thumbnail_small_url')
            ->whereNotNull('media_url');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No image posts without a small thumbnail.');

            return;
        }

        $this->info("Backfilling small thumbnails for {$total} image posts...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $generated = 0;
        $skipped = 0;

        $query->chunkById($chunk, function ($posts) use ($media, $bar, &$generated, &$skipped) {
            foreach ($posts as $post) {
                $path = $media->generateImageThumbnailFromPath(
                    $post->media_url, $post->user_id, 'posts', size: 150,
                );

                if ($path === null) {
                    $skipped++;
                } else {
                    $post->forceFill(['thumbnail_small_url' => $path])->save();
                    $generated++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Posts — generated: {$generated}");

        if ($skipped > 0) {
            $this->warn("Posts — skipped (source missing or decode failed): {$skipped}");
        }
    }

    private function backfillAvatars(MediaUploadService $media, int $chunk): void
    {
        $query = User::query()
            ->whereNotNull('avatar')
            ->whereNull('avatar_thumbnail');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No avatars without a small thumbnail.');

            return;
        }

        $this->info("Backfilling small thumbnails for {$total} avatars...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $generated = 0;
        $skipped = 0;

        $query->chunkById($chunk, function ($users) use ($media, $bar, &$generated, &$skipped) {
            foreach ($users as $user) {
                $path = $media->generateImageThumbnailFromPath(
                    $user->avatar, $user->id, 'avatars', size: 150,
                );

                if ($path === null) {
                    $skipped++;
                } else {
                    $user->forceFill(['avatar_thumbnail' => $path])->save();
                    $generated++;
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Avatars — generated: {$generated}");

        if ($skipped > 0) {
            $this->warn("Avatars — skipped (source missing or decode failed): {$skipped}");
        }
    }
}
