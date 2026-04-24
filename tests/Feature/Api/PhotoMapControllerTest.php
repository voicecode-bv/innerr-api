<?php

use App\Models\Circle;
use App\Models\Post;
use App\Models\User;
use MatanYadaev\EloquentSpatial\Enums\Srid;
use MatanYadaev\EloquentSpatial\Objects\Point;

// Amsterdam-ish bbox: west,south,east,north
const BBOX = '4.7,52.3,5.0,52.5';

function pointInBbox(): Point
{
    return new Point(52.37, 4.89, Srid::WGS84->value);
}

function pointOutsideBbox(): Point
{
    return new Point(40.71, -74.00, Srid::WGS84->value);
}

it('requires authentication', function () {
    $this->getJson('/api/photos/map?bbox='.BBOX)
        ->assertUnauthorized();
});

it('requires a bbox parameter', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/photos/map')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bbox']);
});

it('rejects a malformed bbox', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/photos/map?bbox=not,a,bbox')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bbox']);
});

it('rejects a bbox with swapped corners', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/photos/map?bbox=5.0,52.5,4.7,52.3')
        ->assertStatus(422);
});

it('rejects a bbox out of range', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/photos/map?bbox=-200,-100,200,100')
        ->assertStatus(422);
});

it('returns own posts with coordinates inside the bbox as GeoJSON', function () {
    $user = User::factory()->create();
    $post = Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonPath('type', 'FeatureCollection')
        ->assertJsonPath('truncated', false)
        ->assertJsonPath('features.0.id', $post->id)
        ->assertJsonPath('features.0.type', 'Feature')
        ->assertJsonPath('features.0.geometry.type', 'Point')
        ->assertJsonPath('features.0.geometry.coordinates.0', $post->longitude)
        ->assertJsonPath('features.0.geometry.coordinates.1', $post->latitude)
        ->assertJsonPath('features.0.properties.post_id', $post->id)
        ->assertJsonPath('features.0.properties.media_type', 'image');
});

it('excludes posts outside the bbox', function () {
    $user = User::factory()->create();
    Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => pointOutsideBbox(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(0, 'features');
});

it('excludes posts without coordinates', function () {
    $user = User::factory()->create();
    Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => null,
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(0, 'features');
});

it('returns posts from circles the user owns', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create(['coordinates' => pointInBbox()]);
    $post->circles()->attach($circle);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.id', $post->id);
});

it('returns posts from circles the user is a member of', function () {
    $user = User::factory()->create();
    $circle = Circle::factory()->create();
    $circle->members()->attach($user);
    $post = Post::factory()->create(['coordinates' => pointInBbox()]);
    $post->circles()->attach($circle);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(1, 'features');
});

it('does not return posts from circles the user has no access to', function () {
    $user = User::factory()->create();
    $otherCircle = Circle::factory()->create();
    $post = Post::factory()->create(['coordinates' => pointInBbox()]);
    $post->circles()->attach($otherCircle);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(0, 'features');
});

it('does not return duplicate posts shared with multiple accessible circles', function () {
    $user = User::factory()->create();
    $circle1 = Circle::factory()->create(['user_id' => $user->id]);
    $circle2 = Circle::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create(['coordinates' => pointInBbox()]);
    $post->circles()->attach([$circle1->id, $circle2->id]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(1, 'features');
});

it('accepts a full-globe bbox without triggering the PostGIS antipodal error', function () {
    $user = User::factory()->create();
    $inside = Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);
    $faraway = Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => pointOutsideBbox(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox=-180,-90,180,90')
        ->assertSuccessful()
        ->assertJsonCount(2, 'features')
        ->assertJsonPath('type', 'FeatureCollection');

    expect([$inside->id, $faraway->id])->toHaveCount(2);
});

it('excludes videos by default', function () {
    $user = User::factory()->create();
    Post::factory()->video()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX)
        ->assertSuccessful()
        ->assertJsonCount(0, 'features');
});

it('includes videos when media_type=all', function () {
    $user = User::factory()->create();
    Post::factory()->video()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);
    Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX.'&media_type=all')
        ->assertSuccessful()
        ->assertJsonCount(2, 'features');
});

it('returns only videos when media_type=video', function () {
    $user = User::factory()->create();
    $video = Post::factory()->video()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);
    Post::factory()->create([
        'user_id' => $user->id,
        'coordinates' => pointInBbox(),
    ]);

    $this->actingAs($user)
        ->getJson('/api/photos/map?bbox='.BBOX.'&media_type=video')
        ->assertSuccessful()
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.id', $video->id);
});

describe('profile map', function () {
    it('requires authentication', function () {
        $target = User::factory()->create();

        $this->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertUnauthorized();
    });

    it('returns 404 for an unknown username', function () {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/profiles/does-not-exist/photos/map?bbox='.BBOX)
            ->assertNotFound();
    });

    it('requires a bbox parameter', function () {
        $target = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/profiles/'.$target->username.'/photos/map')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bbox']);
    });

    it('returns all own posts when viewing own profile, regardless of circle membership', function () {
        $user = User::factory()->create();
        $post = Post::factory()->create([
            'user_id' => $user->id,
            'coordinates' => pointInBbox(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/profiles/'.$user->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', $post->id);
    });

    it('returns a target user\'s post when it is in a circle the requester owns', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $requester->id]);
        $post = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $post->circles()->attach($circle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', $post->id);
    });

    it('returns a target user\'s post when it is in a circle the requester is a member of', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $circle = Circle::factory()->create();
        $circle->members()->attach($requester);
        $post = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $post->circles()->attach($circle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', $post->id);
    });

    it('does not return a target user\'s post when the requester shares no circle with it', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $otherCircle = Circle::factory()->create();
        $post = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $post->circles()->attach($otherCircle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(0, 'features');
    });

    it('does not return a target user\'s post that is not attached to any circle', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(0, 'features');
    });

    it('returns an empty FeatureCollection when requester and target share no circles', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $targetCircle = Circle::factory()->create(['user_id' => $target->id]);
        $post = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $post->circles()->attach($targetCircle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonCount(0, 'features');
    });

    it('does not return posts by users other than the target', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $someoneElse = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $requester->id]);
        $otherPost = Post::factory()->create([
            'user_id' => $someoneElse->id,
            'coordinates' => pointInBbox(),
        ]);
        $otherPost->circles()->attach($circle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(0, 'features');
    });

    it('excludes target posts outside the bbox', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $requester->id]);
        $post = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointOutsideBbox(),
        ]);
        $post->circles()->attach($circle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(0, 'features');
    });

    it('does not return duplicate posts shared with multiple accessible circles', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $circle1 = Circle::factory()->create(['user_id' => $requester->id]);
        $circle2 = Circle::factory()->create(['user_id' => $requester->id]);
        $post = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $post->circles()->attach([$circle1->id, $circle2->id]);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(1, 'features');
    });

    it('excludes target videos by default', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $requester->id]);
        $video = Post::factory()->video()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $video->circles()->attach($circle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX)
            ->assertSuccessful()
            ->assertJsonCount(0, 'features');
    });

    it('includes target videos when media_type=video', function () {
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $circle = Circle::factory()->create(['user_id' => $requester->id]);
        $video = Post::factory()->video()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $video->circles()->attach($circle);
        $image = Post::factory()->create([
            'user_id' => $target->id,
            'coordinates' => pointInBbox(),
        ]);
        $image->circles()->attach($circle);

        $this->actingAs($requester)
            ->getJson('/api/profiles/'.$target->username.'/photos/map?bbox='.BBOX.'&media_type=video')
            ->assertSuccessful()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', $video->id);
    });
});
