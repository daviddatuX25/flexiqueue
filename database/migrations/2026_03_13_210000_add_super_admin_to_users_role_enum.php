<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add super_admin to users.role enum so SuperAdminSeeder and API can set role = 'super_admin'.
     */
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'staff', 'super_admin') DEFAULT 'staff'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::table('users')->where('role', 'super_admin')->update(['role' => 'admin']);
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'staff') DEFAULT 'staff'");
        }
    }
};
