<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('cards_per_page')->default(6);
            $table->string('paper', 10)->default('a4');
            $table->string('orientation', 20)->default('portrait');
            $table->boolean('show_hint')->default(true);
            $table->boolean('show_cut_lines')->default(true);
            $table->string('logo_url', 500)->nullable();
            $table->text('footer_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_settings');
    }
};
