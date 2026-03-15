<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Per addition-to-public-site-plan Part 2.4 + Addition A.1: opaque QR short links; embedded_key for scannable private QR.
     */
    public function up(): void
    {
        Schema::create('site_short_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('type', 32); // site_entry, program_public, program_private
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained('programs')->cascadeOnDelete();
            $table->text('embedded_key')->nullable(); // encrypted program key for scannable private QR
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('site_short_links', function (Blueprint $table) {
            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_short_links');
    }
};
