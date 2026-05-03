<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * In-place conversion of all integer primary keys (and their foreign keys) to UUID v7.
 *
 * Idempotent: detects whether `users.id` is already uuid and short-circuits.
 * Strategy per table:
 *   1. Add `new_id uuid` column, fill with Str::uuid7().
 *   2. Add `new_<fk>` columns on every child, backfill via JOIN on parent.new_id.
 *   3. Backfill polymorphic columns (likes, notifications, personal_access_tokens).
 *   4. Backfill JSON / JSONB id references (notifications.data, users.default_circle_ids).
 *   5. Drop ALL FK constraints, drop old PK + old FK columns, rename new_x -> x,
 *      reinstall PK, FK constraints, and indices.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<string, array{table: string, nullable: bool, onDelete: ?string}>>
     */
    private const TABLES = [
        'users' => [],
        'posts' => [
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'comments' => [
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
            'post_id' => ['table' => 'posts', 'nullable' => false, 'onDelete' => 'cascade'],
            'parent_comment_id' => ['table' => 'comments', 'nullable' => true, 'onDelete' => 'cascade'],
        ],
        'likes' => [
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'circles' => [
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'circle_user' => [
            'circle_id' => ['table' => 'circles', 'nullable' => false, 'onDelete' => 'cascade'],
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'circle_post' => [
            'circle_id' => ['table' => 'circles', 'nullable' => false, 'onDelete' => 'cascade'],
            'post_id' => ['table' => 'posts', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'circle_invitations' => [
            'circle_id' => ['table' => 'circles', 'nullable' => false, 'onDelete' => 'cascade'],
            'user_id' => ['table' => 'users', 'nullable' => true, 'onDelete' => 'cascade'],
            'inviter_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'circle_ownership_transfers' => [
            'circle_id' => ['table' => 'circles', 'nullable' => false, 'onDelete' => 'cascade'],
            'from_user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
            'to_user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'tags' => [
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'people' => [
            'created_by_user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
            'user_id' => ['table' => 'users', 'nullable' => true, 'onDelete' => 'set null'],
        ],
        'circle_person' => [
            'circle_id' => ['table' => 'circles', 'nullable' => false, 'onDelete' => 'cascade'],
            'person_id' => ['table' => 'people', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'person_post' => [
            'person_id' => ['table' => 'people', 'nullable' => false, 'onDelete' => 'cascade'],
            'post_id' => ['table' => 'posts', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'plans' => [],
        'prices' => [
            'plan_id' => ['table' => 'plans', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'subscriptions' => [
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
            'plan_id' => ['table' => 'plans', 'nullable' => false, 'onDelete' => null],
            'price_id' => ['table' => 'prices', 'nullable' => true, 'onDelete' => null],
        ],
        'subscription_events' => [
            'subscription_id' => ['table' => 'subscriptions', 'nullable' => true, 'onDelete' => 'set null'],
            'user_id' => ['table' => 'users', 'nullable' => true, 'onDelete' => 'set null'],
        ],
        'subscription_transactions' => [
            'subscription_id' => ['table' => 'subscriptions', 'nullable' => false, 'onDelete' => 'cascade'],
            'user_id' => ['table' => 'users', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
        'waiting_list_entries' => [],
    ];

    /**
     * Composite-PK pivot: needs FK swap + composite PK reinstall, no `id` column.
     *
     * @var array<string, array<string, array{table: string, nullable: bool, onDelete: ?string}>>
     */
    private const COMPOSITE_PK_TABLES = [
        'post_tag' => [
            'post_id' => ['table' => 'posts', 'nullable' => false, 'onDelete' => 'cascade'],
            'tag_id' => ['table' => 'tags', 'nullable' => false, 'onDelete' => 'cascade'],
        ],
    ];

    /**
     * sessions has a string PK; we only need to swap user_id (no FK constraint, just an index).
     *
     * @var array<string, array<string, array{table: string, nullable: bool, onDelete: ?string}>>
     */
    private const FK_ONLY_TABLES = [
        'sessions' => [
            'user_id' => ['table' => 'users', 'nullable' => true, 'onDelete' => null],
        ],
    ];

    /**
     * Polymorphic id columns. All currently point to App\Models\User in this app, but we
     * resolve via the type column so this still works if more morph targets are added.
     *
     * @var array<string, array{id: string, type: string}>
     */
    private const POLYMORPHIC = [
        'likes' => ['id' => 'likeable_id', 'type' => 'likeable_type'],
        'notifications' => ['id' => 'notifiable_id', 'type' => 'notifiable_type'],
        'personal_access_tokens' => ['id' => 'tokenable_id', 'type' => 'tokenable_type'],
    ];

    /**
     * morph type → parent table for polymorphic + JSON backfill.
     *
     * @var array<string, string>
     */
    private const MORPH_MAP = [
        'App\\Models\\User' => 'users',
        'App\\Models\\Post' => 'posts',
        'App\\Models\\Comment' => 'comments',
    ];

    /**
     * notifications.data JSON keys → parent table for backfill.
     *
     * @var array<string, string>
     */
    private const JSON_ID_KEYS = [
        'user_id' => 'users',
        'liker_id' => 'users',
        'inviter_id' => 'users',
        'from_user_id' => 'users',
        'to_user_id' => 'users',
        'post_id' => 'posts',
        'comment_id' => 'comments',
        'circle_id' => 'circles',
        'transfer_id' => 'circle_ownership_transfers',
        'invitation_id' => 'circle_invitations',
    ];

    /**
     * Indices to (re)create after column swaps. Standard FK indices are added
     * automatically by `swapForeignKey()`; this list contains composite and
     * partial indices that the original migrations declared explicitly.
     *
     * @var list<string>
     */
    private const CUSTOM_INDICES = [
        'CREATE INDEX IF NOT EXISTS posts_user_id_created_at_index ON posts (user_id, created_at)',
        'CREATE INDEX IF NOT EXISTS circle_user_user_id_circle_id_index ON circle_user (user_id, circle_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS circle_user_circle_id_user_id_unique ON circle_user (circle_id, user_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS circle_post_circle_id_post_id_unique ON circle_post (circle_id, post_id)',
        'CREATE INDEX IF NOT EXISTS circle_post_post_id_index ON circle_post (post_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS circle_person_circle_id_person_id_unique ON circle_person (circle_id, person_id)',
        'CREATE UNIQUE INDEX IF NOT EXISTS person_post_person_id_post_id_unique ON person_post (person_id, post_id)',
        'CREATE INDEX IF NOT EXISTS comments_post_id_parent_comment_id_index ON comments (post_id, parent_comment_id)',
        'CREATE INDEX IF NOT EXISTS circle_invitations_user_id_status_index ON circle_invitations (user_id, status)',
        'CREATE UNIQUE INDEX IF NOT EXISTS likes_user_id_likeable_id_likeable_type_unique ON likes (user_id, likeable_id, likeable_type)',
        'CREATE INDEX IF NOT EXISTS likes_likeable_id_likeable_type_index ON likes (likeable_id, likeable_type)',
        'CREATE INDEX IF NOT EXISTS notifications_notifiable_created_at_index ON notifications (notifiable_type, notifiable_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS notifications_unread_index ON notifications (notifiable_type, notifiable_id) WHERE read_at IS NULL',
    ];

    public function up(): void
    {
        if ($this->alreadyConverted()) {
            return;
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->addAndFillNewId($table);
        }

        foreach (self::TABLES as $table => $fks) {
            foreach ($fks as $col => $cfg) {
                $this->addAndFillNewFk($table, $col, $cfg);
            }
        }

        foreach (self::COMPOSITE_PK_TABLES as $table => $fks) {
            foreach ($fks as $col => $cfg) {
                $this->addAndFillNewFk($table, $col, $cfg);
            }
        }

        foreach (self::FK_ONLY_TABLES as $table => $fks) {
            foreach ($fks as $col => $cfg) {
                $this->addAndFillNewFk($table, $col, $cfg);
            }
        }

        foreach (self::POLYMORPHIC as $table => $cols) {
            $this->addAndFillNewPolymorphicId($table, $cols['id'], $cols['type']);
        }

        $this->backfillNotificationsJsonbData();
        $this->backfillUsersDefaultCircleIds();

        $this->dropAllForeignKeys();

        foreach (array_keys(self::TABLES) as $table) {
            $this->swapPrimaryKey($table);
        }

        foreach (self::TABLES as $table => $fks) {
            foreach ($fks as $col => $cfg) {
                $this->swapForeignKey($table, $col, $cfg);
            }
        }

        foreach (self::COMPOSITE_PK_TABLES as $table => $fks) {
            foreach ($fks as $col => $cfg) {
                $this->swapForeignKey($table, $col, $cfg);
            }
            DB::statement("ALTER TABLE {$table} ADD PRIMARY KEY (".implode(',', array_keys($fks)).')');
        }

        foreach (self::FK_ONLY_TABLES as $table => $fks) {
            foreach ($fks as $col => $cfg) {
                $this->swapForeignKey($table, $col, $cfg);
            }
        }

        foreach (self::POLYMORPHIC as $table => $cols) {
            $this->swapPolymorphicId($table, $cols['id']);
        }

        foreach (self::CUSTOM_INDICES as $sql) {
            DB::statement($sql);
        }

        $this->createUuidAggregates();
    }

    /**
     * PostgreSQL has no built-in `max(uuid)` / `min(uuid)`. Eloquent's `ofMany()`
     * relation appends `MAX(id)` as a tie-breaker, so we register lexicographic
     * aggregates over uuid (which is correct for UUID v7's time-ordered prefix).
     */
    private function createUuidAggregates(): void
    {
        DB::statement('CREATE OR REPLACE FUNCTION uuid_larger(uuid, uuid) RETURNS uuid AS $$ SELECT CASE WHEN $1 IS NULL THEN $2 WHEN $2 IS NULL THEN $1 WHEN $1 > $2 THEN $1 ELSE $2 END $$ LANGUAGE SQL IMMUTABLE');
        DB::statement('CREATE OR REPLACE FUNCTION uuid_smaller(uuid, uuid) RETURNS uuid AS $$ SELECT CASE WHEN $1 IS NULL THEN $2 WHEN $2 IS NULL THEN $1 WHEN $1 < $2 THEN $1 ELSE $2 END $$ LANGUAGE SQL IMMUTABLE');
        DB::statement('DROP AGGREGATE IF EXISTS max(uuid)');
        DB::statement('DROP AGGREGATE IF EXISTS min(uuid)');
        DB::statement('CREATE AGGREGATE max(uuid) (SFUNC = uuid_larger, STYPE = uuid)');
        DB::statement('CREATE AGGREGATE min(uuid) (SFUNC = uuid_smaller, STYPE = uuid)');
    }

    public function down(): void
    {
        throw new RuntimeException('UUID conversion is not reversible.');
    }

    private function alreadyConverted(): bool
    {
        return Schema::hasTable('users') && Schema::getColumnType('users', 'id') === 'uuid';
    }

    private function addAndFillNewId(string $table): void
    {
        if (! Schema::hasColumn($table, 'new_id')) {
            Schema::table($table, fn (Blueprint $t) => $t->uuid('new_id')->nullable());
        }

        DB::table($table)
            ->whereNull('new_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table): void {
                $values = [];
                foreach ($rows as $row) {
                    $uuid = (string) Str::uuid7();
                    $values[] = '('.(int) $row->id.', '.DB::getPdo()->quote($uuid).'::uuid)';
                }
                if ($values === []) {
                    return;
                }
                DB::statement(
                    "UPDATE {$table} SET new_id = v.new_id FROM (VALUES "
                    .implode(',', $values).
                    ') AS v(id, new_id) WHERE '.$table.'.id = v.id'
                );
            });
    }

    /**
     * @param  array{table: string, nullable: bool, onDelete: ?string}  $cfg
     */
    private function addAndFillNewFk(string $table, string $col, array $cfg): void
    {
        $newCol = "new_{$col}";
        if (! Schema::hasColumn($table, $newCol)) {
            Schema::table($table, fn (Blueprint $t) => $t->uuid($newCol)->nullable());
        }
        DB::statement(
            "UPDATE {$table} SET {$newCol} = parent.new_id FROM {$cfg['table']} parent WHERE {$table}.{$col} = parent.id AND {$table}.{$newCol} IS NULL"
        );
    }

    private function addAndFillNewPolymorphicId(string $table, string $idCol, string $typeCol): void
    {
        $newCol = "new_{$idCol}";
        if (! Schema::hasColumn($table, $newCol)) {
            Schema::table($table, fn (Blueprint $t) => $t->uuid($newCol)->nullable());
        }
        foreach (self::MORPH_MAP as $morphType => $parentTable) {
            DB::statement(
                "UPDATE {$table} SET {$newCol} = parent.new_id FROM {$parentTable} parent WHERE {$table}.{$idCol} = parent.id AND {$table}.{$typeCol} = ? AND {$table}.{$newCol} IS NULL",
                [$morphType]
            );
        }

        $orphanCount = DB::table($table)->whereNull($newCol)->count();
        if ($orphanCount > 0) {
            DB::table($table)->whereNull($newCol)->delete();
        }
    }

    private function backfillNotificationsJsonbData(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $data = json_decode($row->data, true);
                if (! is_array($data)) {
                    continue;
                }
                $changed = false;
                foreach (self::JSON_ID_KEYS as $key => $parentTable) {
                    if (! array_key_exists($key, $data)) {
                        continue;
                    }
                    $oldId = $data[$key];
                    if (! is_numeric($oldId)) {
                        continue;
                    }
                    $newId = DB::table($parentTable)->where('id', (int) $oldId)->value('new_id');
                    if ($newId !== null) {
                        $data[$key] = (string) $newId;
                        $changed = true;
                    }
                }
                if ($changed) {
                    DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
                }
            }
        }, 'id');
    }

    private function backfillUsersDefaultCircleIds(): void
    {
        DB::table('users')
            ->whereNotNull('default_circle_ids')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $ids = json_decode($user->default_circle_ids, true);
                    if (! is_array($ids) || $ids === []) {
                        continue;
                    }
                    $newIds = DB::table('circles')->whereIn('id', $ids)->pluck('new_id')->all();
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['default_circle_ids' => json_encode(array_map('strval', $newIds))]);
                }
            });
    }

    private function dropAllForeignKeys(): void
    {
        $constraints = DB::select(
            "SELECT tc.table_name, tc.constraint_name FROM information_schema.table_constraints tc WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()"
        );
        foreach ($constraints as $c) {
            DB::statement("ALTER TABLE {$c->table_name} DROP CONSTRAINT IF EXISTS {$c->constraint_name}");
        }
    }

    private function swapPrimaryKey(string $table): void
    {
        $pk = DB::selectOne(
            "SELECT constraint_name FROM information_schema.table_constraints WHERE table_name = ? AND constraint_type = 'PRIMARY KEY' AND table_schema = current_schema()",
            [$table]
        );
        if ($pk !== null) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$pk->constraint_name}");
        }
        if (Schema::hasColumn($table, 'id')) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN id CASCADE");
        }
        DB::statement("ALTER TABLE {$table} RENAME COLUMN new_id TO id");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET NOT NULL");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN id SET DEFAULT gen_random_uuid()");
        DB::statement("ALTER TABLE {$table} ADD PRIMARY KEY (id)");
    }

    /**
     * @param  array{table: string, nullable: bool, onDelete: ?string}  $cfg
     */
    private function swapForeignKey(string $table, string $col, array $cfg): void
    {
        if (Schema::hasColumn($table, $col)) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$col} CASCADE");
        }
        DB::statement("ALTER TABLE {$table} RENAME COLUMN new_{$col} TO {$col}");
        if (! $cfg['nullable']) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} SET NOT NULL");
        }
        if ($cfg['table'] !== 'users' || $table !== 'sessions') {
            $onDelete = $cfg['onDelete'] !== null ? ' ON DELETE '.strtoupper($cfg['onDelete']) : '';
            DB::statement(
                "ALTER TABLE {$table} ADD FOREIGN KEY ({$col}) REFERENCES {$cfg['table']}(id){$onDelete}"
            );
        }
        DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$col}_index ON {$table} ({$col})");
    }

    private function swapPolymorphicId(string $table, string $idCol): void
    {
        if (Schema::hasColumn($table, $idCol)) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$idCol} CASCADE");
        }
        DB::statement("ALTER TABLE {$table} RENAME COLUMN new_{$idCol} TO {$idCol}");
        DB::statement("ALTER TABLE {$table} ALTER COLUMN {$idCol} SET NOT NULL");
    }
};
