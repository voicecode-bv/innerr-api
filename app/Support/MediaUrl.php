<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MediaUrl
{
    public static function disk(): Filesystem
    {
        return Storage::disk();
    }

    public static function sign(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (preg_match('#/storage/(.+)$#', $path, $matches)) {
            $path = $matches[1];
        }

        $disk = static::disk();
        $expires = static::expiry();

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($path, $expires);
            } catch (\RuntimeException) {
                // Local disk doesn't support temporaryUrl, fall through
            }
        }

        return URL::signedRoute('api.media', ['path' => $path], $expires);
    }

    /**
     * Stable expiry that snaps to the start of the next hour, so identical
     * paths produce identical signed URLs within a single hour window.
     * This lets browsers and the Spaces CDN cache by URL.
     */
    protected static function expiry(): \DateTimeInterface
    {
        return now()->startOfHour()->addHours(2);
    }
}
