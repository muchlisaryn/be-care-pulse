<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `code` packaging wajib unik LINTAS PREFIX: satu nomor hanya boleh dipakai satu
     * batch, entah PKG maupun RPK. Nomor urut harian karena itu berjalan terus —
     * pengemasan ulang mendapat nomor berikutnya (PKG26071901 → RPK26071902), bukan
     * mengulang deret sendiri.
     *
     * Baris lama yang terlanjur kembar (mis. PKG26071901 & RPK26071901) dinomori
     * ulang lebih dulu: yang paling awal (id terkecil) dipertahankan, sisanya
     * mendapat nomor bebas berikutnya pada tanggal yang sama.
     */
    public function up(): void
    {
        $this->renumberDuplicates();

        Schema::table('packaging', function (Blueprint $table) {
            $table->dropUnique(['prefix', 'code']);
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('packaging', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['prefix', 'code']);
        });
    }

    /** Beri nomor baru pada baris ber-`code` kembar (yang lebih baru yang digeser). */
    private function renumberDuplicates(): void
    {
        $rows = DB::table('packaging')->select('id', 'code')->orderBy('id')->get();

        // Nomor yang sudah terpakai per tanggal (6 digit pertama kode).
        $usedByDay = [];
        foreach ($rows as $row) {
            [$day, $sequence] = $this->split((string) $row->code);
            if ($day !== null) {
                $usedByDay[$day][$sequence] = true;
            }
        }

        $seen = [];
        foreach ($rows as $row) {
            $code = (string) $row->code;

            // Baris pertama pemegang kode ini boleh mempertahankannya.
            if (! isset($seen[$code])) {
                $seen[$code] = true;

                continue;
            }

            [$day] = $this->split($code);
            // Kode lama non-ymd (mis. '001') dilewati — tidak bisa dinomori ulang
            // dengan aman, dan praktis tidak pernah kembar.
            if ($day === null) {
                continue;
            }

            $next = 1;
            while (isset($usedByDay[$day][$next])) {
                $next++;
            }
            $usedByDay[$day][$next] = true;

            $newCode = $day.str_pad((string) $next, 2, '0', STR_PAD_LEFT);
            $seen[$newCode] = true;

            DB::table('packaging')->where('id', $row->id)->update(['code' => $newCode]);
        }
    }

    /**
     * Pecah kode jadi [tanggal ymd, nomor urut]. Mengembalikan [null, 0] bila kode
     * tidak mengikuti format ymd + urutan (kode format lama).
     *
     * @return array{0: ?string, 1: int}
     */
    private function split(string $code): array
    {
        if (! preg_match('/^(\d{6})(\d+)$/', $code, $m)) {
            return [null, 0];
        }

        return [$m[1], (int) $m[2]];
    }
};
