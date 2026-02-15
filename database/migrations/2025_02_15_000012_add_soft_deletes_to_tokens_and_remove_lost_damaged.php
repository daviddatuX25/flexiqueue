<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per plan: Add soft deletes to tokens; remove lost/damaged status.
     * Migrate existing lost/damaged tokens to soft-deleted (deleted_at set, status = available).
     */
    public function up(): void
    {
        Schema::table('tokens', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Migrate lost/damaged to soft-deleted: set deleted_at and status = available
        DB::table('tokens')
            ->whereIn('status', ['lost', 'damaged'])
            ->update([
                'deleted_at' => now(),
                'status' => 'available',
            ]);

        DB::statement("ALTER TABLE tokens MODIFY COLUMN status ENUM('available', 'in_use') DEFAULT 'available'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE tokens MODIFY COLUMN status ENUM('available', 'in_use', 'lost', 'damaged') DEFAULT 'available'");

        // Restore: previously soft-deleted become lost (best-effort)
        DB::table('tokens')
            ->whereNotNull('deleted_at')
            ->update([
                'deleted_at' => null,
                'status' => 'lost',
            ]);

        Schema::table('tokens', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
