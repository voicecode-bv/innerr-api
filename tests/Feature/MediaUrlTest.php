<?php

use App\Support\MediaUrl;
use Illuminate\Support\Carbon;

it('returns null for null path', function () {
    expect(MediaUrl::sign(null))->toBeNull();
});

it('produces identical signed urls within the same day window', function () {
    Carbon::setTestNow('2026-04-27 02:00:00');
    $first = MediaUrl::sign('avatars/abc.jpg');

    Carbon::setTestNow('2026-04-27 22:45:30');
    $second = MediaUrl::sign('avatars/abc.jpg');

    expect($first)->toBe($second);
});

it('produces a different signed url after the day window rolls over', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');
    $first = MediaUrl::sign('avatars/abc.jpg');

    Carbon::setTestNow('2026-04-28 10:15:00');
    $second = MediaUrl::sign('avatars/abc.jpg');

    expect($first)->not->toBe($second);
});

it('strips a leading /storage/ prefix from the path', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    $withPrefix = MediaUrl::sign('https://example.test/storage/avatars/abc.jpg');
    $withoutPrefix = MediaUrl::sign('avatars/abc.jpg');

    expect($withPrefix)->toBe($withoutPrefix);
});

it('routes through the Bunny CDN signer when configured', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    config([
        'services.bunny_cdn.url' => 'https://media.innerr.app',
        'services.bunny_cdn.token_key' => 'test-token-key',
    ]);

    $url = MediaUrl::sign('users/abc/posts/photo.jpg');

    expect($url)
        ->toStartWith('https://media.innerr.app/users/abc/posts/photo.jpg?')
        ->toContain('token=HS256-')
        ->toContain('expires=');
});

it('strips the /storage/ prefix before signing with Bunny CDN', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    config([
        'services.bunny_cdn.url' => 'https://media.innerr.app',
        'services.bunny_cdn.token_key' => 'test-token-key',
    ]);

    $direct = MediaUrl::sign('avatars/abc.jpg');
    $withPrefix = MediaUrl::sign('https://example.test/storage/avatars/abc.jpg');

    expect($withPrefix)->toBe($direct);
});

it('passes through external URLs unchanged (e.g. picsum seed data)', function () {
    config([
        'services.bunny_cdn.url' => 'https://media-dev.innerr.app',
        'services.bunny_cdn.token_key' => 'test-token-key',
    ]);

    $external = 'https://picsum.photos/seed/6296/600/600';

    expect(MediaUrl::sign($external))->toBe($external);
});

it('falls back to a signed Laravel route when Bunny is not configured', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    config([
        'services.bunny_cdn.url' => null,
        'services.bunny_cdn.token_key' => null,
    ]);

    $url = MediaUrl::sign('avatars/abc.jpg');

    expect($url)->toContain('/api/media/');
    expect($url)->toContain('signature=');
});

it('temporary() returns null for a null path', function () {
    expect(MediaUrl::temporary(null, now()->addHour()))->toBeNull();
});

it('temporary() bypasses the Bunny CDN even when it is configured', function () {
    // A print supplier fetches the file server-to-server; it must not be
    // routed through the public CDN. On the local test disk this falls back
    // to a signed app route rather than a bunny.app URL.
    config([
        'services.bunny_cdn.url' => 'https://media.innerr.app',
        'services.bunny_cdn.token_key' => 'test-token-key',
    ]);

    $url = MediaUrl::temporary('print-orders/o1/i1.pdf', now()->addDays(6));

    expect($url)
        ->not->toContain('media.innerr.app')
        ->toContain('/api/media/')
        ->toContain('signature=');
});

it('temporary() passes through external URLs unchanged', function () {
    $external = 'https://picsum.photos/seed/6296/600/600';

    expect(MediaUrl::temporary($external, now()->addHour()))->toBe($external);
});

it('signs an HLS master playlist via the MediaHlsController proxy', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    config([
        'services.bunny_cdn.url' => 'https://media.innerr.app',
        'services.bunny_cdn.token_key' => 'test-token-key',
    ]);

    $url = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');

    // HLS-master gaat NIET direct via BunnyCDN: relative URL resolution dropt
    // de query string, dus segments zouden zonder token bij BunnyCDN aankomen.
    // We routeren door onze eigen proxy die de m3u8 inhoud herschrijft.
    expect($url)
        ->toContain('/api/media/hls/')
        ->toEndWith('/master.m3u8');
});

it('uses the same proxy URL format whether Bunny is configured or not', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    config([
        'services.bunny_cdn.url' => null,
        'services.bunny_cdn.token_key' => null,
    ]);

    $url = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');

    expect($url)
        ->toContain('/api/media/hls/')
        ->toEndWith('/master.m3u8');
});

it('signs an HLS master via a deterministic uuid token, stable within the day window', function () {
    Carbon::setTestNow('2026-04-27 02:00:00');
    $first = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');

    Carbon::setTestNow('2026-04-27 22:00:00');
    $second = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');

    // Same video + same day window → identical proxy URL (client-cacheable),
    // and the token is a valid UUID so it satisfies the route's whereUuid().
    expect($first)
        ->toBe($second)
        ->toMatch('#/api/media/hls/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/master\.m3u8$#');
});

it('rotates the HLS proxy token after the day window rolls over', function () {
    Carbon::setTestNow('2026-04-27 10:00:00');
    $first = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');

    Carbon::setTestNow('2026-04-28 10:00:00');
    $second = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');

    expect($first)->not->toBe($second);
});

it('issues distinct HLS proxy tokens for different videos', function () {
    Carbon::setTestNow('2026-04-27 10:00:00');

    $first = MediaUrl::sign('users/abc/posts/hls/m1/master.m3u8');
    $second = MediaUrl::sign('users/abc/posts/hls/m2/master.m3u8');

    expect($first)->not->toBe($second);
});
