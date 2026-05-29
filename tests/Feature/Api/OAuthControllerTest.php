<?php

use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

beforeEach(function () {
    config()->set('oauth.mobile_callback', 'innerrapp://oauth/callback');
});

function mockSocialiteUser(string $id, ?string $email, ?string $name): SocialiteUser
{
    $user = Mockery::mock(SocialiteUser::class);
    $user->shouldReceive('getId')->andReturn($id);
    $user->shouldReceive('getEmail')->andReturn($email);
    $user->shouldReceive('getName')->andReturn($name);

    return $user;
}

function mockSocialiteDriver(string $provider, ?SocialiteUser $user, ?Throwable $throws = null): void
{
    $driver = Mockery::mock(AbstractProvider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();

    if ($throws !== null) {
        $driver->shouldReceive('user')->andThrow($throws);
    } else {
        $driver->shouldReceive('user')->andReturn($user);
    }

    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

function appleSocialiteUserWithAudience(string $id, string $email, ?string $name, string $aud): Laravel\Socialite\Two\User
{
    $payload = rtrim(strtr(base64_encode((string) json_encode(['aud' => $aud, 'sub' => $id])), '+/', '-_'), '=');

    $user = new Laravel\Socialite\Two\User;
    $user->map(['id' => $id, 'email' => $email, 'name' => $name]);
    // The Apple provider stores the verified id_token as the user's token.
    $user->token = 'header.'.$payload.'.signature';

    return $user;
}

it('accepts an apple login whose id token audience matches the configured client id', function () {
    config()->set('services.apple.client_id', 'app.innerr.client');

    mockSocialiteDriver('apple', appleSocialiteUserWithAudience(
        'a-aud-ok',
        'ok@privaterelay.appleid.com',
        'Tim',
        'app.innerr.client',
    ));

    $response = $this->post('/api/oauth/apple/callback', ['code' => 'test']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('innerrapp://oauth/callback?token=');
    expect(User::where('apple_id', 'a-aud-ok')->exists())->toBeTrue();
});

it('rejects an apple login whose id token audience is for a different app', function () {
    config()->set('services.apple.client_id', 'app.innerr.client');

    mockSocialiteDriver('apple', appleSocialiteUserWithAudience(
        'a-aud-evil',
        'evil@privaterelay.appleid.com',
        'Mallory',
        'com.attacker.app',
    ));

    $response = $this->post('/api/oauth/apple/callback', ['code' => 'test']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toBe('innerrapp://oauth/callback?error=oauth_failed');
    expect(User::where('apple_id', 'a-aud-evil')->exists())->toBeFalse();
});

it('redirects the mobile app with a token after a successful google login', function () {
    mockSocialiteDriver('google', mockSocialiteUser('g-1', 'jane@example.com', 'Jane Doe'));

    $response = $this->get('/api/oauth/google/callback?code=test');

    $user = User::where('email', 'jane@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->google_id)->toBe('g-1');
    expect($user->password)->toBeNull();

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('innerrapp://oauth/callback?token=');
});

it('reuses an existing user matched by google_id and updates the name', function () {
    $existing = User::factory()->create([
        'email' => 'old@example.com',
        'name' => 'Old Name',
        'google_id' => 'g-2',
    ]);

    mockSocialiteDriver('google', mockSocialiteUser('g-2', 'ignored@example.com', 'New Name'));

    $this->get('/api/oauth/google/callback?code=test')->assertRedirect();

    expect($existing->fresh()->name)->toBe('New Name');
    expect(User::count())->toBe(1);
});

it('auto-links google_id onto an existing password user with the same email', function () {
    $existing = User::factory()->create([
        'email' => 'match@example.com',
        'google_id' => null,
    ]);

    mockSocialiteDriver('google', mockSocialiteUser('g-3', 'match@example.com', 'Any Name'));

    $this->get('/api/oauth/google/callback?code=test')->assertRedirect();

    expect($existing->fresh()->google_id)->toBe('g-3');
    expect(User::count())->toBe(1);
});

it('refuses to auto-link onto an existing account that has not verified its email', function () {
    User::factory()->unverified()->create([
        'email' => 'squat@example.com',
        'google_id' => null,
    ]);

    mockSocialiteDriver('google', mockSocialiteUser('g-squat', 'squat@example.com', 'Real Owner'));

    $response = $this->get('/api/oauth/google/callback?code=test');

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toBe('innerrapp://oauth/callback?error=unverified_account_exists');
    expect(User::where('email', 'squat@example.com')->value('google_id'))->toBeNull();
});

it('generates a unique username when the email local-part collides', function () {
    User::factory()->create(['username' => 'collision']);

    mockSocialiteDriver('google', mockSocialiteUser('g-4', 'collision@example.com', 'Some User'));

    $this->get('/api/oauth/google/callback?code=test')->assertRedirect();

    $new = User::where('email', 'collision@example.com')->first();
    expect($new)->not->toBeNull();
    expect($new->username)->not->toBe('collision');
    expect($new->username)->toStartWith('collision-');
});

it('redirects with an oauth_failed error when Socialite throws', function () {
    mockSocialiteDriver('google', null, new RuntimeException('boom'));

    $response = $this->get('/api/oauth/google/callback?code=test');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toBe('innerrapp://oauth/callback?error=oauth_failed');
});

it('redirects with missing_email when the provider does not return an email', function () {
    mockSocialiteDriver('google', mockSocialiteUser('g-5', null, 'No Email'));

    $response = $this->get('/api/oauth/google/callback?code=test');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toBe('innerrapp://oauth/callback?error=missing_email');
    expect(User::count())->toBe(0);
});

it('accepts a POST callback from Apple', function () {
    mockSocialiteDriver('apple', appleSocialiteUserWithAudience(
        'a-1',
        'tim@privaterelay.appleid.com',
        'Tim',
        (string) config('services.apple.client_id'),
    ));

    $response = $this->post('/api/oauth/apple/callback', ['code' => 'test']);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('innerrapp://oauth/callback?token=');
    expect(User::where('apple_id', 'a-1')->exists())->toBeTrue();
});

it('does not overwrite an existing name when Apple returns a null name on subsequent login', function () {
    $existing = User::factory()->create([
        'email' => 'tim2@privaterelay.appleid.com',
        'name' => 'Tim Original',
        'apple_id' => 'a-2',
    ]);

    mockSocialiteDriver('apple', appleSocialiteUserWithAudience(
        'a-2',
        'tim2@privaterelay.appleid.com',
        null,
        (string) config('services.apple.client_id'),
    ));

    $this->post('/api/oauth/apple/callback', ['code' => 'test'])->assertRedirect();

    expect($existing->fresh()->name)->toBe('Tim Original');
});

it('stores the locale from the Accept-Language header on a new oauth user', function () {
    mockSocialiteDriver('google', mockSocialiteUser('g-locale', 'locale@example.com', 'Locale User'));

    $this->withHeaders(['Accept-Language' => 'nl'])
        ->get('/api/oauth/google/callback?code=test')
        ->assertRedirect();

    expect(User::where('email', 'locale@example.com')->value('locale'))->toBe('nl');
});

it('returns 404 for unsupported providers', function () {
    $this->get('/api/oauth/facebook/redirect')->assertNotFound();
    $this->get('/api/oauth/facebook/callback')->assertNotFound();
});

it('always redirects to the configured mobile callback, ignoring query input', function () {
    config()->set('oauth.mobile_callback', 'innerrapp://oauth/callback');
    mockSocialiteDriver('google', mockSocialiteUser('g-9', 'safe@example.com', 'Safe'));

    $response = $this->get('/api/oauth/google/callback?callback=evil://attacker');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('innerrapp://oauth/callback?token=');
    expect($response->headers->get('Location'))->not->toContain('evil');
});
