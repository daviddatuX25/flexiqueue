<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per refactor plan: migrate supervisor users to program_supervisors, change role enum to (admin, staff).
     */
    public function up(): void
    {
        // Migrate existing supervisor users to program_supervisors (for all programs)
        $supervisorIds = DB::table('users')
            ->where('role', 'supervisor')
            ->pluck('id');

        if ($supervisorIds->isNotEmpty()) {
            $programIds = DB::table('programs')->pluck('id');
            foreach ($supervisorIds as $userId) {
                foreach ($programIds as $programId) {
                    DB::table('program_supervisors')->insertOrIgnore([
                        'program_id' => $programId,
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // Update supervisor -> staff
        DB::table('users')->where('role', 'supervisor')->update(['role' => 'staff']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'staff') DEFAULT 'staff'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'supervisor', 'staff') DEFAULT 'staff'");
        }
        DB::table('program_supervisors')->truncate();
    }
};
