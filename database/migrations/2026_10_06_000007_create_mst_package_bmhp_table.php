<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master paket BMHP — satu nama paket untuk sekumpulan BMHP. Kodenya diketik
     * manual, sama seperti `mst_bmhp`.
     */
    public function up(): void
    {
        Schema::create('mst_package_bmhp', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
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
        Schema::dropIfExists('mst_package_bmhp');
    }
};
