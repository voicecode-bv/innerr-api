<?php

use App\Http\Controllers\Api\MediaHlsController;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('public');
    config([
        'filesystems.default' => 'public',
        'services.bunny_cdn.url' => 'https://media.innerr.app',
        'services.bunny_cdn.token_key' => 'test-token-key',
    ]);
});

function seedHls(string $prefix): void
{
    Storage::disk('public')->put($prefix.'master.m3u8', implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:7',
        '#EXT-X-INDEPENDENT-SEGMENTS',
        '#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS="avc1.4d4028,mp4a.40.2"',
        'v1080/playlist.m3u8',
        '#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720,CODECS="avc1.4d401f,mp4a.40.2"',
        'v720/playlist.m3u8',
    ]));

    $variantContent = implode("\n", [
        '#EXTM3U',
        '#EXT-X-VERSION:7',
        '#EXT-X-TARGETDURATION:6',
        '#EXT-X-MAP:URI="init.mp4"',
        '#EXTINF:6.000,',
        'seg-0001.m4s',
        '#EXTINF:6.000,',
        'seg-0002.m4s',
        '#EXT-X-ENDLIST',
    ]);

    Storage::disk('public')->put($prefix.'v1080/playlist.m3u8', $variantContent);
    Storage::disk('public')->put($prefix.'v720/playlist.m3u8', $variantContent);
    Storage::disk('public')->put($prefix.'v1080/init.mp4', 'init');
    Storage::disk('public')->put($prefix.'v1080/seg-0001.m4s', 'seg1');
    Storage::disk('public')->put($prefix.'v1080/seg-0002.m4s', 'seg2');
    Storage::disk('public')->put($prefix.'poster.jpg', 'poster');
}

function issueToken(string $prefix): string
{
    $token = (string) Str::uuid();
    Cache::put(MediaHlsController::TOKEN_CACHE_PREFIX.$token, ['prefix' => $prefix], now()->addHour());

    return $token;
}

it('returns 404 for an unknown token', function () {
    $this->get('/api/media/hls/'.Str::uuid().'/master.m3u8')->assertStatus(404);
});

it('returns 403 when file contains parent traversal', function () {
    $prefix = 'users/abc/posts/hls/m1/';
    seedHls($prefix);
    $token = issueToken($prefix);

    $this->get('/api/media/hls/'.$token.'/../etc/passwd')->assertStatus(403);
});

it('returns 404 when the requested file does not exist on disk', function () {
    $prefix = 'users/abc/posts/hls/m1/';
    seedHls($prefix);
    $token = issueToken($prefix);

    $this->get('/api/media/hls/'.$token.'/v9999/playlist.m3u8')->assertStatus(404);
});

it('rewrites master.m3u8 variant references to our proxy URLs', function () {
    $prefix = 'users/abc/posts/hls/m1/';
    seedHls($prefix);
    $token = issueToken($prefix);

    $response = $this->get('/api/media/hls/'.$token.'/master.m3u8')->assertOk();

    $body = $response->getContent();

    expect($body)->toContain('#EXT-X-STREAM-INF:')
        ->and($body)->toContain('/api/media/hls/'.$token.'/v1080/playlist.m3u8')
        ->and($body)->toContain('/api/media/hls/'.$token.'/v720/playlist.m3u8')
        ->and($body)->not->toContain("\nv1080/playlist.m3u8")
        ->and($response->headers->get('Content-Type'))->toContain('application/vnd.apple.mpegurl');
});

it('caches the playlist for half the embedded signed-URL lifetime', function () {
    // Start van de dag → embedded segment-URLs verlopen over 48h (startOfDay
    // + 2 dagen). De playlist wordt de helft daarvan gecachet: 24h.
    Carbon::setTestNow('2026-04-27 00:00:00');

    $prefix = 'users/abc/posts/hls/m1/';
    seedHls($prefix);
    $token = issueToken($prefix);

    $response = $this->get('/api/media/hls/'.$token.'/master.m3u8')->assertOk();

    expect($response->headers->get('Cache-Control'))
        ->toContain('max-age='.(24 * 3600))
        ->toContain('private');
});

it('rewrites variant playlist segments + init.mp4 to direct BunnyCDN signed URLs', function () {
    $prefix = 'users/abc/posts/hls/m1/';
    seedHls($prefix);
    $token = issueToken($prefix);

    $response = $this->get('/api/media/hls/'.$token.'/v1080/playlist.m3u8')->assertOk();

    $body = $response->getContent();

    // Segments + init.mp4 wijzen direct naar BunnyCDN met directory-token.
    expect($body)
        ->toContain('https://media.innerr.app/users/abc/posts/hls/m1/v1080/init.mp4?token=HS256-')
        ->and($body)->toContain('https://media.innerr.app/users/abc/posts/hls/m1/v1080/seg-0001.m4s?token=HS256-')
        ->and($body)->toContain('token_path=')
        ->and($body)->toContain('#EXT-X-MAP:URI="https://media.innerr.app/');
});

it('redirects non-playlist files to a direct BunnyCDN signed URL', function () {
    $prefix = 'users/abc/posts/hls/m1/';
    seedHls($prefix);
    $token = issueToken($prefix);

    $this->get('/api/media/hls/'.$token.'/v1080/seg-0001.m4s')
        ->assertRedirect();
});
