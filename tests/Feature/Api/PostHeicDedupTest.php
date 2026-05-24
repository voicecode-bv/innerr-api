<?php

use App\Models\Circle;
use App\Models\Post;
use App\Models\User;
use App\Services\MediaUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Build a fresh UploadedFile pointing at the real HEIC fixture. Each variant
 * generated from it (display image + two thumbnails) used to trigger its own
 * HEIC decode; ProcessPostImage now converts once and reuses the JPEG. With
 * the sync test queue the job runs inline, so the decode count is observable
 * here.
 */
function freshHeicUpload(): UploadedFile
{
    return new UploadedFile(
        __DIR__.'/../../fixtures/photo-heic-orientation-mismatch.heic',
        'photo.heic',
        'image/heic',
        null,
        true,
    );
}

it('decodes each HEIC photo only once when building a multi-photo post', function () {
    Storage::fake('public');

    // Count how often the expensive HEIC->JPEG conversion actually runs by
    // counting calls that still receive a HEIC file. After the up-front
    // conversion every downstream call sees a JPEG and short-circuits.
    $countingMedia = new class extends MediaUploadService
    {
        public int $heicDecodes = 0;

        public function convertHeicToJpeg(UploadedFile $file): UploadedFile
        {
            if (in_array(strtolower((string) $file->getClientOriginalExtension()), ['heic', 'heif'], true)) {
                $this->heicDecodes++;
            }

            return parent::convertHeicToJpeg($file);
        }
    };

    $this->app->instance(MediaUploadService::class, $countingMedia);

    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media' => [freshHeicUpload(), freshHeicUpload(), freshHeicUpload()],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonCount(3, 'data.media');

    // One decode per photo, not three (display + large + small thumbnail).
    expect($countingMedia->heicDecodes)->toBe(3);

    // All variants are still produced from the converted JPEG.
    $items = Post::first()->media()->orderBy('sort_order')->get();
    $disk = Storage::disk('public');

    expect($items)->toHaveCount(3);

    foreach ($items as $item) {
        expect($item->type)->toBe('image')
            ->and($item->path)->not->toBeNull()
            ->and($item->thumbnail_path)->not->toBeNull()
            ->and($item->thumbnail_small_path)->not->toBeNull();

        $disk->assertExists($item->path);
        $disk->assertExists($item->thumbnail_path);
        $disk->assertExists($item->thumbnail_small_path);
    }
});
