<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $now = now();

            // Build the full list of (circle_id, user_id) pairs that should
            // have a member-Person: the circle's owner plus every accepted
            // member.
            $pairs = DB::table('circles')
                ->select('id as circle_id', 'user_id')
                ->union(
                    DB::table('circle_user')->select('circle_id', 'user_id')
                )
                ->get();

            foreach ($pairs->groupBy('user_id') as $userId => $rows) {
                $userId = (int) $userId;

                $personId = DB::table('people')
                    ->where('user_id', $userId)
                    ->value('id');

                if ($personId === null) {
                    $user = DB::table('users')
                        ->where('id', $userId)
                        ->first(['id', 'name', 'avatar', 'avatar_thumbnail']);

                    if ($user === null) {
                        continue;
                    }

                    $personId = DB::table('people')->insertGetId([
                        'created_by_user_id' => $user->id,
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'avatar' => $user->avatar,
                        'avatar_thumbnail' => $user->avatar_thumbnail,
                        'usage_count' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $existingCircleIds = DB::table('circle_person')
                    ->where('person_id', $personId)
                    ->pluck('circle_id')
                    ->all();

                $newRows = [];
                foreach ($rows as $row) {
                    $circleId = (int) $row->circle_id;
                    if (in_array($circleId, $existingCircleIds, true)) {
                        continue;
                    }

                    $newRows[] = [
                        'circle_id' => $circleId,
                        'person_id' => $personId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($newRows !== []) {
                    DB::table('circle_person')->insert($newRows);
                }
            }
        });
    }

    public function down(): void
    {
        // Irreversible: deleting auto-created Persons would remove person tags
        // from posts created in the meantime. Leave as-is.
    }
};
