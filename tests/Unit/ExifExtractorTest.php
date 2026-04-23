<?php

use App\Support\ExifExtractor;
use Illuminate\Http\UploadedFile;

function exifFixture(string $name, ?string $clientName = null, string $mime = 'image/jpeg'): UploadedFile
{
    return new UploadedFile(__DIR__.'/../fixtures/'.$name, $clientName ?? $name, $mime, null, true);
}

it('extracts taken_at and gps from a jpeg with exif', function () {
    $exif = ExifExtractor::fromUploadedFile(exifFixture('photo-with-exif.jpg'));

    expect($exif['taken_at'])->not->toBeNull()
        ->and($exif['taken_at']->format('Y-m-d H:i:s'))->toBe('2024-06-15 14:30:00')
        ->and($exif['taken_at']->timezoneName)->toBe('UTC')
        ->and($exif['latitude'])->toEqualWithDelta(48.858331, 0.00001)
        ->and($exif['longitude'])->toEqualWithDelta(2.294497, 0.00001);
});

it('returns nulls for a jpeg without exif', function () {
    $exif = ExifExtractor::fromUploadedFile(exifFixture('photo-without-exif.jpg'));

    expect($exif)->toBe([
        'taken_at' => null,
        'latitude' => null,
        'longitude' => null,
    ]);
});

it('returns nulls and does not throw for non-jpeg files', function () {
    $heic = exifFixture('photo-with-exif.jpg', 'photo.heic', 'image/heic');

    expect(ExifExtractor::fromUploadedFile($heic))->toBe([
        'taken_at' => null,
        'latitude' => null,
        'longitude' => null,
    ]);
});
