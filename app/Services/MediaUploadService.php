<?php

namespace App\Services;

use App\Support\ExifExtractor;
use App\Support\MediaUrl;
use App\Support\UserStorage;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
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
     * Edge length (in pixels) for the large square thumbnail used as a
     * fallback above the small grid thumbnail. Sized for 2× retina
     * displays so it stays sharp when shown on larger surfaces.
     */
    public const THUMBNAIL_SIZE_LARGE = 800;

    /**
     * Edge length (in pixels) for the small square thumbnail used in
     * profile grids and avatar previews. Sized for 2× retina displays
     * so a ~150px grid tile or avatar renders sharply.
     */
    public const THUMBNAIL_SIZE_SMALL = 300;

    /**
     * Maximum height (in pixels) for transcoded display videos.
     * Videos are scaled to fit within this height while preserving
     * their aspect ratio. 1080p is sharp enough for any phone screen
     * and keeps file sizes manageable.
     */
    private const MAX_VIDEO_HEIGHT = 1080;

    /**
     * CRF value for H.264 encoding (0–51, lower = better quality).
     * 23 is FFmpeg's default and offers a good balance between file
     * size and visual quality for social / family content.
     */
    private const VIDEO_CRF = 23;

    /**
     * Store an uploaded file for the given user.
     *
     * Images are converted from HEIC when needed, the original file is kept
     * in a separate "originals" folder, and a resized variant is generated
     * for display in the app. Videos are transcoded to H.264/AAC at a max
     * of 1080p; the untouched original is preserved alongside the display
     * variant just like images.
     *
     * @return string The path of the file that should be used for display.
     */
    public function store(
        UploadedFile $file,
        string $userId,
        string $folder,
        ?int $width = null,
        ?int $height = null,
        bool $cover = false,
    ): string {
        $file = $this->convertHeicToJpeg($file);

        $disk = MediaUrl::disk();
        $userFolder = "users/{$userId}";

        if (! $this->isImage($file)) {
            return $this->storeVideo($file, $userId, $folder);
        }

        $filename = Str::random(40).'.'.$file->getClientOriginalExtension();

        // Keep the untouched original.
        $originalPath = "{$userFolder}/originals/{$folder}/{$filename}";
        $disk->putFileAs("{$userFolder}/originals/{$folder}", $file, $filename);
        UserStorage::trackPut($originalPath, $disk);

        // Generate a resized variant for in-app display.
        $displayPath = "{$userFolder}/{$folder}/{$filename}";
        $resizedPath = tempnam(sys_get_temp_dir(), 'resized_').'.'.$file->getClientOriginalExtension();

        $image = Image::decodePath($file->getPathname());
        $image->orient();

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
        UserStorage::trackPut($displayPath, $disk);

        @unlink($resizedPath);

        return $displayPath;
    }

    /**
     * Process a post image that was stored untouched during upload into its
     * display variant and both thumbnails, deferring the costly HEIC decode +
     * resize out of the HTTP request (see ProcessPostImage). The HEIC
     * conversion runs once and is reused across all three outputs.
     *
     * The caller persists the returned paths and removes the raw source.
     *
     * @return array{path: string, original_path: ?string, thumbnail_path: ?string, thumbnail_small_path: ?string, taken_at: ?Carbon, latitude: ?float, longitude: ?float}
     */
    public function processStoredPostImage(string $rawPath, string $userId, string $folder): array
    {
        $disk = MediaUrl::disk();
        $extension = strtolower(pathinfo($rawPath, PATHINFO_EXTENSION)) ?: 'jpg';

        $tempSource = tempnam(sys_get_temp_dir(), 'post_img_').'.'.$extension;
        file_put_contents($tempSource, $disk->get($rawPath));

        try {
            $file = new UploadedFile($tempSource, 'upload.'.$extension, null, null, true);

            // Convert once and reuse the JPEG for every variant.
            $file = $this->convertHeicToJpeg($file);

            // The synchronous create-post extraction skips HEIC (exif_read_data
            // can't parse it), so HEIC uploads land with null taken_at/GPS. The
            // converted JPEG keeps the EXIF profile, so re-read it here to
            // recover metadata for clients that didn't send their own.
            $exif = ExifExtractor::fromUploadedFile($file);

            $thumbnailPath = $this->generateImageThumbnail($file, $userId, $folder);
            $thumbnailSmallPath = $this->generateImageThumbnail($file, $userId, $folder, size: self::THUMBNAIL_SIZE_SMALL);
            $displayPath = $this->store($file, $userId, $folder);

            return [
                'path' => $displayPath,
                'original_path' => MediaUrl::originalPath($displayPath),
                'thumbnail_path' => $thumbnailPath,
                'thumbnail_small_path' => $thumbnailSmallPath,
                'taken_at' => $exif['taken_at'],
                'latitude' => $exif['latitude'],
                'longitude' => $exif['longitude'],
            ];
        } finally {
            @unlink($tempSource);
        }
    }

    /**
     * Transcode and store a video file for the given user.
     *
     * The original upload is kept in the "originals" folder. A display
     * variant is transcoded to H.264 (video) + AAC (audio) inside an
     * MP4 container, scaled down to a maximum of 1080p. The `faststart`
     * flag is set so the video can begin playing before it is fully
     * downloaded.
     *
     * @return string The storage path of the transcoded display file.
     */
    private function storeVideo(UploadedFile $file, string $userId, string $folder): string
    {
        $disk = MediaUrl::disk();
        $userFolder = "users/{$userId}";
        $filename = Str::random(40).'.mp4';

        // Keep the untouched original.
        $originalExtension = $file->getClientOriginalExtension() ?: 'mp4';
        $originalFilename = Str::random(40).'.'.$originalExtension;
        $disk->putFileAs("{$userFolder}/originals/{$folder}", $file, $originalFilename);
        UserStorage::trackPut("{$userFolder}/originals/{$folder}/{$originalFilename}", $disk);

        // Transcode to H.264/AAC at max 1080p.
        $transcodedPath = tempnam(sys_get_temp_dir(), 'transcode_').'.mp4';

        $result = Process::timeout(300)->run([
            'ffmpeg',
            '-i', $file->getPathname(),
            // Video: H.264, scale down to 1080p max, keep aspect ratio.
            // The scale filter uses -2 so width is divisible by 2 (H.264 requirement).
            '-vf', "scale=-2:'min(".self::MAX_VIDEO_HEIGHT.",ih)'",
            '-c:v', 'libx264',
            '-preset', 'medium',
            '-crf', (string) self::VIDEO_CRF,
            '-pix_fmt', 'yuv420p',
            // Audio: AAC at 128 kbps. If the source has no audio, FFmpeg
            // simply skips the audio stream.
            '-c:a', 'aac',
            '-b:a', '128k',
            // Place the moov atom at the start for fast streaming start.
            '-movflags', '+faststart',
            '-y',
            $transcodedPath,
        ]);

        // If transcoding fails, fall back to the original file.
        if ($result->failed() || ! file_exists($transcodedPath) || filesize($transcodedPath) === 0) {
            @unlink($transcodedPath);

            $fallbackPath = $file->store("{$userFolder}/{$folder}");
            UserStorage::trackPut($fallbackPath, $disk);

            return $fallbackPath;
        }

        $displayPath = "{$userFolder}/{$folder}/{$filename}";
        $disk->put($displayPath, file_get_contents($transcodedPath));
        UserStorage::trackPut($displayPath, $disk);

        @unlink($transcodedPath);

        return $displayPath;
    }

    /**
     * Delete a stored display file together with its original counterpart, if any.
     *
     * HLS bundles (paths ending in `.m3u8`) are deleted as a whole directory
     * so master playlist, variant playlists and segments all go in one sweep.
     * The original source MP4 lives outside that directory and cannot be
     * derived from the master path, so HLS callers must pass it explicitly
     * via `$originalPath`. For single-file mp4/image uploads the original is
     * inferred from the display path when `$originalPath` is null.
     */
    public function delete(?string $displayPath, ?string $originalPath = null): void
    {
        if ($displayPath === null && $originalPath === null) {
            return;
        }

        $disk = MediaUrl::disk();

        if ($displayPath !== null) {
            if (str_ends_with($displayPath, '.m3u8')) {
                $this->deleteDirectory(dirname($displayPath), $disk);
            } else {
                UserStorage::trackDelete($displayPath, $disk);
                $disk->delete($displayPath);
            }
        }

        $originalPath ??= $displayPath !== null ? MediaUrl::originalPath($displayPath) : null;

        if ($originalPath !== null && $originalPath !== $displayPath) {
            UserStorage::trackDelete($originalPath, $disk);
            $disk->delete($originalPath);
        }
    }

    /**
     * Remove every file under a storage directory and track each one for the
     * owning user's quota before issuing the bulk delete.
     */
    private function deleteDirectory(string $directory, Filesystem $disk): void
    {
        foreach ($disk->allFiles($directory) as $file) {
            UserStorage::trackDelete($file, $disk);
        }

        $disk->deleteDirectory($directory);
    }

    /**
     * Generate a square JPEG thumbnail from an image upload.
     *
     * Produces a cover-cropped thumbnail of `$size`×`$size` pixels so the
     * image fills a fixed-ratio grid tile without stretching. Stored in
     * the user's `<folder>/thumbnails/` sub-folder.
     *
     * @return string|null The storage path of the thumbnail, or null on failure.
     */
    public function generateImageThumbnail(UploadedFile $file, string $userId, string $folder, int $size = self::THUMBNAIL_SIZE_LARGE): ?string
    {
        $file = $this->convertHeicToJpeg($file);

        if (! $this->isImage($file)) {
            return null;
        }

        $disk = MediaUrl::disk();
        $tempThumb = tempnam(sys_get_temp_dir(), 'thumb_').'.jpg';

        try {
            $image = Image::decodePath($file->getPathname());
            $image->orient();
            $image->cover($size, $size);
            $image->save($tempThumb, quality: self::DISPLAY_QUALITY);

            $filename = Str::random(40).'.jpg';
            $thumbnailPath = "users/{$userId}/{$folder}/thumbnails/{$filename}";

            $disk->put($thumbnailPath, file_get_contents($tempThumb));
            UserStorage::trackPut($thumbnailPath, $disk);

            return $thumbnailPath;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tempThumb);
        }
    }

    /**
     * Generate a square JPEG thumbnail from an image already in storage.
     *
     * Intended for backfilling: reads the source from the media disk,
     * produces a cover-cropped `$size`×`$size` thumbnail, and writes it
     * back to `users/{userId}/{folder}/thumbnails/`.
     *
     * @return string|null The storage path of the thumbnail, or null on failure.
     */
    public function generateImageThumbnailFromPath(string $sourcePath, string $userId, string $folder, int $size = self::THUMBNAIL_SIZE_LARGE): ?string
    {
        $disk = MediaUrl::disk();

        if (! $disk->exists($sourcePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $isHeic = in_array($extension, ['heic', 'heif'], true);

        $tempSource = tempnam(sys_get_temp_dir(), 'src_').'.'.($extension ?: 'bin');
        file_put_contents($tempSource, $disk->get($sourcePath));

        $decodeSource = $tempSource;
        $tempHeicJpeg = null;

        if ($isHeic) {
            $tempHeicJpeg = tempnam(sys_get_temp_dir(), 'src_heic_').'.jpg';
            self::decodeHeicToJpeg($tempSource, $tempHeicJpeg);
            $decodeSource = $tempHeicJpeg;
        }

        $tempThumb = tempnam(sys_get_temp_dir(), 'thumb_').'.jpg';

        try {
            $image = Image::decodePath($decodeSource);
            $image->orient();
            $image->cover($size, $size);
            $image->save($tempThumb, quality: self::DISPLAY_QUALITY);

            $filename = Str::random(40).'.jpg';
            $thumbnailPath = "users/{$userId}/{$folder}/thumbnails/{$filename}";

            $disk->put($thumbnailPath, file_get_contents($tempThumb));
            UserStorage::trackPut($thumbnailPath, $disk);

            return $thumbnailPath;
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tempSource);
            @unlink($tempThumb);

            if ($tempHeicJpeg !== null) {
                @unlink($tempHeicJpeg);
            }
        }
    }

    /**
     * Generate a JPEG thumbnail from a video file using FFmpeg.
     *
     * Accepts either a local filesystem path (when the source file is
     * still on disk, e.g. during upload) or a storage path. When a
     * local path is given the video does not need to be downloaded from
     * storage first, which is much faster.
     *
     * Captures a frame at the 1-second mark and stores the resulting
     * JPEG in the user's thumbnails folder.
     *
     * @param  string  $videoPath  Local file path or storage path to the video.
     * @param  string  $userId  Owner of the video.
     * @param  string  $folder  Storage sub-folder (e.g. "posts").
     * @param  bool  $isLocalPath  Whether $videoPath is already a local filesystem path.
     * @return string|null The storage path of the thumbnail, or null on failure.
     */
    public function generateVideoThumbnail(string $videoPath, string $userId, string $folder, bool $isLocalPath = false): ?string
    {
        $disk = MediaUrl::disk();

        $tempVideo = null;
        $inputPath = $videoPath;

        if (! $isLocalPath) {
            // Download the video from storage to a temp file.
            $tempVideo = tempnam(sys_get_temp_dir(), 'vid_');
            file_put_contents($tempVideo, $disk->get($videoPath));
            $inputPath = $tempVideo;
        }

        $tempThumb = tempnam(sys_get_temp_dir(), 'thumb_').'.jpg';

        try {
            $result = Process::run([
                'ffmpeg',
                '-i', $inputPath,
                '-ss', '00:00:01',
                '-frames:v', '1',
                '-q:v', '3',
                '-y',
                $tempThumb,
            ]);

            if ($result->failed() || ! file_exists($tempThumb) || filesize($tempThumb) === 0) {
                return null;
            }

            // Resize the thumbnail to a reasonable display size.
            $image = Image::decodePath($tempThumb);
            $image->orient();
            $image->scaleDown(width: self::MAX_DISPLAY_WIDTH);
            $image->save($tempThumb, quality: self::DISPLAY_QUALITY);

            $filename = Str::random(40).'.jpg';
            $thumbnailPath = "users/{$userId}/{$folder}/thumbnails/{$filename}";

            $disk->put($thumbnailPath, file_get_contents($tempThumb));
            UserStorage::trackPut($thumbnailPath, $disk);

            return $thumbnailPath;
        } catch (\Throwable) {
            return null;
        } finally {
            if ($tempVideo !== null) {
                @unlink($tempVideo);
            }
            @unlink($tempThumb);
        }
    }

    private function isImage(UploadedFile $file): bool
    {
        return str_starts_with((string) $file->getMimeType(), 'image/');
    }

    /**
     * Convert a HEIC/HEIF upload to a JPEG-backed UploadedFile, returning the
     * file unchanged when it is already a non-HEIC format.
     *
     * Idempotent and safe to hoist: callers that process the same upload into
     * several variants (display image plus thumbnails) should convert once and
     * pass the resulting JPEG to each step so the costly HEIC decode runs a
     * single time instead of once per variant.
     */
    public function convertHeicToJpeg(UploadedFile $file): UploadedFile
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! in_array($extension, ['heic', 'heif'], true)) {
            return $file;
        }

        $heicPath = tempnam(sys_get_temp_dir(), 'heic_').'.'.$extension;
        copy($file->getPathname(), $heicPath);

        $jpegPath = tempnam(sys_get_temp_dir(), 'heic_').'.jpg';

        try {
            self::decodeHeicToJpeg($heicPath, $jpegPath);
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

    /**
     * Decode a HEIC/HEIF file to a baseline JPEG at $jpegPath.
     *
     * Prefers Imagick because libheif inside ImageMagick reconciles the
     * HEIF container's `irot`/`imir` transforms with the embedded EXIF
     * Orientation tag — both end up consistent (Orientation = 1) in the
     * resulting JPEG. The `heif-convert` CLI keeps the original EXIF tag
     * intact while libheif has already rotated the pixels, which causes
     * downstream rotators to apply a phantom second rotation.
     */
    public static function decodeHeicToJpeg(string $heicPath, string $jpegPath): void
    {
        if (class_exists(\Imagick::class) && in_array('HEIC', \Imagick::queryFormats('HEIC'), true)) {
            $im = new \Imagick($heicPath);

            try {
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(90);
                $im->writeImage($jpegPath);
            } finally {
                $im->clear();
            }

            return;
        }

        $result = Process::run(['heif-convert', '-q', '90', $heicPath, $jpegPath]);

        if ($result->failed() || ! file_exists($jpegPath)) {
            throw new \RuntimeException(
                'HEIC to JPEG conversion failed. Install ImageMagick with libheif support, or libheif-examples for the heif-convert fallback. '.$result->errorOutput()
            );
        }

        // heif-convert leaves the source EXIF Orientation intact even though
        // libheif already applied the container rotation. Reset the tag so
        // the downstream pipeline does not rotate the pixels a second time.
        if (class_exists(\Imagick::class)) {
            $im = new \Imagick($jpegPath);

            try {
                $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
                $im->writeImage($jpegPath);
            } finally {
                $im->clear();
            }
        }
    }
}
