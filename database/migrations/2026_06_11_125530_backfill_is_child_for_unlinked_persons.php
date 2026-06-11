<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Persons without a linked user account are children in practice: the
     * app's only create paths are the onboarding (which sets `is_child`) and
     * the settings page (which historically did not). Those unflagged
     * children fell outside the parents model entirely — `manageParents`
     * requires the flag — so backfill the flag and give them their creator
     * as first parent, mirroring the person_parents backfill.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE people
            SET is_child = true
            WHERE user_id IS NULL AND is_child = false
        SQL);

        DB::statement(<<<'SQL'
            INSERT INTO person_parents (person_id, user_id, created_at, updated_at)
            SELECT id, created_by_user_id, NOW(), NOW()
            FROM people
            WHERE is_child = true AND created_by_user_id IS NOT NULL
            ON CONFLICT (person_id, user_id) DO NOTHING
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data backfill; nothing sensible to reverse.
    }
};
