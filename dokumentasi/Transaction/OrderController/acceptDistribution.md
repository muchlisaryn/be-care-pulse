# OrderController@acceptDistribution

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/accept-distribution
**Auth:** Bearer Token (wajib)

Terima order masuk & **siapkan distribusi**. Karena order hanya meminta barang yang
sudah steril, order **tidak lewat pipeline Cleaning→Inspection→Sterilization lagi**.
Sistem mengalokasikan unit steril dari gudang secara **FEFO** (first-expired-first-out)
sesuai jumlah & jenis yang diminta, lalu order → status `digudang` (muncul di
Distribution & Tracking). Selanjutnya pakai endpoint `distribute` seperti biasa.

**Reservasi:** baris gudang (`instrument_storages`) unit terpilih dipindahkan
kepemilikannya ke order ini (`order_id`), sehingga (a) keluar dari pool "available
sterile" milik produksi (`room_id` null) dan (b) `distribute` menemukannya untuk
dikeluarkan dari gudang. Status unit tetap `tersedia` sampai didistribusikan.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body
Tidak ada (alokasi otomatis). Order ditentukan dari path `{order}`.

### Prasyarat
- Order berstatus `diajukan`.
- Stok steril cukup untuk tiap baris permintaan (di gudang, `tersimpan`, belum
  kedaluwarsa, milik produksi). Jika kurang → error 500 dengan pesan jelas.

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

#### Error (422)
```json
{ "status": false, "message": "Order ini sudah diproses dan tidak bisa diterima lagi." }
```

#### Error (500) — stok steril kurang
```json
{
  "status": false,
  "message": "Stok steril \"Gunting Epis\" tidak cukup: butuh 2, tersedia 1.",
  "code": 0,
  "file": "...",
  "line": 0
}
```
