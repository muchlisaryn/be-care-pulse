<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel MASTER modul Nafsul dalam satu migrasi.
 *
 * Menggantikan ±17 migrasi lamanya (create per tabel dalam bahasa Indonesia →
 * tambah kolom susulan → rename ke bahasa Inggris → tambah kolom audit → buang
 * kolom yang tidak dipakai → pendidikan & pekerjaan jadi relasi). Tabel langsung
 * dibuat dalam bentuk akhirnya.
 *
 * Cakupannya sebatas master: wilayah, kota, status anggota, pendidikan,
 * pekerjaan, status nikah, ketua kelompok, tarif, anggota, dan keluarga
 * anggota. Tabel transaksi (`transactions`, `transaction_details`) dan
 * pelayanan jenazah (`funeral_services`) SENGAJA tidak dibuat di sini —
 * halaman frontend-nya belum ada di repo ini. Tambahkan lewat migrasi
 * tersendiri saat modulnya menyusul.
 *
 * Sengaja ditaruh paling akhir (timestamp terbaru) dan setiap tabel dijaga
 * `hasTable`, sehingga:
 *   - database baru  → seluruh tabel dibuat di langkah terakhir;
 *   - database lama  → seluruhnya sudah ada, migrasi ini jadi no-op.
 * Dua-duanya cukup dengan `php artisan migrate`, tanpa `migrate:fresh`.
 *
 * Kolom relasi ke master menyimpan id dan hanya diberi indeks (tanpa foreign
 * key constraint), mengikuti pola tabel Nafsul & CSSD lain. Relasi
 * `member_families` ke `members` tetap memakai constraint karena baris
 * anaknya memang tidak boleh menggantung.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Master ber-kode ----
        foreach (['regions', 'cities', 'member_statuses'] as $table) {
            $this->create($table, function (Blueprint $t) {
                $t->id();
                $t->string('code')->unique();
                $t->string('name');
            });
        }

        // ---- Master tanpa kode, dirujuk lewat id ----
        foreach (['educations', 'occupations', 'marital_statuses'] as $table) {
            $this->create($table, function (Blueprint $t) {
                $t->id();
                $t->string('name')->unique();
            });
        }

        $this->create('group_leaders', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->char('gender', 1)->nullable();
            $t->text('address')->nullable();
            $t->string('phone')->nullable();
        });

        $this->create('rates', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('category')->nullable()->index();
            $t->string('rate_group')->nullable();
            $t->string('group_name')->nullable();
            $t->string('name');
            $t->string('rate_code')->nullable();
            $t->text('note')->nullable();
            $t->decimal('price', 15, 2)->default(0);
        });

        // ---- Anggota ----
        $this->create('members', function (Blueprint $t) {
            $t->id();
            $t->string('family_card_number')->nullable();
            $t->string('member_number')->nullable()->index();
            $t->string('name');
            $t->date('birth_date')->nullable();
            $t->char('gender', 1)->nullable();
            $t->unsignedBigInteger('education_id')->nullable()->index();
            $t->unsignedBigInteger('occupation_id')->nullable()->index();
            $t->string('marital_status')->nullable();
            $t->string('id_card_number')->nullable();
            $t->text('address')->nullable();
            $t->string('phone')->nullable();
            $t->date('active_date')->nullable();
            $t->date('inactive_date')->nullable();
            $t->string('note')->nullable();
            $t->string('family_name')->nullable();
            $t->string('relationship')->nullable();
            $t->text('family_address')->nullable();
            $t->string('family_phone')->nullable();
            $t->string('user_code')->nullable();
            $t->string('visit')->nullable();
            $t->date('updated_date')->nullable();
            $t->unsignedBigInteger('region_id')->nullable()->index();
            $t->unsignedBigInteger('birth_city_id')->nullable()->index();
            $t->unsignedBigInteger('member_status_id')->nullable()->index();
            $t->unsignedBigInteger('group_leader_id')->nullable()->index();
        });

        $this->create('member_families', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $t->string('leader_name')->nullable();
            $t->string('member_number')->nullable();
            $t->string('member_name');
            $t->date('birth_date')->nullable();
            $t->char('gender', 1)->nullable();
            $t->string('education')->nullable();
        });
    }

    public function down(): void
    {
        // Urutan terbalik: tabel anak lebih dulu agar constraint-nya ikut lepas.
        foreach ([
            'member_families', 'members', 'rates', 'group_leaders', 'marital_statuses',
            'occupations', 'educations', 'member_statuses', 'cities', 'regions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Buat tabel beserta kolom audit wajib (trait HasAuditColumns), kecuali
     * tabelnya sudah ada — database lama sudah melewati migrasi pendahulunya.
     */
    private function create(string $table, callable $kolom): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $t) use ($kolom) {
            $kolom($t);

            $t->timestamps();
            $t->string('created_by')->nullable();
            $t->string('updated_by')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->string('deleted_by')->nullable()->index();
            $t->unsignedBigInteger('deleted_user_id')->nullable()->index();
        });
    }
};
