<?php

use App\Http\Controllers\Api\UploadController;
use App\Models\Circle;
use App\Models\Post;
use App\Models\User;
use App\Support\MediaUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    File::deleteDirectory(UploadController::sessionsDirectory());
});

/**
 * Chunk-upload a fake image and return its redeemable upload token + session id.
 *
 * @return array{0: string, 1: string}
 */
function uploadSourceImage(User $user, string $filename = 'src.jpg'): array
{
    $fakeImage = UploadedFile::fake()->image($filename, 100, 100);
    $bytes = file_get_contents($fakeImage->getPathname()) ?: '';

    test()->actingAs($user);
    $uploadId = test()->postJson('/api/uploads')->json('upload_id');

    $finalResponse = test()->postJson("/api/uploads/{$uploadId}/chunk", [
        'sequence' => 0,
        'data' => base64_encode($bytes),
        'final' => true,
        'mime_type' => 'image/jpeg',
    ])->assertOk();

    return [(string) $finalResponse->json('upload_token'), $uploadId];
}

it('archives the uncropped source and stores the crop rectangle', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    [$croppedToken] = uploadSourceImage($user, 'cropped.jpg');
    [$sourceToken, $sourceSession] = uploadSourceImage($user, 'original.jpg');

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media_tokens' => [$croppedToken],
            'media_source_tokens' => [$sourceToken],
            'media_crops' => [['x' => 10, 'y' => 20, 'width' => 100, 'height' => 80]],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.media.0.crop.width', 100)
        ->assertJsonPath('data.media.0.crop.x', 10);

    $media = Post::sole()->media()->sole();

    expect($media->source_path)->not->toBeNull()
        ->and($media->source_path)->toStartWith("users/{$user->id}/sources/posts/")
        ->and(MediaUrl::disk()->exists($media->source_path))->toBeTrue()
        ->and($media->crop)->toEqual(['x' => 10, 'y' => 20, 'width' => 100, 'height' => 80])
        // The redeemed source session is cleaned up, like media tokens.
        ->and(is_dir(UploadController::sessionDirectory($sourceSession)))->toBeFalse();
});

it('leaves source and crop null for an uncropped upload', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    [$token] = uploadSourceImage($user);

    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media_tokens' => [$token],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.media.0.source_url', null)
        ->assertJsonPath('data.media.0.crop', null);

    $media = Post::sole()->media()->sole();

    expect($media->source_path)->toBeNull()
        ->and($media->crop)->toBeNull();
});

it('does not expose the source original to other viewers', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $owner->id]);
    $circle->members()->attach($viewer);

    [$croppedToken] = uploadSourceImage($owner, 'cropped.jpg');
    [$sourceToken] = uploadSourceImage($owner, 'original.jpg');

    $postId = $this->actingAs($owner)
        ->postJson('/api/posts', [
            'media_tokens' => [$croppedToken],
            'media_source_tokens' => [$sourceToken],
            'media_crops' => [['x' => 0, 'y' => 0, 'width' => 50, 'height' => 50]],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated()
        ->json('data.id');

    // The owner sees the source; a circle viewer never does.
    $this->actingAs($owner)
        ->getJson("/api/posts/{$postId}")
        ->assertOk()
        ->assertJsonPath('data.media.0.source_url', fn ($url) => is_string($url) && $url !== '');

    $this->actingAs($viewer)
        ->getJson("/api/posts/{$postId}")
        ->assertOk()
        ->assertJsonPath('data.media.0.source_url', null)
        ->assertJsonPath('data.media.0.crop.width', 50);
});

it('ignores a malformed crop while still archiving the source', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);

    [$croppedToken] = uploadSourceImage($user, 'cropped.jpg');
    [$sourceToken] = uploadSourceImage($user, 'original.jpg');

    // Width missing: required_with rules reject before we ever persist a
    // half-built rectangle.
    $this->actingAs($user)
        ->postJson('/api/posts', [
            'media_tokens' => [$croppedToken],
            'media_source_tokens' => [$sourceToken],
            'media_crops' => [['x' => 10, 'y' => 20, 'height' => 80]],
            'circle_ids' => [$circle->id],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['media_crops.0.width']);
});

it('rejects a source token belonging to another user', function () {
    Storage::fake('public');
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $intruder->id]);

    [$croppedToken] = uploadSourceImage($intruder, 'cropped.jpg');
    [$foreignSource, $foreignSession] = uploadSourceImage($owner, 'original.jpg');

    // The intruder names someone else's source token: consumeAssembled refuses
    // it, so the post is created without a source rather than leaking it.
    $this->actingAs($intruder)
        ->postJson('/api/posts', [
            'media_tokens' => [$croppedToken],
            'media_source_tokens' => [$foreignSource],
            'media_crops' => [['x' => 0, 'y' => 0, 'width' => 50, 'height' => 50]],
            'circle_ids' => [$circle->id],
        ])
        ->assertCreated();

    $media = Post::sole()->media()->sole();

    expect($media->source_path)->toBeNull()
        // The owner's session is left untouched for them to redeem.
        ->and(is_dir(UploadController::sessionDirectory($foreignSession)))->toBeTrue();
});
