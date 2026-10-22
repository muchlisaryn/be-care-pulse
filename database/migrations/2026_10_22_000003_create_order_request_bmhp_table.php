<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Baris permintaan order BMHP dipisah dari `order_request_item` (yang kini khusus
     * order instrumen) ke tabel sendiri `order_request_bmhp`. Baris BMHP lama dipindah,
     * lalu kolom `bmhp_id` & `package_bmhp_id` di `order_request_item` dibuang.
     */
    public function up(): void
    {
        if (! Schema::hasTable('order_request_bmhp')) {
            Schema::create('order_request_bmhp', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('order')->cascadeOnDelete();
                // `satuan` = satu BMHP (bmhp_id), `paket` = paket BMHP (package_bmhp_id).
                $table->string('type', 16);
                $table->foreignId('bmhp_id')->nullable()->constrained('mst_bmhp')->nullOnDelete();
                $table->foreignId('package_bmhp_id')->nullable()->constrained('mst_package_bmhp')->nullOnDelete();
                // Nama saat order dibuat — tetap terbaca walau master diubah/dihapus.
                $table->string('item_name')->nullable();
                $table->string('package_name')->nullable();
                // Satuan: jumlah BMHP; paket: jumlah set.
                $table->unsignedInteger('quantity');
                // Kolom audit standar (lihat HasAuditColumns).
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();
                $table->unsignedBigInteger('deleted_user_id')->nullable()->index();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('order_request_item', 'bmhp_id')) {
            return;
        }

        // Pindahkan baris BMHP lama.
        $rows = DB::table('order_request_item')
            ->whereNotNull('bmhp_id')
            ->orWhereNotNull('package_bmhp_id')
            ->get();

        foreach ($rows as $row) {
            DB::table('order_request_bmhp')->insert([
                'order_id' => $row->order_id,
                'type' => $row->type,
                'bmhp_id' => $row->bmhp_id,
                'package_bmhp_id' => $row->package_bmhp_id,
                'item_name' => $row->item_name,
                'package_name' => $row->package_name,
                'quantity' => $row->quantity,
                'created_by' => $row->created_by,
                'updated_by' => $row->updated_by,
                'deleted_by' => $row->deleted_by,
                'deleted_user_id' => $row->deleted_user_id ?? null,
                'deleted_at' => $row->deleted_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ]);
        }

        DB::table('order_request_item')->whereIn('id', $rows->pluck('id'))->delete();

        Schema::table('order_request_item', function (Blueprint $table) {
            $table->dropForeign(['bmhp_id']);
            $table->dropForeign(['package_bmhp_id']);
            $table->dropColumn(['bmhp_id', 'package_bmhp_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order_request_item', 'bmhp_id')) {
            Schema::table('order_request_item', function (Blueprint $table) {
                $table->foreignId('bmhp_id')->nullable()->after('instrument_id')->constrained('mst_bmhp')->nullOnDelete();
                $table->foreignId('package_bmhp_id')->nullable()->after('instrument_catalog_id')->constrained('mst_package_bmhp')->nullOnDelete();
            });
        }

        if (Schema::hasTable('order_request_bmhp')) {
            foreach (DB::table('order_request_bmhp')->get() as $row) {
                DB::table('order_request_item')->insert([
                    'order_id' => $row->order_id,
                    'type' => $row->type,
                    'bmhp_id' => $row->bmhp_id,
                    'package_bmhp_id' => $row->package_bmhp_id,
                    'item_name' => $row->item_name,
                    'package_name' => $row->package_name,
                    'quantity' => $row->quantity,
                    'created_by' => $row->created_by,
                    'updated_by' => $row->updated_by,
                    'deleted_by' => $row->deleted_by,
                    'deleted_user_id' => $row->deleted_user_id,
                    'deleted_at' => $row->deleted_at,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ]);
            }
        }

        Schema::dropIfExists('order_request_bmhp');
    }
};
