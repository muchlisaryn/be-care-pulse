<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\AuthorityController;
use App\Http\Controllers\ClinicalPathway\AsesmenClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\CategoriClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\PointClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\TemplateClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\VarianClinicalPathwayController;
use App\Http\Controllers\Master\BmhpController;
use App\Http\Controllers\Master\ConditionController;
use App\Http\Controllers\Master\Icd10Controller;
use App\Http\Controllers\Master\InstrumentCatalogController;
use App\Http\Controllers\Master\InstrumentController;
use App\Http\Controllers\Master\InstrumentStockController;
use App\Http\Controllers\Master\MenuController;
use App\Http\Controllers\Master\RoomController;
use App\Http\Controllers\Master\TitleMenuController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\Master\WasherMachineController;
use App\Http\Controllers\Transaction\CleaningController;
use App\Http\Controllers\Transaction\DistributionController;
use App\Http\Controllers\Transaction\MonitoringController;
use App\Http\Controllers\Transaction\OrderController;
use App\Http\Controllers\Transaction\OrderTransferController;
use App\Http\Controllers\Transaction\ProductionController;
use App\Http\Controllers\Transaction\ReportController;
use App\Http\Controllers\Transaction\SterilizationController;
use App\Http\Controllers\Transaction\StorageController;
use Illuminate\Support\Facades\Route;

// Publik
Route::prefix('auth')->controller(AuthController::class)->group(function () {
    Route::post('login', 'login');
});

