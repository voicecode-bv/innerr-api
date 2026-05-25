<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Collection;
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
     * Bulk variant of {@see self::attach()} for backfilling many users into one
     * circle at once. Skips users already attached and re-uses existing Person
     * records. Caller is responsible for chunking and excluding the owner.
     *
     * @param  Collection<int, User>|iterable<User>  $users
     */
    public function attachUsersBulk(Circle $circle, iterable $users): void
    {
        $users = Collection::make($users);

        if ($users->isEmpty()) {
            return;
        }

        $now = now();
        $userIds = $users->pluck('id')->all();

        DB::table('circle_user')->insertOrIgnore(
            $users->map(fn (User $user) => [
                'circle_id' => $circle->id,
                'user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all()
        );

        $personIdByUser = Person::query()
            ->whereIn('user_id', $userIds)
            ->pluck('id', 'user_id');

        $missingPersonRows = $users
            ->reject(fn (User $user) => $personIdByUser->has($user->id))
            ->map(fn (User $user) => [
                'created_by_user_id' => $user->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'avatar' => $user->avatar,
                'avatar_thumbnail' => $user->avatar_thumbnail,
                'usage_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($missingPersonRows !== []) {
            DB::table('people')->insert($missingPersonRows);

            $personIdByUser = Person::query()
                ->whereIn('user_id', $userIds)
                ->pluck('id', 'user_id');
        }

        DB::table('circle_person')->insertOrIgnore(
            $personIdByUser->map(fn (string $personId) => [
                'circle_id' => $circle->id,
                'person_id' => $personId,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all()
        );
    }

    /**
     * Copy the user's current avatar onto their linked member-Person, if one
     * exists. The Person's avatar columns are a denormalized snapshot of the
     * user avatar; this keeps them in step when the user changes their avatar
     * after the Person row was first created. No-op when the user has no
     * member-Person yet. A user has at most one member-Person, so this updates
     * a single row.
     */
    public function syncAvatar(User $user): void
    {
        Person::where('user_id', $user->id)->update([
            'avatar' => $user->avatar,
            'avatar_thumbnail' => $user->avatar_thumbnail,
        ]);
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
