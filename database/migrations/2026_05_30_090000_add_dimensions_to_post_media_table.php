<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            // Display pixel dimensions of the media item, orientation-corrected.
            // Used by the client to lay out a masonry grid without waiting for
            // each image to load. Nullable: existing rows are backfilled by the
            // media:backfill-dimensions command, and rows that fail to probe
            // simply fall back to a square tile on the client.
            $table->unsignedInteger('width')->nullable()->after('thumbnail_small_path');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
