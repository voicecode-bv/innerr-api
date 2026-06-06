<?php

use App\Actions\DeleteUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Post;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('deletes the user, its cascaded records and its media folder', function () {
    $disk = Storage::fake('public');

    $user = User::factory()->create();
    Post::factory()->count(2)->for($user)->create();

    $disk->put("users/{$user->id}/posts/photo.jpg", 'x');
    $disk->put("users/{$user->id}/originals/posts/photo.jpg", 'y');

    DB::table('sessions')->insert([
        'id' => 'sess-1',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'x',
        'last_activity' => now()->timestamp,
    ]);

    app(DeleteUser::class)($user);

    expect(User::find($user->id))->toBeNull();
    expect(Post::where('user_id', $user->id)->count())->toBe(0);
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
    expect($disk->exists("users/{$user->id}/posts/photo.jpg"))->toBeFalse();
    expect($disk->directoryExists("users/{$user->id}"))->toBeFalse();
});

it('deletes a user and its media via the table delete action', function () {
    $disk = Storage::fake('public');

    $user = User::factory()->create();
    $disk->put("users/{$user->id}/posts/photo.jpg", 'x');

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($user));

    expect(User::find($user->id))->toBeNull();
    expect($disk->directoryExists("users/{$user->id}"))->toBeFalse();
});

it('deletes a user and its media via the edit page delete action', function () {
    $disk = Storage::fake('public');

    $user = User::factory()->create();
    $disk->put("users/{$user->id}/posts/photo.jpg", 'x');

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->callAction('delete');

    expect(User::find($user->id))->toBeNull();
    expect($disk->directoryExists("users/{$user->id}"))->toBeFalse();
});

it('deletes multiple users and their media via the bulk action', function () {
    $disk = Storage::fake('public');

    $users = User::factory()->count(2)->create();
    foreach ($users as $user) {
        $disk->put("users/{$user->id}/posts/photo.jpg", 'x');
    }

    Livewire::test(ListUsers::class)
        ->selectTableRecords($users)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    foreach ($users as $user) {
        expect(User::find($user->id))->toBeNull();
        expect($disk->directoryExists("users/{$user->id}"))->toBeFalse();
    }
});
