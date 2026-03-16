<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per identity binding plan: dedicated audit table for admin ID reveal events.
     */
    public function up(): void
    {
        Schema::create('client_id_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients');
            $table->foreignId('client_id_document_id')->constrained('client_id_documents');
            $table->foreignId('staff_user_id')->constrained('users');
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->string('id_type', 50);
            $table->string('id_last4', 10);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_id_audit_log');
    }
};

