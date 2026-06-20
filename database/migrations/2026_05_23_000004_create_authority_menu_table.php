<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_menu', function (Blueprint $table) {
            $table->id();
            $table->foreignId('authority_id')->constrained('authorities')->cascadeOnDelete();
            $table->foreignId('menu_id')->constrained('menus')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['authority_id', 'menu_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_menu');
    }
};
