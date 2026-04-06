<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MediaUrl
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.media'));
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

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($path, now()->addMinutes(60));
            } catch (\RuntimeException) {
                // Local disk doesn't support temporaryUrl, fall through
            }
        }

        return URL::signedRoute('api.media', ['path' => $path], now()->addMinutes(60));
    }
}
