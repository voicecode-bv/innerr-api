<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

/**
 * Tracks per-user object-storage consumption.
 *
 * All Hetzner objects live under `users/{id}/...`, so the user that owns a
 * given file can be derived from its path. Updates use atomic SQL expressions
 * to stay correct under concurrent writes, and clamp at zero so a stale
 * delete (e.g. a file removed by reconcile that's also removed by the app)
 * cannot push a user into negative usage.
 */
class UserStorage
{
    /**
     * Adjust a user's tracked usage by the given (signed) byte delta.
     */
    public static function adjust(int $userId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        User::whereKey($userId)->update([
            'storage_used_bytes' => DB::raw('GREATEST(0, storage_used_bytes + ('.$delta.'))'),
        ]);
    }

    /**
     * Track a file that was just written to the disk. The size is read from
     * the disk so we record what was actually persisted.
     */
    public static function trackPut(string $path, ?Filesystem $disk = null): void
    {
        $userId = self::userIdFromPath($path);

        if ($userId === null) {
            return;
        }

        $disk ??= MediaUrl::disk();

        if (! $disk->exists($path)) {
            return;
        }

        self::adjust($userId, (int) $disk->size($path));
    }

    /**
     * Track a file that is about to be deleted. The caller is responsible
     * for performing the actual delete after this call; tracking happens
     * up-front so the size can still be read from the disk.
     */
    public static function trackDelete(string $path, ?Filesystem $disk = null): void
    {
        $userId = self::userIdFromPath($path);

        if ($userId === null) {
            return;
        }

        $disk ??= MediaUrl::disk();

        if (! $disk->exists($path)) {
            return;
        }

        self::adjust($userId, -((int) $disk->size($path)));
    }

    /**
     * Reset a user's tracked usage to zero. Used when wiping the entire
     * users/{id} folder (anonymisation, account deletion).
     */
    public static function reset(int $userId): void
    {
        User::whereKey($userId)->update(['storage_used_bytes' => 0]);
    }

    /**
     * Extract the user id from a `users/{id}/...` storage path.
     */
    public static function userIdFromPath(string $path): ?int
    {
        if (preg_match('#^users/(\d+)/#', $path, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
