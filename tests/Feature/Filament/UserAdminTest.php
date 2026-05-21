<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows posts count and storage usage for each user', function () {
    $user = User::factory()->create(['storage_used_bytes' => 12_345_678]);
    Post::factory()->count(3)->for($user)->create();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnStateSet('posts_count', 3, $user)
        ->assertTableColumnStateSet('storage_used_bytes', 12_345_678, $user);
});
