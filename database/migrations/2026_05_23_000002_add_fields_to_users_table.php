<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('no_telephone')->nullable()->after('username');
            $table->foreignId('authority_id')->nullable()->after('no_telephone')->constrained('authorities')->nullOnDelete();
            $table->string('created_by')->nullable()->after('deleted_by');
            $table->string('updated_by')->nullable()->after('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['authority_id']);
            $table->dropColumn(['no_telephone', 'authority_id', 'created_by', 'updated_by']);
        });
    }
};
