<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master satuan (pcs, box, botol, ...). Kode auto SAT-NNN. Dikelola admin
     * lewat /master/satuan dan disediakan sebagai pilihan dropdown lewat
     * endpoint `units/options`.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            // Kode satuan (auto SAT-NNN).
            $table->string('code')->unique();
            $table->string('name');
            $table->text('note')->nullable();
            // Kolom audit standar (lihat HasAuditColumns).
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->unsignedBigInteger('deleted_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
