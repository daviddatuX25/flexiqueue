<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'deactivated' to token status enum.
     * Deactivated tokens cannot be bound; admin can reactivate.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE tokens MODIFY COLUMN status ENUM('available', 'in_use', 'deactivated') DEFAULT 'available'");
    }

    public function down(): void
    {
        // Migrate deactivated back to available before reverting enum
        DB::table('tokens')->where('status', 'deactivated')->update(['status' => 'available']);
        DB::statement("ALTER TABLE tokens MODIFY COLUMN status ENUM('available', 'in_use') DEFAULT 'available'");
    }
};
