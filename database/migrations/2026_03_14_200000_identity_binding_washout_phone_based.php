<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per PRIVACY-BY-DESIGN-IDENTITY-BINDING.md: Replace ID document system with phone-based identity.
 * Drops client_id_documents, client_id_audit_log; alters clients, identity_registrations;
 * recreates client_id_audit_log with new schema. DB can be reseeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('client_id_audit_log');
        Schema::dropIfExists('client_id_documents');

        Schema::table('clients', function (Blueprint $table) {
            $table->text('mobile_encrypted')->nullable()->after('birth_year');
            $table->string('mobile_hash', 64)->nullable()->after('mobile_encrypted');
        });

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropForeign(['id_verified_by_user_id']);
            $table->dropColumn(['id_type', 'id_number_encrypted', 'id_number_last4', 'id_verified_at', 'id_verified_by_user_id']);
        });

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->text('mobile_encrypted')->nullable()->after('client_category');
            $table->string('mobile_hash', 64)->nullable()->after('mobile_encrypted');
            $table->boolean('id_verified')->default(false)->after('mobile_hash');
            $table->foreignId('id_verified_by_user_id')->nullable()->after('id_verified')->constrained('users')->nullOnDelete();
            $table->timestamp('id_verified_at')->nullable()->after('id_verified_by_user_id');
        });

        Schema::create('client_id_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('identity_registration_id')->nullable()->constrained('identity_registrations')->nullOnDelete();
            $table->foreignId('staff_user_id')->constrained('users');
            $table->string('action', 50); // phone_reveal | phone_update
            $table->string('mobile_last2', 2)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_id_audit_log');

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->dropForeign(['id_verified_by_user_id']);
            $table->dropColumn(['mobile_encrypted', 'mobile_hash', 'id_verified', 'id_verified_by_user_id', 'id_verified_at']);
        });

        Schema::table('identity_registrations', function (Blueprint $table) {
            $table->string('id_type', 50)->nullable()->after('client_category');
            $table->text('id_number_encrypted')->nullable()->after('id_type');
            $table->string('id_number_last4', 10)->nullable()->after('id_number_encrypted');
            $table->timestamp('id_verified_at')->nullable()->after('id_number_last4');
            $table->foreignId('id_verified_by_user_id')->nullable()->after('id_verified_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['mobile_encrypted', 'mobile_hash']);
        });

        Schema::create('client_id_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('id_type', 50);
            $table->text('id_number_encrypted');
            $table->string('id_number_hash', 64);
            $table->timestamps();
            $table->unique(['id_type', 'id_number_hash'], 'uidx_client_id_documents_type_hash');
        });

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
};
