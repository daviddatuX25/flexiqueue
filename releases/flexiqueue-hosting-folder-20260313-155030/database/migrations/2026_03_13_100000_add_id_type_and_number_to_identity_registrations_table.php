<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per plan: store scanned ID type and number (encrypted) with last4 for staff display.
     */
    public function up(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->string('id_type', 50)->nullable()->after('client_category');
            $table->text('id_number_encrypted')->nullable()->after('id_type');
            $table->string('id_number_last4', 10)->nullable()->after('id_number_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropColumn(['id_type', 'id_number_encrypted', 'id_number_last4']);
        });
    }
};
