<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('tier');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->jsonb('features')->default('{}');
            $table->jsonb('entitlements')->default('[]');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('is_active');
            $table->index('tier');
        });

        DB::statement('CREATE UNIQUE INDEX plans_only_one_default ON plans (is_default) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
