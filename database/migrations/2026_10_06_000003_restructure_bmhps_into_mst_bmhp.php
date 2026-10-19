<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Merombak tabel master BMHP dalam satu langkah:
     *
     *  1. `bmhps` → `mst_bmhp`;
     *  2. kolom `description` dibuang;
     *  3. `stock_qty` dari bilangan bulat menjadi desimal (12,2) — stok boleh pecahan;
     *  4. satuan teks bebas `unit` diganti `unit_id` yang merujuk master satuan
     *     (`units`); teks yang sudah ada dicarikan/dibuatkan barisnya lebih dulu;
     *  5. kolom `price` (harga satuan, rupiah) ditambahkan.
     *
     * Kode BMHP kini diketik manual (tidak lagi auto BMHP-NNN) — itu diurus model
     * & controller; kolom `code` beserta indeks uniknya tidak berubah. Foreign key
     * `distribution_items.bmhp_id` ikut berpindah sendiri bersama nama tabelnya.
     *
     * Tiap langkah memeriksa keadaannya dulu, jadi aman dijalankan pada database
     * yang sebagian langkahnya sudah diterapkan.
     */
    public function up(): void
    {
        // 1. Nama tabel.
        if (Schema::hasTable('bmhps') && ! Schema::hasTable('mst_bmhp')) {
            Schema::rename('bmhps', 'mst_bmhp');
        }

        // 2. Kolom deskripsi.
        if (Schema::hasColumn('mst_bmhp', 'description')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        // 3. Stok desimal.
        Schema::table('mst_bmhp', function (Blueprint $table) {
            $table->decimal('stock_qty', 12, 2)->default(0)->change();
        });

        // 4. Satuan dari master satuan.
        if (! Schema::hasColumn('mst_bmhp', 'unit_id')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->foreignId('unit_id')->nullable()->after('name')->constrained('units')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('mst_bmhp', 'unit')) {
            $names = DB::table('mst_bmhp')
                ->whereNotNull('unit')
                ->where('unit', '!=', '')
                ->distinct()
                ->pluck('unit');

            foreach ($names as $name) {
                $nama = trim($name);

                // Dicocokkan tanpa membedakan huruf besar-kecil, di antara satuan aktif.
                $unitId = DB::table('units')
                    ->whereNull('deleted_by')
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($nama)])
                    ->value('id');

                if (! $unitId) {
                    $unitId = DB::table('units')->insertGetId([
                        'code' => $this->nextUnitCode(),
                        'name' => $nama,
                        'created_by' => 'system',
                        'updated_by' => 'system',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('mst_bmhp')->where('unit', $name)->update(['unit_id' => $unitId]);
            }

            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }

        // 5. Harga satuan — BMHP yang sudah ada mulai dari 0.
        if (! Schema::hasColumn('mst_bmhp', 'price')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->decimal('price', 15, 2)->default(0)->after('stock_qty');
            });
        }
    }

    /**
     * Bentuk lamanya dikembalikan. ISI `description` yang sudah dibuang tidak bisa
     * dipulihkan, dan pecahan pada stok dibulatkan oleh database.
     */
    public function down(): void
    {
        if (Schema::hasColumn('mst_bmhp', 'price')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->dropColumn('price');
            });
        }

        if (! Schema::hasColumn('mst_bmhp', 'unit')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->string('unit')->default('pcs')->after('name');
            });

            // Isi kembali teksnya dari master satuan.
            foreach (DB::table('units')->get(['id', 'name']) as $unit) {
                DB::table('mst_bmhp')->where('unit_id', $unit->id)->update(['unit' => $unit->name]);
            }
        }

        if (Schema::hasColumn('mst_bmhp', 'unit_id')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->dropConstrainedForeignId('unit_id');
            });
        }

        Schema::table('mst_bmhp', function (Blueprint $table) {
            $table->unsignedInteger('stock_qty')->default(0)->change();
        });

        if (! Schema::hasColumn('mst_bmhp', 'description')) {
            Schema::table('mst_bmhp', function (Blueprint $table) {
                $table->text('description')->nullable()->after('stock_qty');
            });
        }

        if (Schema::hasTable('mst_bmhp') && ! Schema::hasTable('bmhps')) {
            Schema::rename('mst_bmhp', 'bmhps');
        }
    }

    /** Kode SAT-NNN berikutnya — sama dengan Unit::generateUniqueCode(). */
    private function nextUnitCode(): string
    {
        $maxCode = DB::table('units')->where('code', 'like', 'SAT-%')->max('code');

        $sequence = 1;
        if ($maxCode && preg_match('/-(\d+)$/', $maxCode, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return 'SAT-'.str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }
};
