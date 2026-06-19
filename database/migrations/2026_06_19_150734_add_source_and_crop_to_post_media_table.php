<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            // The uncropped upload kept alongside the cropped `path`/
            // `original_path`. When a user crops a photo in the app the cropped
            // result drives display and print, but the full frame is archived
            // here so the crop can be redone or the whole frame printed later.
            // Null for media that was never cropped (the upload is the full
            // frame already).
            $table->string('source_path')->nullable()->after('original_path');

            // The crop rectangle the app applied, in the source image's
            // orientation-corrected pixels: {x, y, width, height}. Lets a future
            // re-crop UI restore the current framing. Null when uncropped.
            $table->json('crop')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropColumn(['source_path', 'crop']);
        });
    }
};
