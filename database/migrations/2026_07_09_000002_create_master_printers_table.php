<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Master printer (Pengaturan → Master Printer). Menyimpan konfigurasi printer
     * struk/label: bahasa printer, koneksi, ukuran kertas/label, dll.
     */
    public function up(): void
    {
        Schema::create('master_printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('document_type', ['struk', 'label']);
            $table->enum('printer_language', ['escpos', 'tspl', 'zpl', 'epl'])->default('escpos');
            $table->enum('connection_type', ['network', 'usb', 'bluetooth', 'serial']);
            $table->string('ip_address')->nullable();
            $table->integer('port')->default(9100)->nullable();
            $table->string('device_path')->nullable();

            // receipt (struk) only
            $table->enum('paper_size', ['58mm', '80mm'])->nullable();
            $table->integer('char_per_line')->nullable();
            $table->boolean('auto_cut')->default(true);

            // label only
            $table->integer('label_width_mm')->nullable();
            $table->integer('label_height_mm')->nullable();
            $table->float('label_gap_mm')->nullable();

            $table->string('code_page')->default('CP437');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_printers');
    }
};
