<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Master printer (Pengaturan → Master Printer). Konfigurasi printer struk/label:
 * bahasa printer, koneksi, ukuran kertas/label, dll.
 */
class Printer extends Model
{
    protected $table = 'master_printers';

    protected $fillable = [
        'name',
        'document_type',
        'printer_language',
        'connection_type',
        'ip_address',
        'port',
        'device_path',
        'paper_size',
        'char_per_line',
        'auto_cut',
        'label_width_mm',
        'label_height_mm',
        'label_gap_mm',
        'code_page',
        'is_active',
    ];

    protected $casts = [
        'port' => 'integer',
        'char_per_line' => 'integer',
        'auto_cut' => 'boolean',
        'label_width_mm' => 'integer',
        'label_height_mm' => 'integer',
        'label_gap_mm' => 'float',
        'is_active' => 'boolean',
    ];
}
