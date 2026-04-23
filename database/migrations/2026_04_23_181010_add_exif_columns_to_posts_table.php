<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('taken_at')->nullable()->after('location');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE posts ADD COLUMN coordinates geography(Point, 4326)');
            DB::statement('CREATE INDEX posts_coordinates_gist ON posts USING GIST (coordinates)');
        } else {
            // Fallback for non-Postgres environments (e.g. SQLite quick checks).
            // Production and tests run on Postgres + PostGIS.
            Schema::table('posts', function (Blueprint $table) {
                $table->json('coordinates')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS posts_coordinates_gist');
            DB::statement('ALTER TABLE posts DROP COLUMN IF EXISTS coordinates');
        } else {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('coordinates');
            });
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('taken_at');
        });
    }
};
