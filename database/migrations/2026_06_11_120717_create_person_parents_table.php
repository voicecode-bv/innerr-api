<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('person_parents', function (Blueprint $table) {
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['person_id', 'user_id']);
        });

        // Backfill: every existing child gets its creator as first parent, so
        // management rights are explicit data rather than an implicit fallback.
        DB::statement(<<<'SQL'
            INSERT INTO person_parents (person_id, user_id, created_at, updated_at)
            SELECT id, created_by_user_id, NOW(), NOW()
            FROM people
            WHERE is_child = true AND created_by_user_id IS NOT NULL
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_parents');
    }
};
