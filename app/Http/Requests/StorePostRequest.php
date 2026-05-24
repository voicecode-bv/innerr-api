<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\UploadController;
use App\Rules\AccessibleCircle;
use App\Rules\MaxImageDimensions;
use App\Rules\MaxVideoDuration;
use App\Rules\OwnedTag;
use App\Rules\TaggablePerson;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class StorePostRequest extends FormRequest
{
    private const MIME_TYPES = 'jpg,jpeg,png,gif,heic,heif,mp4,mov,m4v,webm';

    // 1 GB. Verhoogd van 250 MB nu FileFlux op een dedicated machine de
    // transcoding doet — lokale ffmpeg-fallback heeft genoeg memory_limit
    // (700M) om bestanden tot deze grens te verwerken, zij het langzamer.
    private const MAX_KB = 1024000;

    public const MAX_MEDIA_ITEMS = 10;

    /**
     * Cap voor afbeeldings-resolutie. Een 50 MP smartphone-foto haalt
     * ~8000×6000; 12000×12000 geeft marge voor stitched/medium-format
     * uploads en houdt decompression-bombs (50000×50000 PNG die naar GB-RAM
     * uitpakt tijdens thumbnail-generatie) buiten de deur. Wordt door
     * Laravels `dimensions:`-rule alleen op image-files toegepast; video's
     * passeren ongemoeid.
     */
    private const MAX_IMAGE_DIMENSION = 12000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `media_metadata` is sent as a JSON string by the mobile BFF (multipart
     * does not serialize nested arrays cleanly). Decode it up-front so the
     * standard validator can walk `media_metadata.*.taken_at` etc.
     *
     * Daarnaast: clients die hun media chunked geüpload hebben sturen geen
     * multipart `media`-veld maar een `media_token` (single) of
     * `media_tokens[]` (multi). Die zetten we hier om in `UploadedFile`s
     * zodat de bestaande regels (mimes, dimensions, video duration) één-op-één
     * blijven werken.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('media_metadata') && is_string($this->input('media_metadata'))) {
            $decoded = json_decode((string) $this->input('media_metadata'), true);

            if (is_array($decoded)) {
                $this->merge(['media_metadata' => $decoded]);
            }
        }

        $this->materializeTokensAsFiles();
    }

    /**
     * Sessions waarvan we de geassembleerde file als `media` hebben ingevuld;
     * `PostController` ruimt ze na succesvolle store op.
     *
     * @var array<int, string>
     */
    public array $consumedUploadTokens = [];

    private function materializeTokensAsFiles(): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        // Bewust geen `hasFile('media')` — die roept `allFiles()` aan en cacht
        // het lege resultaat in `$this->convertedFiles`, waarna onze latere
        // `$this->files->set('media', …)` niet meer terug te zien is. Direct
        // tegen de underlying FileBag praten omzeilt die cache.
        if ($this->files->has('media')) {
            return;
        }

        $singleToken = $this->input('media_token');
        $multipleTokens = $this->input('media_tokens');

        if (is_string($singleToken) && $singleToken !== '') {
            $file = $this->resolveTokenAsFile($singleToken, $user->id);

            if ($file !== null) {
                $this->files->set('media', $file);
                $this->invalidateConvertedFilesCache();
            }

            return;
        }

        if (is_array($multipleTokens) && $multipleTokens !== []) {
            $files = [];

            foreach ($multipleTokens as $token) {
                if (! is_string($token)) {
                    continue;
                }

                $file = $this->resolveTokenAsFile($token, $user->id);

                if ($file !== null) {
                    $files[] = $file;
                }
            }

            if ($files !== []) {
                $this->files->set('media', $files);
                $this->invalidateConvertedFilesCache();
            }
        }
    }

    /**
     * Reset de `convertedFiles` cache van `Illuminate\Http\Request` zodat een
     * volgende `file()`/`allFiles()`-call onze net-geïnjecteerde UploadedFile
     * objecten wel ziet. Zonder dit blijft de cache de lege snapshot houden.
     */
    private function invalidateConvertedFilesCache(): void
    {
        $reflection = new \ReflectionClass($this);
        $base = $reflection->getParentClass();

        while ($base !== false && $base->getName() !== Request::class) {
            $base = $base->getParentClass();
        }

        if ($base === false || ! $base->hasProperty('convertedFiles')) {
            return;
        }

        $property = $base->getProperty('convertedFiles');
        $property->setAccessible(true);
        $property->setValue($this, null);
    }

    private function resolveTokenAsFile(string $token, string $userId): ?UploadedFile
    {
        $resolved = UploadController::consumeAssembled($token, $userId);

        if ($resolved === null) {
            return null;
        }

        $this->consumedUploadTokens[] = $token;

        // `mimes:`-validatie vergelijkt de extensie van de originele filename
        // tegen guesseable mime types. We hebben alleen de mime type, dus
        // mappen we terug naar de extensie waar Laravel `mimes:` mee werkt.
        $extension = match ($resolved['mime_type']) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-m4v' => 'm4v',
            default => 'bin',
        };

        return new UploadedFile(
            $resolved['path'],
            "upload.{$extension}",
            $resolved['mime_type'],
            null,
            true,
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return $this->isMultiMedia() ? $this->arrayRules() : $this->singleRules();
    }

    /**
     * Did the client upload `media` (single file) or `media[]` (array)?
     * `$request->file('media')` returns an array iff the field name had
     * array notation.
     */
    public function isMultiMedia(): bool
    {
        return is_array($this->file('media'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function singleRules(): array
    {
        return [
            'media' => ['required', 'file', 'mimes:'.self::MIME_TYPES, 'max:'.self::MAX_KB, new MaxImageDimensions(self::MAX_IMAGE_DIMENSION, self::MAX_IMAGE_DIMENSION), new MaxVideoDuration(600)],
            'media_token' => ['sometimes', 'uuid'],
            'media_tokens' => ['prohibited'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'location' => ['nullable', 'string', 'max:255'],
            'taken_at' => ['nullable', 'date', 'before_or_equal:now', 'after:1990-01-01'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'circle_ids' => ['required', 'array', 'min:1', 'max:50'],
            'circle_ids.*' => ['uuid', new AccessibleCircle($this->user())],
            'tag_ids' => ['sometimes', 'array', 'max:50'],
            'tag_ids.*' => ['uuid', new OwnedTag($this->user())],
            'person_ids' => ['sometimes', 'array', 'max:50'],
            'person_ids.*' => ['uuid', new TaggablePerson($this->user(), $this->effectiveCircleIds())],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function arrayRules(): array
    {
        return [
            'media' => ['required', 'array', 'min:1', 'max:'.self::MAX_MEDIA_ITEMS],
            'media.*' => ['file', 'mimes:'.self::MIME_TYPES, 'max:'.self::MAX_KB, new MaxImageDimensions(self::MAX_IMAGE_DIMENSION, self::MAX_IMAGE_DIMENSION), new MaxVideoDuration(600)],
            'media_token' => ['prohibited'],
            'media_tokens' => ['sometimes', 'array', 'max:'.self::MAX_MEDIA_ITEMS],
            'media_tokens.*' => ['uuid'],
            'media_metadata' => ['nullable', 'array', 'max:'.self::MAX_MEDIA_ITEMS],
            'media_metadata.*' => ['array'],
            'media_metadata.*.taken_at' => ['nullable', 'date', 'before_or_equal:now', 'after:1990-01-01'],
            'media_metadata.*.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'media_metadata.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'location' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'circle_ids' => ['required', 'array', 'min:1', 'max:50'],
            'circle_ids.*' => ['uuid', new AccessibleCircle($this->user())],
            'tag_ids' => ['sometimes', 'array', 'max:50'],
            'tag_ids.*' => ['uuid', new OwnedTag($this->user())],
            'person_ids' => ['sometimes', 'array', 'max:50'],
            'person_ids.*' => ['uuid', new TaggablePerson($this->user(), $this->effectiveCircleIds())],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function effectiveCircleIds(): array
    {
        return array_values(array_filter(array_map(
            fn ($id) => is_string($id) ? $id : null,
            (array) $this->input('circle_ids', [])
        )));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v): void {
            if ($this->isMultiMedia()) {
                $this->validateNoMixedVideoPhoto($v);
                $this->validatePerItemCoordinates($v);
            }

            // A post-level location may be supplied alongside (or instead of)
            // per-item EXIF coordinates and becomes the post's map position.
            $this->validateTopLevelCoordinates($v);
        });
    }

    private function validateTopLevelCoordinates(Validator $v): void
    {
        if ($this->filled('latitude') !== $this->filled('longitude')) {
            $v->errors()->add('longitude', 'Latitude and longitude must be provided together.');
        }
    }

    private function validatePerItemCoordinates(Validator $v): void
    {
        foreach ((array) $this->input('media_metadata', []) as $index => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $hasLat = isset($meta['latitude']) && $meta['latitude'] !== null;
            $hasLng = isset($meta['longitude']) && $meta['longitude'] !== null;

            if ($hasLat !== $hasLng) {
                $v->errors()->add(
                    "media_metadata.{$index}.longitude",
                    'Latitude and longitude must be provided together.'
                );
            }
        }
    }

    private function validateNoMixedVideoPhoto(Validator $v): void
    {
        $files = (array) $this->file('media');

        if (count($files) <= 1) {
            return;
        }

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (str_starts_with((string) $file->getMimeType(), 'video/')) {
                $v->errors()->add(
                    "media.{$index}",
                    'A video must be uploaded on its own, without other photos.'
                );

                return;
            }
        }
    }
}
