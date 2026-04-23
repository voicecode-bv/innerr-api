<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX notifications_notifiable_created_at_index ON notifications (notifiable_type, notifiable_id, created_at DESC)');
        DB::statement('CREATE INDEX notifications_unread_index ON notifications (notifiable_type, notifiable_id) WHERE read_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_unread_index');
        DB::statement('DROP INDEX IF EXISTS notifications_notifiable_created_at_index');
    }
};
