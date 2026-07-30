# OrderController@acceptDistribution

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/accept-distribution
**Auth:** Bearer Token (wajib)

Terima order masuk. Karena order hanya meminta barang yang sudah steril, order **tidak
lewat pipeline Cleaning→Inspection→Sterilization lagi**: statusnya langsung → `digudang`
(muncul di Distribution & Tracking). Selanjutnya pakai endpoint `distribute`.

**Menerima order HANYA menerima.** Endpoint ini **tidak** mengalokasikan unit, **tidak**
membuat `order_item`, dan **tidak** mereservasi baris gudang — `instrument_storages.order_id`
tetap `null`. Pemilihan unit terjadi di modal Distribusikan (lihat
[distributionOptions](distributionOptions.md)), dan klaimnya terjadi di
[distribute](distribute.md).

Yang tetap dilakukan: kecukupan stok pool diperiksa lebih dulu sebagai **peringatan dini**
— bila kurang, order ditolak 422 dan statusnya tidak berubah. Karena tidak mereservasi,
pemeriksaan ini **tidak mengikat**: stok yang sama masih bisa diambil order lain yang
didistribusikan lebih dulu.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body
Tidak ada (alokasi otomatis). Order ditentukan dari path `{order}`.

### Prasyarat
- Order berstatus `diajukan`.
- Stok pool cukup untuk tiap baris permintaan (`order_id IS NULL`, `expiry_date >= hari ini`,
  unit `tersedia`, bentuk simpan cocok). Jika kurang → error 422.

### Transaksi & konkurensi
Berjalan dalam satu transaksi (`DB::transaction`) — bila ada langkah yang gagal,
semuanya di-rollback dan order tetap `diajukan`.

Baris order dikunci (`SELECT ... FOR UPDATE`) lalu statusnya diperiksa ulang di dalam
transaksi, sehingga dua permintaan "Terima order" yang datang bersamaan dijalankan
berurutan — yang kedua menemukan status sudah bukan `diajukan` dan ditolak 422.

Pemeriksaan stok di sini **tidak** mengunci baris gudang, karena tidak mereservasi.
Perebutan stok antar order diselesaikan saat distribusi (lihat [distribute](distribute.md)).

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Order diterima & siap didistribusikan.",
  "data": {
    "id": 21,
    "code": "ORD-021",
    "code_transaction": "INV20260630006",
    "status": "digudang",
    "items": [ { "instrument_stock_id": 87, "source": "satuan" } ]
  }
}
```

#### Error (422) — order sudah diproses
```json
{ "status": false, "message": "Order ini sudah diproses dan tidak bisa diterima lagi." }
```

#### Error (422) — stok steril kurang
```json
{ "status": false, "message": "Stok steril \"Gunting Epis\" tidak cukup: butuh 2, tersedia 1." }
```
