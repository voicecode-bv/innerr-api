<?php

use App\Support\MediaUrl;
use Illuminate\Support\Carbon;

it('returns null for null path', function () {
    expect(MediaUrl::sign(null))->toBeNull();
});

it('produces identical signed urls within the same hour window', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');
    $first = MediaUrl::sign('avatars/abc.jpg');

    Carbon::setTestNow('2026-04-27 10:45:30');
    $second = MediaUrl::sign('avatars/abc.jpg');

    expect($first)->toBe($second);
});

it('produces a different signed url after the hour window rolls over', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');
    $first = MediaUrl::sign('avatars/abc.jpg');

    Carbon::setTestNow('2026-04-27 11:05:00');
    $second = MediaUrl::sign('avatars/abc.jpg');

    expect($first)->not->toBe($second);
});

it('strips a leading /storage/ prefix from the path', function () {
    Carbon::setTestNow('2026-04-27 10:15:00');

    $withPrefix = MediaUrl::sign('https://example.test/storage/avatars/abc.jpg');
    $withoutPrefix = MediaUrl::sign('avatars/abc.jpg');

    expect($withPrefix)->toBe($withoutPrefix);
});
