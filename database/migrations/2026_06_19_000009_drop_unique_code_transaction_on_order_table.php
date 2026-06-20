<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sejak fitur pinjam-alih (handover), beberapa order dalam satu rantai sengaja
     * BERBAGI `code_transaction` (invoice) yang sama agar histori antar ruangan
     * terkumpul jadi satu. Maka constraint UNIQUE harus dilepas, diganti index biasa.
     */
    public function up(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropUnique(['code_transaction']);
            $table->index('code_transaction');
        });
    }

    public function down(): void
    {
        Schema::table('order', function (Blueprint $table) {
            $table->dropIndex(['code_transaction']);
            $table->unique('code_transaction');
        });
    }
};
