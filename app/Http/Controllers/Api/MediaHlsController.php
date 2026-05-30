<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BunnyCdn\UrlSigner as BunnyCdnUrlSigner;
use App\Support\MediaUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use League\Flysystem\FilesystemException;

/**
 * Serveert HLS playlists (master + variants) via een korte cache-token URL,
 * met embedded signed URLs voor variants/segments zodat HLS-playback achter
 * BunnyCDN token-authentication werkt zónder cookie-based auth.
 *
 * Achtergrond: een BunnyCDN directory-token (`token_path` query param) verloopt
 * niet bij relative URL resolution (RFC 3986 dropt de query string). De player
 * fetcht variant playlists en segments zonder de oorspronkelijke token → 403.
 * Door master en variants serverside te herschrijven met absolute signed URLs
 * heeft elke request een eigen token in de query string, en blijft segments
 * + init.mp4 leveren via de CDN edge.
 */
class MediaHlsController extends Controller
{
    public const TOKEN_CACHE_PREFIX = 'hls-token:';

    public function __invoke(Request $request, string $token, string $file): Response|RedirectResponse
    {
        $resolved = Cache::get(self::TOKEN_CACHE_PREFIX.$token);

        abort_unless(is_array($resolved) && isset($resolved['prefix']), 404);

        // Voorkom directory-traversal: alleen relative components, geen `..`.
        if (str_contains($file, '..') || str_starts_with($file, '/')) {
            abort(403);
        }

        $prefix = rtrim($resolved['prefix'], '/').'/';
        $diskPath = $prefix.$file;

        if (str_ends_with($file, '.m3u8')) {
            // Lees de playlist direct in plaats van eerst exists() te checken: op
            // S3-disks (Hetzner/BunnyCDN) gooit fileExists() een HeadObject die bij
            // elke non-404/403 status (400, redirect, 5xx, timeout) een
            // UnableToCheckExistence throwt → 500. read() gebruikt GetObject en
            // levert simpelweg null op een ontbrekend bestand.
            $content = $this->readPlaylist($diskPath);

            abort_if($content === null, 404);

            return $this->servePlaylist($content, $prefix, $token, $file);
        }

        // Init mp4 / segments: redirect naar een direct-signed BunnyCDN URL.
        // Onze m3u8-rewrite zou voor segmenten meestal al absolute BunnyCDN URLs
        // hebben geschreven, dus dit is een fallback voor stragglers. De CDN edge
        // resolved bestaan/ontbreken zelf, dus geen extra storage-roundtrip hier.
        return $this->redirectToCdn($diskPath);
    }

    /**
     * Lees een playlist van disk; geef null terug als het bestand ontbreekt of
     * onleesbaar is, ongeacht de `throw`-instelling van de disk.
     */
    protected function readPlaylist(string $diskPath): ?string
    {
        try {
            return MediaUrl::disk()->get($diskPath);
        } catch (FilesystemException $e) {
            return null;
        }
    }

    protected function servePlaylist(string $content, string $prefix, string $token, string $file): Response
    {
        $rewritten = $this->rewritePlaylist($content, $prefix, $token, $file);

        // De m3u8 op disk is een afgeronde VOD-playlist (#EXT-X-ENDLIST) en
        // wijzigt niet meer. Wat per dag-venster roteert, zijn de embedded
        // signed segment-URLs. We cachen de playlist daarom tot de helft van
        // hun resterende geldigheid: de player hergebruikt 'm over sessies maar
        // ververst altijd ruim vóór segment-expiry — anders 403 op verlopen
        // segment-URLs. Afgeleid van expiryForHls() zodat dit klopt blijft als
        // de signing-window verandert.
        $remaining = MediaUrl::expiryForHls()->getTimestamp() - now()->getTimestamp();
        $maxAge = max(60, intdiv($remaining, 2));

        return response($rewritten, 200, [
            'Content-Type' => 'application/vnd.apple.mpegurl',
            'Cache-Control' => 'private, max-age='.$maxAge,
        ]);
    }

    /**
     * Rewrite playlist content zodat alle referenced URLs absolute signed URLs zijn.
     *
     * Master.m3u8 referenties (variant playlists) → onze proxy URL (zelfde token).
     * Variant playlist referenties (segments + EXT-X-MAP init.mp4) → direct
     * BunnyCDN signed URL voor maximum CDN edge cache hit.
     */
    protected function rewritePlaylist(string $content, string $prefix, string $token, string $file): string
    {
        $baseDir = rtrim(dirname('/'.$file), '/');
        $baseDir = $baseDir === '' ? '' : $baseDir.'/'; // 'v1080/' of '' voor master

        $isVariant = str_contains($file, '/'); // v1080/playlist.m3u8 etc.
        $bunny = BunnyCdnUrlSigner::fromConfig();
        $expires = MediaUrl::expiryForHls();

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $output = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $output[] = $line;

                continue;
            }

            // EXT-X-MAP:URI="init.mp4" — rewrite de URI quote-inhoud.
            if (str_starts_with($trimmed, '#EXT-X-MAP')) {
                $output[] = preg_replace_callback(
                    '/URI="([^"]+)"/',
                    fn ($m) => 'URI="'.$this->rewriteUrl($m[1], $prefix, $baseDir, $token, $isVariant, $bunny, $expires).'"',
                    $line,
                );

                continue;
            }

            if (str_starts_with($trimmed, '#')) {
                $output[] = $line; // andere comments / tags: ongewijzigd

                continue;
            }

            // Niet-comment line is een file reference (relatief pad).
            $output[] = $this->rewriteUrl($trimmed, $prefix, $baseDir, $token, $isVariant, $bunny, $expires);
        }

        return implode("\n", $output);
    }

    protected function rewriteUrl(
        string $relative,
        string $prefix,
        string $baseDir,
        string $token,
        bool $isVariant,
        ?BunnyCdnUrlSigner $bunny,
        \DateTimeInterface $expires,
    ): string {
        // Skip URLs die al absoluut zijn.
        if (preg_match('#^https?://#i', $relative)) {
            return $relative;
        }

        // Master.m3u8 → variant playlists via onze proxy (zelfde token).
        if (! $isVariant && str_ends_with($relative, '.m3u8')) {
            return url('/api/media/hls/'.$token.'/'.$baseDir.$relative);
        }

        // Variant.m3u8 → segments + init.mp4 direct via BunnyCDN signed URL.
        $diskPath = $prefix.$baseDir.$relative;

        if ($bunny !== null) {
            return $bunny->signDirectory($prefix, $baseDir.$relative, $expires);
        }

        // Geen Bunny configured (dev/test): val terug naar dezelfde proxy.
        return url('/api/media/hls/'.$token.'/'.$baseDir.$relative);
    }

    protected function redirectToCdn(string $diskPath): RedirectResponse
    {
        $bunny = BunnyCdnUrlSigner::fromConfig();
        $expires = MediaUrl::expiryForHls();

        if ($bunny !== null) {
            $directory = dirname($diskPath).'/';
            $filename = basename($diskPath);

            return redirect()->away($bunny->signDirectory($directory, $filename, $expires));
        }

        // Dev/test fallback: signed Laravel route op de generieke media-endpoint.
        return redirect()->away(URL::signedRoute('api.media', ['path' => $diskPath], $expires));
    }
}