// Butuh token
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('register', 'register');
        Route::post('logout', 'logout');
        Route::get('me', 'me');
        Route::put('update', 'update');
        Route::put('profile', 'updateProfile');
        Route::put('change-password', 'changePassword');
        Route::get('sessions', 'sessions');
        Route::delete('sessions/{id}', 'revokeSession');
        Route::delete('sessions', 'revokeAllSessions');
    });

    Route::prefix('master')->group(function () {
        Route::apiResource('authorities', AuthorityController::class);
        Route::apiResource('title-menus', TitleMenuController::class);
        Route::apiResource('menus', MenuController::class);
        Route::apiResource('users', UserController::class);
        Route::apiResource('conditions', ConditionController::class);

        // ICD 10 (master data medis) + impor massal dari Excel (skip duplikat code+version)
        Route::post('icd10/import', [Icd10Controller::class, 'import']);
        Route::apiResource('icd10', Icd10Controller::class)->parameters(['icd10' => 'icd10']);
        Route::get('instruments/stats', [InstrumentController::class, 'stats']);
        // Gambar instrumen (opsional): unggah/ganti & hapus
        Route::post('instruments/{instrument}/image', [InstrumentController::class, 'uploadImage']);
        Route::delete('instruments/{instrument}/image', [InstrumentController::class, 'deleteImage']);
        Route::apiResource('instruments', InstrumentController::class);

        // QR Code instrumen (F3 PRD): scan untuk lookup & generate label QR
        Route::post('instrument-stocks/scan', [InstrumentStockController::class, 'scan']);
        Route::get('instrument-stocks/{instrument_stock}/qr', [InstrumentStockController::class, 'qr']);
        // Riwayat pergerakan/perubahan status unit
        Route::get('instrument-stocks/{instrument_stock}/logs', [InstrumentStockController::class, 'logs']);
        Route::apiResource('instrument-stocks', InstrumentStockController::class);
        // Katalog instrumen CSSD (definisi Set: satuan/paket)
        Route::post('instrument-catalogs/{instrument_catalog}/image', [InstrumentCatalogController::class, 'uploadImage']);
        Route::delete('instrument-catalogs/{instrument_catalog}/image', [InstrumentCatalogController::class, 'deleteImage']);
        Route::apiResource('instrument-catalogs', InstrumentCatalogController::class);

        // BMHP (Bahan Medis Habis Pakai / consumables)
        Route::apiResource('bmhps', BmhpController::class);

        Route::apiResource('rooms', RoomController::class);

        // Master mesin pencuci (washer disinfector) — tahap Cleaning
        // Scan barcode mesin (WSH-NNN) sebelum alat masuk mesin pencuci
        Route::post('washer-machines/scan', [WasherMachineController::class, 'scan']);
        Route::apiResource('washer-machines', WasherMachineController::class)
            ->parameters(['washer-machines' => 'washer_machine']);

        // Monitoring ruangan: unit instrumen yang sedang dipinjam per ruangan
        Route::get('monitoring/rooms', [MonitoringController::class, 'rooms']);
        // Order masuk dari menu Order Instrumen (diajukan/disetujui, lintas user)
        Route::get('monitoring/incoming', [MonitoringController::class, 'incoming']);
        // Order yang sudah dikembalikan (riwayat) — tetap dipajang di monitoring
        Route::get('monitoring/returned', [MonitoringController::class, 'returned']);
        // Papan monitor (display TV): daftar order aktif untuk dipajang
        Route::get('monitoring/board', [MonitoringController::class, 'board']);

        // Peminjaman instrumen (F5 PRD): order header + item unit
        // Scan kode order (ORD-NNN) untuk tracking seluruh unit dalam satu order
        Route::post('orders/scan', [OrderController::class, 'scan']);
        // Daftar order milik pihak lain yang sedang dipinjam (untuk Pinjam Instrumen)
        Route::get('orders/borrowable', [OrderController::class, 'borrowable']);
        // Terima order: data alokasi unit + proses penerimaan (alokasi + kurangi stok)
        Route::get('orders/{order}/allocation', [OrderController::class, 'allocation']);
        Route::post('orders/{order}/receive', [OrderController::class, 'receive']);
        // Produksi CSSD: mulai batch internal (stok milik CSSD) → langsung tahap Cleaning
        Route::post('production', [ProductionController::class, 'store']);
        // Pipeline pemrosesan CSSD: Proses order masuk → tahap Cleaning & Pengemasan
        Route::post('orders/{order}/process', [CleaningController::class, 'process']);
        Route::get('cleaning', [CleaningController::class, 'index']);
        // Notifikasi kegagalan suhu/waktu pencucian (parameter di luar ambang mesin)
        Route::get('cleaning/alerts', [CleaningController::class, 'alerts']);
        Route::put('cleaning/{order}/washing', [CleaningController::class, 'updateWashing']);
        // Tahap Packaging: data kebutuhan unit, generate unit dari stok, lalu lanjut (selesai/siap steril)
        Route::get('orders/{order}/packaging', [OrderController::class, 'packaging']);
        Route::post('orders/{order}/pack', [OrderController::class, 'pack']);
        // Inspection checklist: scan barcode unit / centang manual komponen set
        Route::post('orders/{order}/pack/scan', [OrderController::class, 'packScan']);
        Route::post('orders/{order}/pack/check', [OrderController::class, 'packCheck']);
        // Batalkan centang satu unit (edit alokasi sebelum diselesaikan)
        Route::post('orders/{order}/pack/uncheck', [OrderController::class, 'packUncheck']);
        Route::post('orders/{order}/packaging-complete', [OrderController::class, 'packagingComplete']);
        // Tahap Sterilisasi: daftar order siap-steril (selesai) & buat batch dari order
        Route::get('orders/ready-to-sterilize', [OrderController::class, 'readyToSterilize']);
        Route::post('orders/{order}/sterilize', [OrderController::class, 'sterilize']);
        // Validasi hasil sterilisasi (Steril / Gagal) langsung dari tab
        Route::post('orders/{order}/sterilize/validate', [OrderController::class, 'validateSterilization']);
        // Order masuk: terima & alokasikan unit steril (FEFO) → langsung siap distribusi
        Route::post('orders/{order}/accept-distribution', [OrderController::class, 'acceptDistribution']);
        // Tahap 6 — Distribusi: order siap-distribusi (digudang) & distribusikan + RM pasien
        Route::get('orders/ready-to-distribute', [OrderController::class, 'readyToDistribute']);
        Route::post('orders/{order}/distribute', [OrderController::class, 'distribute']);
        Route::apiResource('orders', OrderController::class);

        // Pinjam-alih (handover) instrumen antar peminjam tanpa order ulang ke CSSD
        Route::get('order-transfers/incoming-count', [OrderTransferController::class, 'incomingCount']);
        Route::get('order-transfers', [OrderTransferController::class, 'index']);
        Route::post('order-transfers', [OrderTransferController::class, 'store']);
        Route::post('order-transfers/{order_transfer}/accept', [OrderTransferController::class, 'accept']);
        Route::post('order-transfers/{order_transfer}/reject', [OrderTransferController::class, 'reject']);
        Route::post('order-transfers/{order_transfer}/cancel', [OrderTransferController::class, 'cancel']);

        // Distribusi alat bersih: serah-terima alat steril CSSD → unit/ruangan
        Route::apiResource('distributions', DistributionController::class);

        // Tahap 5 — Penyimpanan (Storage Steril): simpan unit steril ke rak + inventaris
        Route::get('storage/incoming', [StorageController::class, 'incoming']);
        Route::get('storage/inventory', [StorageController::class, 'inventory']);
        Route::post('orders/{order}/store', [StorageController::class, 'store']);

        // Sterilisasi CSSD: batch/siklus sterilisasi + unit di dalamnya
        Route::get('sterilizations/expiring', [SterilizationController::class, 'expiring']);
        Route::apiResource('sterilizations', SterilizationController::class);

        // Laporan CSSD per alat (satu baris per unit di tiap batch sterilisasi)
        Route::get('reports/cssd-per-item', [ReportController::class, 'cssdPerItem']);
    });

    // Clinical Pathway
    Route::prefix('clinical-pathway')->group(function () {
        // Kategori (template) — urutan unik + label
        Route::apiResource('categories', CategoriClinicalPathwayController::class)
            ->parameters(['categories' => 'categori']);

        // Template Clinical Pathway — diagnosa (ICD 10) + maksimal hari + keterangan
        // + status. Tidak bisa dihapus, hanya aktif / non-aktif (toggle).
        Route::patch('templates/{template}/toggle', [TemplateClinicalPathwayController::class, 'toggleStatus']);
        Route::apiResource('templates', TemplateClinicalPathwayController::class)
            ->except(['destroy'])
            ->parameters(['templates' => 'template']);

        // Formulir: poin (& sub-poin) per template. Penomoran mengikuti kategori
        // (mis. kategori 1 → poin 1.1 → sub-poin 1.1.1).
        Route::get('templates/{template}/points', [PointClinicalPathwayController::class, 'index']);
        Route::post('templates/{template}/points', [PointClinicalPathwayController::class, 'store']);
        // Salin seluruh poin dari formulir lain ke formulir ini.
        Route::post('templates/{template}/copy-points', [PointClinicalPathwayController::class, 'copyFrom']);
        Route::put('points/{point}', [PointClinicalPathwayController::class, 'update']);
        Route::delete('points/{point}', [PointClinicalPathwayController::class, 'destroy']);

        // Asesmen — pengisian clinical pathway per pasien (data pasien + ceklis poin).
        // Auto-save ceklis/keterangan per poin lewat endpoint savePoint.
        Route::put('asesmen/{asesmen}/points/{point}', [AsesmenClinicalPathwayController::class, 'savePoint']);
        // Verifikasi CP per peran (dokter / perawat / pelaksana) + batal verifikasi.
        Route::post('asesmen/{asesmen}/verify', [AsesmenClinicalPathwayController::class, 'verify']);
        // Cetak asesmen ke PDF (preview & download di frontend).
        Route::get('asesmen/{asesmen}/pdf', [AsesmenClinicalPathwayController::class, 'pdf']);

        // Pencatatan varian (penyimpangan) per asesmen. Paraf diisi otomatis
        // dari username user yang login.
        Route::get('asesmen/{asesmen}/varian', [VarianClinicalPathwayController::class, 'index']);
        Route::post('asesmen/{asesmen}/varian', [VarianClinicalPathwayController::class, 'store']);
        Route::put('varian/{varian}', [VarianClinicalPathwayController::class, 'update']);
        Route::delete('varian/{varian}', [VarianClinicalPathwayController::class, 'destroy']);

        Route::apiResource('asesmen', AsesmenClinicalPathwayController::class)
            ->parameters(['asesmen' => 'asesmen']);
    });
});
