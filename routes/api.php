<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\AuthorityController;
use App\Http\Controllers\Master\BmhpController;
use App\Http\Controllers\Master\ConditionController;
use App\Http\Controllers\Master\InstrumentCatalogController;
use App\Http\Controllers\Master\InstrumentController;
use App\Http\Controllers\Master\InstrumentStockController;
use App\Http\Controllers\Master\MenuController;
use App\Http\Controllers\Master\RoomController;
use App\Http\Controllers\Master\TitleMenuController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\Transaction\DistributionController;
use App\Http\Controllers\Transaction\MonitoringController;
use App\Http\Controllers\Transaction\OrderController;
use App\Http\Controllers\Transaction\OrderTransferController;
use App\Http\Controllers\Transaction\ReportController;
use App\Http\Controllers\Transaction\SterilizationController;
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

        // Sterilisasi CSSD: batch/siklus sterilisasi + unit di dalamnya
        Route::get('sterilizations/expiring', [SterilizationController::class, 'expiring']);
        Route::apiResource('sterilizations', SterilizationController::class);

        // Laporan CSSD per alat (satu baris per unit di tiap batch sterilisasi)
        Route::get('reports/cssd-per-item', [ReportController::class, 'cssdPerItem']);
    });
});
