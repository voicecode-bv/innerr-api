<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grandfather every account that exists before email verification is
     * enforced: mark them verified so only new sign-ups have to verify. We use
     * the account creation time as the verification timestamp and update in
     * batches to avoid locking a large users table in a single statement.
     */
    public function up(): void
    {
        do {
            $updated = DB::table('users')
                ->whereNull('email_verified_at')
                ->limit(2000)
                ->update(['email_verified_at' => DB::raw('COALESCE(created_at, NOW())')]);
        } while ($updated > 0);
    }

    /**
     * Irreversible: the backfilled timestamps cannot be distinguished from
     * genuine verifications, so reversing would wrongly unverify real accounts.
     */
    public function down(): void
    {
        //
    }
};
