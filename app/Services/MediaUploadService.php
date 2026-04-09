<?php

namespace App\Services;

use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class MediaUploadService
{
    /**
     * Maximum width (in pixels) for resized display images.
     */
    private const MAX_DISPLAY_WIDTH = 1920;

    /**
     * JPEG quality used for resized display images.
     */
    private const DISPLAY_QUALITY = 85;

    /**
     * Store an uploaded file for the given user.
     *
     * Images are converted from HEIC when needed, the original file is kept
     * in a separate "originals" folder, and a resized variant is generated
     * for display in the app. Non-image files (e.g. video) are stored once
     * inside the user's folder without resizing.
     *
     * @return string The path of the file that should be used for display.
     */
    public function store(
        UploadedFile $file,
        int $userId,
        string $folder,
        ?int $width = null,
        ?int $height = null,
        bool $cover = false,
    ): string {
        $file = $this->convertHeicToJpeg($file);

        $disk = MediaUrl::disk();
        $userFolder = "users/{$userId}";

        if (! $this->isImage($file)) {
            return $file->store("{$userFolder}/{$folder}", config('filesystems.media'));
        }

        $filename = Str::random(40).'.'.$file->getClientOriginalExtension();

        // Keep the untouched original.
        $originalPath = "{$userFolder}/originals/{$folder}/{$filename}";
        $disk->putFileAs("{$userFolder}/originals/{$folder}", $file, $filename);

        // Generate a resized variant for in-app display.
        $displayPath = "{$userFolder}/{$folder}/{$filename}";
        $resizedPath = tempnam(sys_get_temp_dir(), 'resized_').'.'.$file->getClientOriginalExtension();

        $image = Image::decodePath($file->getPathname());

        if ($cover && $width !== null && $height !== null) {
            $image->cover($width, $height);
        } else {
            $image->scaleDown(
                width: $width ?? self::MAX_DISPLAY_WIDTH,
                height: $height,
            );
        }

        $image->save($resizedPath, quality: self::DISPLAY_QUALITY);

        $disk->put($displayPath, file_get_contents($resizedPath));

        @unlink($resizedPath);

        return $displayPath;
    }

    /**
     * Delete a stored display file together with its original counterpart, if any.
     */
    public function delete(?string $displayPath): void
    {
        if ($displayPath === null) {
            return;
        }

        $disk = MediaUrl::disk();
        $disk->delete($displayPath);

        $originalPath = preg_replace(
            '#^(users/\d+)/(?!originals/)(.+)$#',
            '$1/originals/$2',
            $displayPath,
        );

        if ($originalPath !== null && $originalPath !== $displayPath) {
            $disk->delete($originalPath);
        }
    }

    private function isImage(UploadedFile $file): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/');
    }

    private function convertHeicToJpeg(UploadedFile $file): UploadedFile
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, ['heic', 'heif'], true)) {
            return $file;
        }

        // Use ffmpeg for HEIC conversion because both the PHP Imagick
        // extension and heif-convert (libheif) on IM 6.x fail on iPhone
        // HEIC files with HDR gain maps ("Too many auxiliary image references").
        // ffmpeg handles all HEIC variants reliably.
        $heicPath = tempnam(sys_get_temp_dir(), 'heic_').'.'.$extension;
        copy($file->getPathname(), $heicPath);

        $jpegPath = tempnam(sys_get_temp_dir(), 'heic_').'.jpg';

        try {
            $result = Process::run([
                'ffmpeg', '-i', $heicPath, '-q:v', '2', '-y', $jpegPath,
            ]);

            if ($result->failed() || ! file_exists($jpegPath)) {
                throw new \RuntimeException(
                    'HEIC to JPEG conversion failed: '.$result->errorOutput()
                );
            }
        } finally {
            @unlink($heicPath);
        }

        return new UploadedFile(
            $jpegPath,
            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.jpg',
            'image/jpeg',
            null,
            true,
        );
    }
}
