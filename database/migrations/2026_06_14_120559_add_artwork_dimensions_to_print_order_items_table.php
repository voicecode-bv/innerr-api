<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The resolved PDF page size (mm) for this line, snapshotted at order time
     * from the offering's artwork config plus the chosen options. Null falls
     * back to the config/print.php dimensions when the artwork is generated.
     */
    public function up(): void
    {
        Schema::table('print_order_items', function (Blueprint $table): void {
            $table->unsignedInteger('artwork_width_mm')->nullable()->after('options');
            $table->unsignedInteger('artwork_height_mm')->nullable()->after('artwork_width_mm');
        });
    }

    public function down(): void
    {
        Schema::table('print_order_items', function (Blueprint $table): void {
            $table->dropColumn(['artwork_width_mm', 'artwork_height_mm']);
        });
    }
};
