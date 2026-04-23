<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Throwable;

final class ExifExtractor
{
    /**
     * Extract EXIF metadata (capture date and GPS coordinates) from an uploaded
     * image. Returns null values for any field that is missing, malformed, or
     * outside a sane range. Never throws — uploads must not fail because of EXIF.
     *
     * @return array{taken_at: ?Carbon, latitude: ?float, longitude: ?float}
     */
    public static function fromUploadedFile(UploadedFile $file): array
    {
        $empty = ['taken_at' => null, 'latitude' => null, 'longitude' => null];

        if (! function_exists('exif_read_data')) {
            return $empty;
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $clientMime = strtolower((string) $file->getClientMimeType());
        $sniffedMime = strtolower((string) $file->getMimeType());

        // Both the client-declared metadata AND the sniffed content must look like JPEG.
        // A HEIC renamed to .jpg, or a JPEG sent as photo.heic, should be skipped.
        $clientJpeg = $clientMime === 'image/jpeg' || in_array($extension, ['jpg', 'jpeg'], true);

        if (! $clientJpeg || $sniffedMime !== 'image/jpeg') {
            return $empty;
        }

        try {
            $data = @exif_read_data($file->getPathname(), 'EXIF', true);
        } catch (Throwable) {
            return $empty;
        }

        if (! is_array($data)) {
            return $empty;
        }

        $exif = $data['EXIF'] ?? [];
        $gps = $data['GPS'] ?? [];

        return [
            'taken_at' => self::extractTakenAt($exif),
            'latitude' => self::extractCoordinate($gps, 'GPSLatitude', 'GPSLatitudeRef', 90.0),
            'longitude' => self::extractCoordinate($gps, 'GPSLongitude', 'GPSLongitudeRef', 180.0),
        ];
    }

    /**
     * @param  array<string, mixed>  $exif
     */
    private static function extractTakenAt(array $exif): ?Carbon
    {
        $candidates = ['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'];

        foreach ($candidates as $key) {
            $raw = $exif[$key] ?? null;

            if (! is_string($raw) || $raw === '') {
                continue;
            }

            $offset = isset($exif['OffsetTimeOriginal']) && is_string($exif['OffsetTimeOriginal'])
                ? $exif['OffsetTimeOriginal']
                : 'UTC';

            try {
                $date = Carbon::createFromFormat('Y:m:d H:i:s', $raw, $offset);
            } catch (Throwable) {
                continue;
            }

            if ($date === false || $date === null) {
                continue;
            }

            $date = $date->utc();

            $min = Carbon::create(1990, 1, 1, 0, 0, 0, 'UTC');
            $max = Carbon::now('UTC')->addDay();

            if ($date->lessThan($min) || $date->greaterThan($max)) {
                continue;
            }

            return $date;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gps
     */
    private static function extractCoordinate(array $gps, string $valueKey, string $refKey, float $maxAbs): ?float
    {
        $value = $gps[$valueKey] ?? null;
        $ref = $gps[$refKey] ?? null;

        if (! is_array($value) || count($value) !== 3 || ! is_string($ref) || $ref === '') {
            return null;
        }

        $decimal = self::dmsToDecimal($value, $ref);

        if ($decimal === null || abs($decimal) > $maxAbs) {
            return null;
        }

        return $decimal;
    }

    /**
     * @param  array{0: mixed, 1: mixed, 2: mixed}  $dms
     */
    private static function dmsToDecimal(array $dms, string $ref): ?float
    {
        $degrees = self::parseRational($dms[0]);
        $minutes = self::parseRational($dms[1]);
        $seconds = self::parseRational($dms[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + $minutes / 60 + $seconds / 3600;
        $direction = strtoupper($ref);

        if ($direction === 'S' || $direction === 'W') {
            $decimal = -$decimal;
        }

        return $decimal;
    }

    private static function parseRational(mixed $rational): ?float
    {
        if (is_int($rational) || is_float($rational)) {
            return (float) $rational;
        }

        if (! is_string($rational)) {
            return null;
        }

        if (! str_contains($rational, '/')) {
            return is_numeric($rational) ? (float) $rational : null;
        }

        [$num, $den] = array_pad(explode('/', $rational, 2), 2, '0');

        if (! is_numeric($num) || ! is_numeric($den) || (float) $den === 0.0) {
            return null;
        }

        return (float) $num / (float) $den;
    }
}
