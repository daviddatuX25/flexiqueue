<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per docs/plans/PIN-QR-AUTHORIZATION-SYSTEM.md AUTH-1:
     * temporary_authorizations table, users.override_qr_token column.
     */
    public function up(): void
    {
        Schema::create('temporary_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 255);
            $table->string('type', 10); // pin|qr
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('override_qr_token', 255)->nullable()->after('override_pin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('override_qr_token');
        });

        Schema::dropIfExists('temporary_authorizations');
    }
};
