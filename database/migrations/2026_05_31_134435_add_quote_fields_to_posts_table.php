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
        Schema::table('posts', function (Blueprint $table) {
            // Discriminates a quote post (user-written child quote rendered onto
            // a background) from a regular media post. Existing rows default to
            // 'media', so old clients that never send `type` keep working.
            $table->string('type')->default('media')->index()->after('user_id');
            $table->text('quote_text')->nullable()->after('caption');
            $table->string('quote_author')->nullable()->after('quote_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['type', 'quote_text', 'quote_author']);
        });
    }
};
