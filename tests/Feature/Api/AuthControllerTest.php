<?php

use App\Models\User;

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
        ]);

    $this->assertDatabaseHas('users', [
        'email' => 'john@example.com',
        'username' => 'johndoe',
    ]);
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
        ]);
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
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email);
});

it('returns unauthenticated for me endpoint without token', function () {
    $this->getJson('/api/auth/me')
        ->assertUnauthorized();
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
