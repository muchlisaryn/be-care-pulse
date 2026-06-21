<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asesmen clinical pathway — data pasien + diagnosa (mengacu ke satu
     * template/formulir). Pengisian ceklis per poin disimpan di tabel
     * asesmen_point_clinical_pathway.
     */
    public function up(): void
    {
        Schema::create('asesmen_clinical_pathway', function (Blueprint $table) {
            $table->id();
            // Formulir/template yang dipakai (menentukan diagnosa & maksimal hari).
            $table->foreignId('template_id')->constrained('template_clinical_pathway')->cascadeOnDelete();

            // Identitas pasien. Wajib: no_rm & nama_pasien. Sisanya opsional.
            $table->string('no_rm'); // nomor rekam medis (wajib)
            $table->string('nama_pasien');
            $table->string('jenis_kelamin', 1)->nullable(); // L / P
            $table->date('tanggal_lahir')->nullable();

            // Data klinis.
            $table->string('diagnosa_masuk')->nullable();
            $table->string('penyakit_utama')->nullable();
            $table->string('penyakit_penyerta')->nullable();
            $table->string('komplikasi')->nullable();
            $table->string('tindakan')->nullable();
            $table->decimal('bb', 5, 2)->nullable(); // berat badan (kg)
            $table->decimal('tb', 5, 2)->nullable(); // tinggi badan (cm)

            // Perawatan.
            $table->dateTime('tanggal_jam_masuk')->nullable();
            $table->dateTime('tanggal_jam_keluar')->nullable();
            $table->integer('lama_rawat')->nullable();   // hari (diisi manual)
            $table->string('rencana_rawat')->nullable();
            // Ruang rawat diambil dari master ruangan (rooms).
            $table->foreignId('ruang_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->boolean('rujukan')->default(false);  // ya / tidak

            $table->string('updated_by')->nullable();
            $table->string('created_by')->nullable();
            $table->string('deleted_by')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asesmen_clinical_pathway');
    }
};
