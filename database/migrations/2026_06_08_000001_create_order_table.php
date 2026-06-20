<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nama tabel "order" adalah reserved keyword SQL; di-quote otomatis oleh Laravel.
        Schema::create('order', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('order_date');
            $table->date('return_plan_date')->nullable();
            $table->date('return_actual_date')->nullable();
            $table->string('status')->default('diajukan');
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order');
    }
};
