<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edge_sync_conflicts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('edge_device_id');
            $table->string('table_name', 100);
            $table->unsignedBigInteger('record_id');
            $table->string('conflict_type', 50);
            $table->text('resolution');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edge_sync_conflicts');
    }
};
