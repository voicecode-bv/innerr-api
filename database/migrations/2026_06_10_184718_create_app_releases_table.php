<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // One row per store platform (ios / android).
            $table->string('platform')->unique();
            // Newest version live in the store; drives the dismissible
            // "update available" card in the app.
            $table->string('latest_version')->nullable();
            // Oldest version still allowed to talk to the API; anything below
            // gets the blocking "update required" screen.
            $table->string('minimum_version')->nullable();
            $table->string('store_url')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_releases');
    }
};
