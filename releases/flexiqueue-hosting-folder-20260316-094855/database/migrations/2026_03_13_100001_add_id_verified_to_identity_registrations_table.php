<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: traceability when staff verify scanned ID matches stored ID on identity registration.
     */
    public function up(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->timestamp('id_verified_at')->nullable()->after('id_number_last4');
            $table->foreignId('id_verified_by_user_id')->nullable()->after('id_verified_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropForeign(['id_verified_by_user_id']);
            $table->dropColumn(['id_verified_at', 'id_verified_by_user_id']);
        });
    }
};
