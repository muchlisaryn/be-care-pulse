<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_transfers', function (Blueprint $table) {
            // Pinjam-alih bisa untuk pasien berbeda dari order sumber, jadi No. RM &
            // nama pasien dicatat per permintaan. Nullable agar baris lama tetap valid;
            // wajib-isi ditegakkan di validasi request untuk permintaan baru.
            $table->string('medical_record_no')->nullable()->after('borrowed_by');
            $table->string('patient_name')->nullable()->after('medical_record_no');
        });
    }

    public function down(): void
    {
        Schema::table('order_transfers', function (Blueprint $table) {
            $table->dropColumn(['medical_record_no', 'patient_name']);
        });
    }
};
