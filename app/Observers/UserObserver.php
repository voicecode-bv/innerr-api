<?php

namespace App\Observers;

use App\Models\Circle;
use App\Models\User;
use App\Services\MemberPersonSyncer;

class UserObserver
{
    public function __construct(private MemberPersonSyncer $memberPersons) {}

    public function created(User $user): void
    {
        Circle::query()
            ->where('auto_add_new_users', true)
            ->where('user_id', '!=', $user->id)
            ->select('id', 'user_id')
            ->chunkById(100, function ($circles) use ($user) {
                foreach ($circles as $circle) {
                    $circle->members()->syncWithoutDetaching([$user->id]);
                    $this->memberPersons->attach($circle, $user);
                }
            });
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('avatar') || $user->wasChanged('avatar_thumbnail')) {
            $this->memberPersons->syncAvatar($user);
        }
    }
}
