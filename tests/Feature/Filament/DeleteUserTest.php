<?php

use App\Actions\DeleteUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\DeleteUserMedia;
use App\Models\Post;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('deletes the user with its cascaded and non-cascaded records', function () {
    Queue::fake();

    $user = User::factory()->create();
    Post::factory()->count(2)->for($user)->create();

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
});

it('queues a job to wipe the user media folder', function () {
    Queue::fake();

    $user = User::factory()->create();

    app(DeleteUser::class)($user);

    Queue::assertPushed(DeleteUserMedia::class, fn (DeleteUserMedia $job): bool => $job->userId === $user->id);
});

it('wipes the whole user media folder when the job runs', function () {
    $disk = Storage::fake('public');

    $userId = '00000000-0000-0000-0000-000000000001';
    $disk->put("users/{$userId}/posts/photo.jpg", 'x');
    $disk->put("users/{$userId}/originals/posts/photo.jpg", 'y');

    (new DeleteUserMedia($userId))->handle();

    expect($disk->directoryExists("users/{$userId}"))->toBeFalse();
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
