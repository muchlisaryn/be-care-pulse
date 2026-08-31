<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\AuthorityController;
use App\Http\Controllers\ClinicalPathway\AsesmenClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\CategoriClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\PointClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\TemplateClinicalPathwayController;
use App\Http\Controllers\ClinicalPathway\VarianClinicalPathwayController;
use App\Http\Controllers\Dashboard\CssdDashboardController;
use App\Http\Controllers\Dashboard\NurseDashboardController;
use App\Http\Controllers\Master\BmhpController;
use App\Http\Controllers\Master\ConditionController;
use App\Http\Controllers\Master\Icd10Controller;
use App\Http\Controllers\Master\InstrumentCatalogController;
use App\Http\Controllers\Master\InstrumentController;
use App\Http\Controllers\Master\InstrumentStockController;
use App\Http\Controllers\Master\MenuController;
use App\Http\Controllers\Master\PackagingTypeController;
use App\Http\Controllers\Master\PrinterController;
use App\Http\Controllers\Master\RackController;
use App\Http\Controllers\Master\RoomController;
use App\Http\Controllers\Master\SterilizerMachineController;
use App\Http\Controllers\Master\TitleMenuController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\Master\WasherMachineController;
use App\Http\Controllers\Nafsul\AnggotaController;
use App\Http\Controllers\Nafsul\DashboardController as NafsulDashboardController;
use App\Http\Controllers\Nafsul\GabungAnggotaController;
use App\Http\Controllers\Nafsul\KetuaKelompokController;
use App\Http\Controllers\Nafsul\KotaController;
use App\Http\Controllers\Nafsul\PekerjaanController;
use App\Http\Controllers\Nafsul\PendidikanController;
use App\Http\Controllers\Nafsul\StatusAnggotaController;
use App\Http\Controllers\Nafsul\StatusNikahController;
use App\Http\Controllers\Nafsul\TarifController;
use App\Http\Controllers\Nafsul\TransaksiController;
use App\Http\Controllers\Nafsul\TransaksiHeaderController;
use App\Http\Controllers\Nafsul\TransaksiImportController;
use App\Http\Controllers\Nafsul\WilayahController;
use App\Http\Controllers\Transaction\CleaningController;
use App\Http\Controllers\Transaction\DistributionController;
use App\Http\Controllers\Transaction\MonitoringController;
use App\Http\Controllers\Transaction\OrderController;
use App\Http\Controllers\Transaction\OrderTrackingController;
use App\Http\Controllers\Transaction\OrderTransferController;
use App\Http\Controllers\Transaction\PackagingController;
use App\Http\Controllers\Transaction\ProductionController;
use App\Http\Controllers\Transaction\ReportController;
use App\Http\Controllers\Transaction\SterileExpiryController;
use App\Http\Controllers\Transaction\SterileInventoryController;
use App\Http\Controllers\Transaction\SterilizationController;
use App\Http\Controllers\Transaction\SterilizationPipelineController;
use App\Http\Controllers\Transaction\StorageController;
use App\Http\Controllers\Transaction\TrackingCountController;
use App\Http\Controllers\Transaction\TransactionReportController;
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

    // Dashboard per peran. Masing-masing satu endpoint saja — seluruh kartu &
    // grafik satu layar datang dalam SATU respons, supaya angkanya dipotret pada
    // detik yang sama. Kalau tiap kartu memanggil endpointnya sendiri, order yang
    // didistribusikan di sela-sela permintaan membuat "sedang dipinjam" dan
    // "belum dikembalikan" di layar yang sama saling bertentangan.
    Route::get('cssd/dashboard', [CssdDashboardController::class, 'index']);
    Route::get('nurse/dashboard', [NurseDashboardController::class, 'index']);

    Route::prefix('master')->group(function () {
        Route::apiResource('authorities', AuthorityController::class);
        Route::apiResource('title-menus', TitleMenuController::class);
        Route::apiResource('menus', MenuController::class);
        // Import user PER BATCH (klien memecah berkasnya) — harus di atas apiResource
        // agar tidak tertangkap route users/{user}.
        Route::post('users/import', [UserController::class, 'import']);
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
        // Tracking posisi unit di pipeline CSSD (tahap saat ini + kode produksi)
        Route::get('instrument-stocks/{instrument_stock}/tracking', [InstrumentStockController::class, 'tracking']);
        Route::apiResource('instrument-stocks', InstrumentStockController::class);
        // Katalog instrumen CSSD (definisi Set: satuan/paket)
        Route::post('instrument-catalogs/{instrument_catalog}/image', [InstrumentCatalogController::class, 'uploadImage']);
        Route::delete('instrument-catalogs/{instrument_catalog}/image', [InstrumentCatalogController::class, 'deleteImage']);
        Route::apiResource('instrument-catalogs', InstrumentCatalogController::class);

        // BMHP (Bahan Medis Habis Pakai / consumables)
        Route::apiResource('bmhps', BmhpController::class);

        Route::apiResource('rooms', RoomController::class);

        // Master mesin pencuci (washer disinfector) — tahap Cleaning
        // Lookup mesin washer via id (washer_machine_id) — kode/barcode sudah dihapus
        Route::post('washer-machines/scan', [WasherMachineController::class, 'scan']);
        Route::apiResource('washer-machines', WasherMachineController::class)
            ->parameters(['washer-machines' => 'washer_machine']);

        // Master mesin sterilisator (autoclave) — tahap Sterilization
        Route::apiResource('sterilizer-machines', SterilizerMachineController::class)
            ->parameters(['sterilizer-machines' => 'sterilizer_machine']);

        // Master jenis kemasan — tahap Packaging; masa simpannya menentukan tgl kedaluwarsa steril
        Route::get('packaging-types/options', [PackagingTypeController::class, 'options']);
        Route::apiResource('packaging-types', PackagingTypeController::class)
            ->parameters(['packaging-types' => 'packaging_type']);

        // Master rak gudang steril — pilihan lokasi rak saat "Simpan ke Gudang"
        Route::get('racks/options', [RackController::class, 'options']);
        Route::apiResource('racks', RackController::class);

        // Master tipe printer (Pengaturan → Master Printer)
        Route::apiResource('printers', PrinterController::class);

        // Monitoring ruangan: unit instrumen yang sedang dipinjam per ruangan
        Route::get('monitoring/rooms', [MonitoringController::class, 'rooms']);
        // Order masuk dari menu Order Instrumen (diajukan/disetujui, lintas user)
        Route::get('monitoring/incoming', [MonitoringController::class, 'incoming']);
        // Jumlahnya saja — untuk badge notifikasi sidebar (dipanggil sering)
        Route::get('monitoring/incoming-count', [MonitoringController::class, 'incomingCount']);
        // Order yang sudah dikembalikan (riwayat) — tetap dipajang di monitoring
        Route::get('monitoring/returned', [MonitoringController::class, 'returned']);
        // Tab "Distribution & Tracking": dipinjam + riwayat dalam satu daftar,
        // dipaginasi di server (30 baris/halaman)
        Route::get('monitoring/tracking', [MonitoringController::class, 'tracking']);
        // Jumlah instrumen yang sedang dipinjam (paket per set, satuan per unit)
        Route::get('monitoring/borrowed-summary', [MonitoringController::class, 'borrowedSummary']);
        // Angka badge tab Tracking Order — count() murni, tanpa memuat daftarnya
        Route::get('monitoring/counts', [MonitoringController::class, 'counts']);
        // Badge tab "Distribution & Tracking" — endpoint TERPISAH dari
        // monitoring/counts di atas: aturan hitungnya dari jejak waktu
        // (processed_at / distributed_at / is_returned), bukan kolom status
        Route::get('tracking-order/counts', [TrackingCountController::class, 'counts']);
        // Kartu "Distribusi per Ruangan": angka per ruangan, tanpa daftar instrumennya
        Route::get('monitoring/rooms-summary', [MonitoringController::class, 'roomsSummary']);
        // Papan monitor (display TV): daftar order aktif untuk dipajang
        Route::get('monitoring/board', [MonitoringController::class, 'board']);

        // Peminjaman instrumen (F5 PRD): order header + item unit
        // Scan kode order (ORD-NNN) untuk tracking seluruh unit dalam satu order
        Route::post('orders/scan', [OrderController::class, 'scan']);
        // Tracking order LENGKAP (termasuk pipeline CSSD tiap unit) — hanya ditarik
        // saat tombol "Tampilkan semua tracking" ditekan.
        Route::get('orders/{order}/timeline', [OrderController::class, 'timeline']);
        // Aktivitas TERAKHIR saja — endpoint TERPISAH & ringan, dipakai bagian
        // Tracking pada modal Pengembalian Instrumen saat pertama dibuka
        Route::get('order-tracking/{order}/latest', [OrderTrackingController::class, 'latest']);
        // Daftar order milik pihak lain yang sedang dipinjam (untuk Pinjam Instrumen)
        Route::get('orders/borrowable', [OrderController::class, 'borrowable']);
        // Terima order: data alokasi unit + proses penerimaan (alokasi + kurangi stok)
        Route::get('orders/{order}/allocation', [OrderController::class, 'allocation']);
        Route::post('orders/{order}/receive', [OrderController::class, 'receive']);
        // Produksi CSSD: mulai batch internal (stok milik CSSD) → langsung tahap Cleaning
        Route::post('production', [ProductionController::class, 'store']);
        // Rincian batch produksi (lazy-load tombol Detail di timeline) — by nomor produksi.
        Route::get('production/detail', [ProductionController::class, 'detail']);
        // Pipeline pemrosesan CSSD: Proses order masuk → tahap Cleaning & Pengemasan
        Route::post('orders/{order}/process', [CleaningController::class, 'process']);
        Route::get('cleaning', [CleaningController::class, 'index']);
        // Rincian batch cleaning (lazy-load tombol Detail di timeline) — by nomor cleaning.
        Route::get('cleaning/detail', [CleaningController::class, 'detail']);
        // Notifikasi kegagalan suhu/waktu pencucian (parameter di luar ambang mesin)
        Route::get('cleaning/alerts', [CleaningController::class, 'alerts']);
        Route::put('cleaning/{washing}/washing', [CleaningController::class, 'updateWashing']);
        // Batalkan batch cleaning yang belum diproses → stok dikembalikan ke semula
        Route::delete('cleaning/{washing}/cancel', [CleaningController::class, 'cancelWashing']);
        // Pipeline produksi — Tahap Inspection & Packaging (record PKG): list & selesai
        Route::get('packaging', [PackagingController::class, 'index']);
        // "Selesai & Cetak Label" untuk batch antrean: record packaging +
        // packaging_item baru dibuat di sini (bukan saat cleaning selesai).
        Route::post('packaging/complete', [PackagingController::class, 'completeQueued']);
        Route::get('packaging/{packaging}/label', [PackagingController::class, 'label']);
        // Rincian barcode beberapa batch packaging (lazy-load tombol Detail di timeline).
        Route::get('packaging/barcode-detail', [PackagingController::class, 'barcodeDetail']);
        Route::post('packaging/{packaging}/complete', [PackagingController::class, 'complete']);
        // Pipeline produksi — Tahap Sterilisasi (berbasis PKG): list siap-steril + batch, buat batch gabungan, validasi
        Route::get('sterilization-pipeline', [SterilizationPipelineController::class, 'index']);
        // Rincian batch sterilisasi (lazy-load tombol Detail di timeline) — by nomor STR.
        Route::get('sterilization-pipeline/detail', [SterilizationPipelineController::class, 'detail']);
        Route::post('sterilization-pipeline/batch', [SterilizationPipelineController::class, 'batch']);
        // Validasi hasil scan barcode label kemasan (dikenal / layak dibatch?).
        Route::post('sterilization-pipeline/scan', [SterilizationPipelineController::class, 'scan']);
        Route::post('sterilization-pipeline/{sterilization}/validate', [SterilizationPipelineController::class, 'validateResult']);
        // Tahap Packaging (order peminjaman): data kebutuhan unit, generate unit dari stok, lalu lanjut (selesai/siap steril)
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
        // Kandidat unit steril per baris permintaan (untuk memilih stok/kode produksi)
        Route::get('orders/{order}/distribution-options', [OrderController::class, 'distributionOptions']);
        Route::post('orders/{order}/distribute', [OrderController::class, 'distribute']);
        // Order untuk beberapa pasien sekaligus — satu record order per pasien,
        // dibuat dalam satu transaksi (harus di atas apiResource agar tidak
        // tertangkap sebagai parameter {order})
        Route::post('orders/bulk', [OrderController::class, 'bulkStore']);
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
        // Batch steril pipeline PRODUKSI yang siap disimpan (tanpa order)
        Route::get('storage/production-incoming', [StorageController::class, 'productionIncoming']);
        Route::get('storage/inventory', [StorageController::class, 'inventory']);
        // Tab "Inventaris" halaman Gudang Steril — endpoint TERSENDIRI: baris
        // kedaluwarsa tetap ditampilkan di sini (ditandai can_distribute=false),
        // aturan yang tidak berlaku di tempat lain
        Route::get('sterile-inventory', [SterileInventoryController::class, 'index']);
        Route::get('sterile-inventory/summary', [SterileInventoryController::class, 'summary']);
        // Angka ringkasan gudang (total / mendekati kedaluwarsa / kedaluwarsa)
        Route::get('storage/summary', [StorageController::class, 'summary']);
        Route::post('orders/{order}/store', [StorageController::class, 'store']);
        Route::post('sterilization/{sterilization}/store', [StorageController::class, 'storeProduction']);

        // Alat Kedaluwarsa Steril — API TERSENDIRI (bukan milik Storage Steril maupun
        // CRUD sterilisasi) untuk halaman /cssd/kedaluwarsa
        Route::get('sterile-expiry/summary', [SterileExpiryController::class, 'summary']);
        Route::get('sterile-expiry', [SterileExpiryController::class, 'index']);
        // Rincian isi satu batch, dipecah per LABEL kemasan (dasar pilihan petugas).
        // Parameternya id batch steril mentah — bukan route model binding: daftar
        // memakai id 0 untuk baris gudang lama yang tak punya batch.
        Route::get('sterile-expiry/{sterilization}/units', [SterileExpiryController::class, 'units'])
            ->whereNumber('sterilization');
        // Packaging Ulang: tarik label kedaluwarsa dari rak → ronde RPK baru.
        Route::post('sterile-expiry/{sterilization}/repackage', [SterileExpiryController::class, 'repackage'])
            ->whereNumber('sterilization');

        // Sterilisasi CSSD: batch/siklus sterilisasi + unit di dalamnya
        Route::get('sterilizations/expiring', [SterilizationController::class, 'expiring']);
        Route::apiResource('sterilizations', SterilizationController::class);

        // Laporan CSSD per alat (satu baris per label kemasan di tiap batch sterilisasi)
        Route::get('reports/cssd-per-item', [ReportController::class, 'cssdPerItem']);
        // Pilihan filter mesin laporan di atas — diambil dari batch yang ada, bukan master
        Route::get('reports/cssd-machines', [ReportController::class, 'cssdMachines']);
        // Pilihan filter indikator kimia (nomor lot) — juga diambil dari batch yang ada
        Route::get('reports/cssd-chemical-indicators', [ReportController::class, 'cssdChemicalIndicators']);
        // Laporan transaksi instrumen: tgl transaksi, no invoice, nama instrumen/set
        // (production_item) & nomor barcode label, peminjam, ruangan
        Route::get('reports/transaksi-instrumen', [TransactionReportController::class, 'index']);
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

    // Nafsul Muthmainah — master keanggotaan.
    //
    // Di-prefix `nafsul` agar tidak bentrok dengan route CSSD; login memakai
    // token Sanctum yang sama (satu sesi untuk seluruh aplikasi), sehingga
    // AuthController & UserController milik modul Nafsul lama tidak diikutkan.
    //
    // Sebatas master: transaksi, pelayanan jenazah, dashboard, dan laporan
    // belum ada di repo ini (tabelnya pun tidak dibuat, lihat migrasi
    // create_nafsul_tables) — daftarkan lagi saat modulnya menyusul.
    //
    // Seluruh `->parameters()` di bawah WAJIB ditulis eksplisit: Laravel
    // mensingularkan nama resource Inggris (mis. `kota` → `kotum`), sedangkan
    // controller mem-binding `Kota $kota`.
    Route::prefix('nafsul')->group(function () {
        // Ringkasan pendapatan iuran — satu respons untuk seluruh layar dashboard,
        // alasannya sama dengan dashboard CSSD & perawat di atas.
        Route::get('dashboard', [NafsulDashboardController::class, 'index']);

        // `anggota/import` didaftarkan sebelum apiResource agar tidak tertangkap
        // sebagai `anggota/{anggota}`.
        Route::post('anggota/import', [AnggotaController::class, 'import']);
        Route::get('anggota/statistik', [AnggotaController::class, 'statistik']);
        // Riwayat iuran satu anggota, untuk modal di master anggota. Di atas
        // apiResource agar tidak tertangkap sebagai `anggota/{anggota}`.
        Route::get('anggota/{member}/riwayat-transaksi', [AnggotaController::class, 'riwayatTransaksi']);
        // Ringkasan satu angka untuk form transaksi; lihat pembayaranTerakhir().
        Route::get('anggota/{member}/pembayaran-terakhir', [AnggotaController::class, 'pembayaranTerakhir']);
        Route::apiResource('anggota', AnggotaController::class)
            ->parameters(['anggota' => 'member']);

        // Gabung Anggota — pindahkan transaksi seorang anggota ke anggota lain.
        //
        // `gabung-anggota/anggota/{member}/transaksi` didaftarkan SEBELUM
        // apiResource-nya, kalau tidak `anggota` tertangkap sebagai
        // `gabung-anggota/{gabung}`.
        Route::get('gabung-anggota/anggota/{member}/transaksi', [GabungAnggotaController::class, 'transaksi']);
        Route::get('gabung-anggota', [GabungAnggotaController::class, 'index']);
        Route::post('gabung-anggota', [GabungAnggotaController::class, 'store']);
        Route::get('gabung-anggota/{gabung}', [GabungAnggotaController::class, 'show']);

        // Master Nafsul
        Route::post('ketua-kelompok/import', [KetuaKelompokController::class, 'import']);
        Route::apiResource('ketua-kelompok', KetuaKelompokController::class)
            ->parameters(['ketua-kelompok' => 'groupLeader']);
        // `*/import` selalu didaftarkan sebelum apiResource-nya, kalau tidak
        // tertangkap sebagai parameter show (mis. `wilayah/{region}`).
        Route::post('wilayah/import', [WilayahController::class, 'import']);
        Route::apiResource('wilayah', WilayahController::class)
            ->parameters(['wilayah' => 'region']);
        // `kota/import` didaftarkan sebelum apiResource agar tidak tertangkap
        // sebagai `kota/{city}`.
        Route::post('kota/import', [KotaController::class, 'import']);
        Route::apiResource('kota', KotaController::class)
            ->parameters(['kota' => 'city']);
        Route::apiResource('tarif', TarifController::class)
            ->parameters(['tarif' => 'rate']);
        Route::apiResource('status-anggota', StatusAnggotaController::class)
            ->parameters(['status-anggota' => 'memberStatus']);
        Route::post('pendidikan/import', [PendidikanController::class, 'import']);
        Route::apiResource('pendidikan', PendidikanController::class)
            ->parameters(['pendidikan' => 'education']);
        Route::post('pekerjaan/import', [PekerjaanController::class, 'import']);
        Route::apiResource('pekerjaan', PekerjaanController::class)
            ->parameters(['pekerjaan' => 'occupation']);
        Route::apiResource('status-nikah', StatusNikahController::class)
            ->parameters(['status-nikah' => 'maritalStatus']);

        // Transaksi iuran anggota. `->parameters()` ditulis eksplisit: tanpa
        // itu Laravel mensingularkan `transaksi` jadi `transaksus`, sedangkan
        // controller mem-binding `Transaction $transaksi`.
        // Header didaftarkan lebih dulu: `transaksi/header` harus dikenali
        // sebagai rute tersendiri, bukan tertangkap `transaksi/{transaksi}`.
        // Reset kuitansi: rincian dilepas jadi tagihan lagi, kuitansinya
        // dihapus. Didaftarkan sebelum apiResource-nya agar tidak tertangkap
        // sebagai `transaksi/header/{transaksiHeader}`.
        Route::post('transaksi/header/{transaksiHeader}/reset', [TransaksiHeaderController::class, 'reset']);
        // Validasi kuitansi: mengisi `validation_at` + `validation_by`. Sama
        // seperti reset, harus di atas apiResource-nya.
        Route::post('transaksi/header/{transaksiHeader}/validasi', [TransaksiHeaderController::class, 'validasi']);
        // Buka kunci: `validation_at` & `validation_by` dikosongkan lagi.
        Route::post('transaksi/header/{transaksiHeader}/batal-validasi', [TransaksiHeaderController::class, 'batalValidasi']);
        // Cetak biling (PDF). Sama seperti dua rute di atas, harus mendahului
        // apiResource-nya. Hanya kuitansi yang sudah divalidasi yang dilayani.
        Route::get('transaksi/header/{transaksiHeader}/biling', [TransaksiHeaderController::class, 'biling']);

        Route::apiResource('transaksi/header', TransaksiHeaderController::class)
            ->parameters(['header' => 'transaksiHeader'])
            ->names('transaksi-header');

        // Sama seperti `transaksi/header`: harus sebelum apiResource, kalau
        // tidak tertangkap sebagai `transaksi/{transaksi}`.
        Route::get('transaksi/rencana', [TransaksiController::class, 'rencana']);

        // Impor Excel: satu baris file = satu rincian, digabung jadi kuitansi
        // lewat kolom `kode_kuitansi`. Juga harus di atas apiResource.
        Route::post('transaksi/import', [TransaksiImportController::class, 'import']);

        Route::apiResource('transaksi', TransaksiController::class)
            ->parameters(['transaksi' => 'transaksi']);
    });
});
