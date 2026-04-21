<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('gdpr:cleanup-exports')]
#[Description('Delete GDPR data export files whose signed download link has expired.')]
class CleanupGdprExports extends Command
{
    public function handle(): int
    {
        $disk = Storage::disk(config('gdpr.export.disk'));
        $directory = config('gdpr.export.directory');
        $threshold = now()->subHours((int) config('gdpr.export.expiry_hours'))->getTimestamp();

        $deleted = 0;

        foreach ($disk->allFiles($directory) as $file) {
            if ($disk->lastModified($file) < $threshold) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Deleted {$deleted} expired GDPR export file(s).");

        return self::SUCCESS;
    }
}
