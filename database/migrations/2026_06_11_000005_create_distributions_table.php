<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distribusi alat bersih: serah-terima alat steril CSSD → unit/ruangan.
        Schema::create('distributions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();              // Auto DST-001, DST-002, ...
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('distributed_at');
            $table->string('status')->default('terdistribusi'); // terdistribusi | dibatalkan
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
        Schema::dropIfExists('distributions');
    }
};
