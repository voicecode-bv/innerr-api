<?php

namespace Database\Seeders;

use App\Models\Comment;
use App\Models\Like;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $mainUser = User::factory()->create([
            'name' => 'Michael Blijleven',
            'username' => 'michaelblijleven',
            'email' => 'michael@michaelblijleven.nl',
        ]);

        $users = User::factory(9)->create();
        $allUsers = $users->push($mainUser);

        $posts = Post::factory(20)
            ->recycle($allUsers)
            ->create();

        $posts->each(function (Post $post) use ($allUsers) {
            Comment::factory(rand(0, 5))
                ->recycle($allUsers)
                ->for($post)
                ->create();

            $likers = $allUsers->random(rand(0, 8));
            foreach ($likers as $user) {
                Like::factory()->for($post)->for($user)->create();
            }
        });
    }
}
