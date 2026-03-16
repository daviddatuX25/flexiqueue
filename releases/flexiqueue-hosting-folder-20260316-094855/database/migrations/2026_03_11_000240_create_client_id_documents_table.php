<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per FLEXIQUEUE-XM2O configurable token-to-client binding:
     * client_id_documents stores encrypted ID numbers and deterministic hashes
     * for lookup/dedup. id_type is an application-level enum string, not a DB enum,
     * so we can add new types without further schema changes.
     */
    public function up(): void
    {
        Schema::create('client_id_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('id_type', 50);
            $table->text('id_number_encrypted');
            $table->string('id_number_hash', 64);
            $table->timestamps();

            $table->unique(['id_type', 'id_number_hash'], 'uidx_client_id_documents_type_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_id_documents');
    }
};

