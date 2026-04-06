<?php

namespace App\Support;

use Illuminate\Support\Facades\URL;

class MediaUrl
{
    public static function sign(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (preg_match('#/storage/(.+)$#', $path, $matches)) {
            $path = $matches[1];
        }

        return URL::signedRoute('api.media', ['path' => $path], now()->addMinutes(60));
    }
}
