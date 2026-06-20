<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
        });

        // Backfill kode untuk data ruangan yang sudah ada (4 huruf acak unik)
        $existing = DB::table('rooms')->pluck('code', 'id')->filter()->values()->all();
        $used = array_flip($existing);

        foreach (DB::table('rooms')->whereNull('code')->pluck('id') as $id) {
            do {
                $code = '';
                for ($i = 0; $i < 4; $i++) {
                    $code .= chr(random_int(65, 90));
                }
            } while (isset($used[$code]));

            $used[$code] = true;
            DB::table('rooms')->where('id', $id)->update(['code' => $code]);
        }
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
