<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MemberPersonSyncer
{
    /**
     * Make sure the user has a Person record (linked via `user_id`) and that
     * Person is attached to the given circle. Idempotent — safe to call when
     * the link or circle attachment already exists.
     */
    public function attach(Circle $circle, User $user): Person
    {
        return DB::transaction(function () use ($circle, $user) {
            $person = Person::where('user_id', $user->id)->first();

            if ($person === null) {
                $person = Person::create([
                    'created_by_user_id' => $user->id,
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                    'avatar_thumbnail' => $user->avatar_thumbnail,
                ]);
            }

            $person->circles()->syncWithoutDetaching([$circle->id]);

            return $person;
        });
    }

    /**
     * Detach the user's member-Person from this circle. The Person record
     * itself is kept so historical post tags remain intact.
     */
    public function detach(Circle $circle, User $user): void
    {
        $personIds = Person::where('user_id', $user->id)->pluck('id');

        if ($personIds->isEmpty()) {
            return;
        }

        DB::table('circle_person')
            ->where('circle_id', $circle->id)
            ->whereIn('person_id', $personIds)
            ->delete();
    }

    /**
     * Detach the user's member-Person from every circle. Used during account
     * anonymization.
     */
    public function detachAll(User $user): void
    {
        $personIds = Person::where('user_id', $user->id)->pluck('id');

        if ($personIds->isEmpty()) {
            return;
        }

        DB::table('circle_person')
            ->whereIn('person_id', $personIds)
            ->delete();
    }
}
