<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per ISSUES-ELABORATION §11: log triage scan attempts; result 'not_found' flags potentially fabricated/invalid.
     */
    public function up(): void
    {
        Schema::create('triage_scan_log', function (Blueprint $table) {
            $table->id();
            $table->string('physical_id', 64)->nullable();
            $table->string('qr_hash', 64)->nullable();
            $table->string('result', 32); // available, not_found, deactivated, in_use
            $table->unsignedBigInteger('token_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_scan_log');
    }
};
