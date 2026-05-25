<?php

namespace App\Support;

use App\Http\Controllers\Api\MediaHlsController;
use App\Services\BunnyCdn\UrlSigner as BunnyCdnUrlSigner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Ramsey\Uuid\Uuid;

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
        } elseif (preg_match('#^https?://#', $path)) {
            // External URL (e.g. picsum.photos seed data) — pass through unchanged.
            return $path;
        }

        $expires = static::expiry();

        // HLS-master playlist: één directory-token authoriseert master.m3u8 +
        // alle variant-playlists + segments onder dezelfde prefix. Eén signed
        // URL is genoeg om de hele stream af te spelen — anders zou elke
        // segment een eigen signed URL nodig hebben.
        if (str_ends_with($path, '.m3u8')) {
            return static::signHlsMaster($path, $expires);
        }

        $bunny = BunnyCdnUrlSigner::fromConfig();

        if ($bunny !== null) {
            return $bunny->sign($path, $expires);
        }

        $disk = static::disk();

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
     * Sign een HLS master playlist door een korte cache-token uit te geven
     * die het pad-prefix bevat, en de URL naar onze MediaHlsController te
     * laten wijzen. Die controller serveert master + variants met embedded
     * signed URLs zodat de player niet vastloopt op BunnyCDN's token-auth
     * (relative URL resolution dropt de query string van de base; segments
     * komen anders zonder token aan).
     *
     * Segments + init.mp4 worden binnen die controller naar direct-signed
     * BunnyCDN URLs herschreven — die hitten dus gewoon de edge cache.
     */
    protected static function signHlsMaster(string $path, \DateTimeInterface $expires): string
    {
        $directory = dirname($path).'/';
        $filename = basename($path);

        // Deterministic per (directory, expiry window): an identical HLS video
        // yields the same proxy URL within the day window, so the client can
        // cache the master playlist and the cache holds one entry per video per
        // window instead of minting a fresh random token on every API response.
        $token = Uuid::uuid5(Uuid::NAMESPACE_URL, $directory.'|'.$expires->getTimestamp())->toString();
        Cache::put(
            MediaHlsController::TOKEN_CACHE_PREFIX.$token,
            ['prefix' => $directory],
            $expires,
        );

        return url('/api/media/hls/'.$token.'/'.$filename);
    }

    /**
     * Expiry voor HLS-segment URLs en de master-token. Identiek aan de
     * algemene expiry() — public zodat MediaHlsController dezelfde window
     * gebruikt voor zijn rewrite.
     */
    public static function expiryForHls(): \DateTimeInterface
    {
        return static::expiry();
    }

    /**
     * Map a display media path to the untouched original under the user's
     * `originals/` folder. Returns null when the input doesn't match a
     * user-scoped display path (e.g. legacy uploads or already-original paths).
     */
    public static function originalPath(?string $displayPath): ?string
    {
        if ($displayPath === null) {
            return null;
        }

        $originalPath = preg_replace(
            '#^(users/[0-9a-f-]{36})/(?!originals/)(.+)$#',
            '$1/originals/$2',
            $displayPath,
        );

        if ($originalPath === null || $originalPath === $displayPath) {
            return null;
        }

        return $originalPath;
    }

    /**
     * Stable expiry that snaps to the start of the day, so identical paths
     * produce identical signed URLs within a single day window while the URL
     * stays valid for up to 48 hours. The day-stable URL lets browsers and
     * the Bunny CDN edge cache by URL for a full day instead of rotating
     * hourly, so devices re-download unchanged media far less often.
     */
    protected static function expiry(): \DateTimeInterface
    {
        return now()->startOfDay()->addDays(2);
    }
}
