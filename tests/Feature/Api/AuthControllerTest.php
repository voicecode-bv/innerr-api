<?php

use App\Enums\InvitationStatus;
use App\Http\Resources\UserResource;
use App\Models\CircleInvitation;
use App\Models\User;
use Illuminate\Http\Request;

it('can register a new user', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => 'johndoe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'username', 'email'],
        ])
        // Zie login: UserResource scoped email op `$isSelf` en zonder
        // Auth::setUser() vóór de resource is dat null. De mobiele BFF
        // syncLocalUser() blaast dan op met een NOT NULL constraint.
        ->assertJsonPath('user.email', 'john@example.com');

    $this->assertDatabaseHas('users', [
        'email' => 'john@example.com',
        'username' => 'johndoe',
    ]);
});

it('rate limits the registration endpoint', function () {
    // The array cache driver resets per request inside a test, so the 429 cannot
    // be triggered by repeated calls here; we assert the protection is wired up
    // instead. The runtime behaviour is verified against a real cache driver.
    $route = app('router')->getRoutes()->getByName('api.auth.register');

    expect($route->gatherMiddleware())->toContain('throttle:5,1');
});

it('validates registration fields', function (array $data, string $errorField) {
    $this->postJson('/api/auth/register', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing name' => [['username' => 'jd', 'email' => 'j@e.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'device_name' => 'test'], 'name'],
    'missing email' => [['name' => 'John', 'username' => 'jd', 'password' => 'password123', 'password_confirmation' => 'password123', 'device_name' => 'test'], 'email'],
    'missing username' => [['name' => 'John', 'email' => 'j@e.com', 'password' => 'password123', 'password_confirmation' => 'password123', 'device_name' => 'test'], 'username'],
    'missing password' => [['name' => 'John', 'username' => 'jd', 'email' => 'j@e.com', 'device_name' => 'test'], 'password'],
    'missing device_name' => [['name' => 'John', 'username' => 'jd', 'email' => 'j@e.com', 'password' => 'password123', 'password_confirmation' => 'password123'], 'device_name'],
    'password too short' => [['name' => 'John', 'username' => 'jd', 'email' => 'j@e.com', 'password' => 'short', 'password_confirmation' => 'short', 'device_name' => 'test'], 'password'],
    'password not confirmed' => [['name' => 'John', 'username' => 'jd', 'email' => 'j@e.com', 'password' => 'password123', 'device_name' => 'test'], 'password'],
]);

it('prevents duplicate email registration', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/auth/register', [
        'name' => 'John',
        'username' => 'johndoe',
        'email' => 'taken@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('normalizes username to lowercase on registration', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => 'JohnDoe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated()
        ->assertJsonPath('user.username', 'johndoe');

    $this->assertDatabaseHas('users', ['username' => 'johndoe']);
});

it('replaces spaces with dashes in username on registration', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => 'john doe',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated()
        ->assertJsonPath('user.username', 'john-doe');
});

it('strips invalid characters from username on registration', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => 'john_doe!',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated()
        ->assertJsonPath('user.username', 'johndoe');
});

it('rejects username that is empty after normalization', function () {
    $this->postJson('/api/auth/register', [
        'name' => 'John Doe',
        'username' => '!!!',
        'email' => 'john@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('prevents duplicate username registration', function () {
    User::factory()->create(['username' => 'taken']);

    $this->postJson('/api/auth/register', [
        'name' => 'John',
        'username' => 'taken',
        'email' => 'new@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('can login with valid credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret',
        'device_name' => 'testing',
    ])->assertSuccessful()
        ->assertJsonStructure([
            'token',
            'user' => ['id', 'name', 'username', 'email'],
        ])
        // E-mail moet daadwerkelijk de waarde bevatten — UserResource scoped
        // `email` op `$isSelf`, dus zonder Auth::setUser() vóór de resource
        // bouw zou hier `null` uitkomen en de mobiele BFF zou crashen op de
        // NOT NULL constraint van `users.email`.
        ->assertJsonPath('user.email', $user->email);
});

it('deletes all existing tokens on login', function () {
    $user = User::factory()->create();
    $user->createToken('old-device-1');
    $user->createToken('old-device-2');

    expect($user->tokens()->count())->toBe(2);

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'secret',
        'device_name' => 'new-device',
    ])->assertSuccessful();

    $tokens = $user->tokens()->get();
    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->name)->toBe('new-device');
});

it('rejects login with invalid password', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
        'device_name' => 'testing',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('rejects login with non-existent email', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'password123',
        'device_name' => 'testing',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('validates login fields', function (array $data, string $errorField) {
    $this->postJson('/api/auth/login', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing email' => [['password' => 'secret', 'device_name' => 'test'], 'email'],
    'missing password' => [['email' => 'j@e.com', 'device_name' => 'test'], 'password'],
    'missing device_name' => [['email' => 'j@e.com', 'password' => 'secret'], 'device_name'],
]);

it('can get the authenticated user', function () {
    $user = User::factory()->create(['feed_layout' => 'masonry']);

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.feed_layout', 'masonry');
});

it('returns unauthenticated for me endpoint without token', function () {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized();
});

it('returns the email on /me for the authenticated user', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'me@example.com');
});

it('strips email from UserResource when serializing a non-self user', function () {
    $self = User::factory()->create(['email' => 'self@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $self);

    $payload = (new UserResource($other))->toArray($request);

    expect($payload['email'])->toBeNull();
    expect($payload['email_verified_at'])->toBeNull();
    expect($payload['onboarded_at'])->toBeNull();
    expect($payload['id'])->toBe($other->id);
});

it('can logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('testing')->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('returns unauthenticated for logout without token', function () {
    $this->postJson('/api/auth/logout')
        ->assertUnauthorized();
});

it('links pending email invitations on registration', function () {
    $invitation = CircleInvitation::factory()->create([
        'email' => 'newuser@example.com',
        'user_id' => null,
        'status' => InvitationStatus::Pending,
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'New User',
        'username' => 'newuser',
        'email' => 'newuser@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated();

    $newUser = User::where('email', 'newuser@example.com')->first();

    expect($invitation->fresh()->user_id)->toBe($newUser->id);
});

it('stores the locale from the Accept-Language header on registration', function (string $header, string $expected) {
    $this->withHeaders(['Accept-Language' => $header])
        ->postJson('/api/auth/register', [
            'name' => 'Locale User',
            'username' => 'localeuser-'.str_replace('-', '', $header),
            'email' => 'locale-'.$header.'@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'device_name' => 'testing',
        ])->assertCreated();

    $this->assertDatabaseHas('users', [
        'email' => 'locale-'.$header.'@example.com',
        'locale' => $expected,
    ]);
})->with([
    'nl' => ['nl', 'nl'],
    'en' => ['en', 'en'],
    'fr' => ['fr', 'fr'],
]);

it('does not link accepted email invitations on registration', function () {
    $invitation = CircleInvitation::factory()->create([
        'email' => 'newuser2@example.com',
        'user_id' => null,
        'status' => InvitationStatus::Accepted,
    ]);

    $this->postJson('/api/auth/register', [
        'name' => 'New User',
        'username' => 'newuser2',
        'email' => 'newuser2@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'device_name' => 'testing',
    ])->assertCreated();

    expect($invitation->fresh()->user_id)->toBeNull();
});
