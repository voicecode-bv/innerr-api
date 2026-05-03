<?php

namespace App\Console\Commands\Storage;

use App\Models\User;
use App\Support\MediaUrl;
use App\Support\UserStorage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use League\Flysystem\DirectoryListing;
use League\Flysystem\StorageAttributes;

#[Signature('storage:reconcile-user-usage {--user= : Reconcile a single user id only}')]
#[Description('Recalculate users.storage_used_bytes from object storage by listing each user\'s files.')]
class ReconcileUserStorageUsage extends Command
{
    public function handle(): int
    {
        $disk = MediaUrl::disk();
        $singleUserId = $this->option('user') !== null ? (string) $this->option('user') : null;

        if ($singleUserId !== null) {
            return $this->reconcileSingle($disk, $singleUserId);
        }

        return $this->reconcileAll($disk);
    }

    /**
     * Reconcile one user by listing files under their prefix only. Cheap when
     * called individually (e.g. after a known drift).
     */
    private function reconcileSingle(Filesystem $disk, string $userId): int
    {
        if (User::whereKey($userId)->doesntExist()) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        $bytes = $this->sumPrefix($disk, "users/{$userId}");

        User::whereKey($userId)->update(['storage_used_bytes' => $bytes]);

        $this->info("User {$userId}: {$bytes} bytes.");

        return self::SUCCESS;
    }

    /**
     * Reconcile every user with a single recursive listing of the `users/`
     * prefix. Sizes come back from the bucket listing so we avoid one
     * round-trip per file.
     */
    private function reconcileAll(Filesystem $disk): int
    {
        $perUser = [];
        $files = 0;

        /** @var DirectoryListing<StorageAttributes> $listing */
        $listing = $disk->getDriver()->listContents('users', deep: true);

        foreach ($listing as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $userId = UserStorage::userIdFromPath($item->path());

            if ($userId === null) {
                continue;
            }

            $perUser[$userId] = ($perUser[$userId] ?? 0) + (int) ($item->fileSize() ?? 0);
            $files++;
        }

        $this->info("Found {$files} files across ".count($perUser).' users.');

        // Zero out users that no longer have files.
        User::query()
            ->when($perUser !== [], fn ($q) => $q->whereNotIn('id', array_keys($perUser)))
            ->where('storage_used_bytes', '>', 0)
            ->update(['storage_used_bytes' => 0]);

        // Bulk-update users with files via a single CASE statement per chunk.
        foreach (array_chunk($perUser, 500, preserve_keys: true) as $chunk) {
            $this->bulkUpdate($chunk);
        }

        $this->info('Reconciliation complete.');

        return self::SUCCESS;
    }

    private function sumPrefix(Filesystem $disk, string $prefix): int
    {
        $bytes = 0;

        /** @var DirectoryListing<StorageAttributes> $listing */
        $listing = $disk->getDriver()->listContents($prefix, deep: true);

        foreach ($listing as $item) {
            if ($item->isFile()) {
                $bytes += (int) ($item->fileSize() ?? 0);
            }
        }

        return $bytes;
    }

    /**
     * @param  array<string, int>  $usage  user id => bytes
     */
    private function bulkUpdate(array $usage): void
    {
        if ($usage === []) {
            return;
        }

        $cases = [];
        $bindings = [];

        foreach ($usage as $userId => $bytes) {
            $cases[] = 'WHEN ?::uuid THEN ?';
            $bindings[] = $userId;
            $bindings[] = $bytes;
        }

        $placeholders = implode(',', array_fill(0, count($usage), '?::uuid'));
        $caseSql = implode(' ', $cases);

        DB::update(
            "UPDATE users SET storage_used_bytes = (CASE id {$caseSql} END)::bigint WHERE id IN ({$placeholders})",
            [...$bindings, ...array_keys($usage)],
        );
    }
}
