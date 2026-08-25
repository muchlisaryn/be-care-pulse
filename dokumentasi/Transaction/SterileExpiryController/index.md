# index

**Method:** GET  
**Endpoint:** `/api/master/sterile-expiry`  
**Controller:** `App\Http\Controllers\Transaction\SterileExpiryController@index`  
**Auth:** Bearer Token (wajib)

Daftar **batch steril di gudang** yang sudah atau akan kedaluwarsa dalam ambang hari tertentu —
sumber data halaman **Alat Kedaluwarsa Steril** (`/cssd/kedaluwarsa`).

> **API tersendiri.** Endpoint ini sengaja TIDAK digabung dengan `storage/inventory` (Storage
> Steril) maupun `sterilizations/expiring` (CRUD sterilisasi). Yang dibagi hanya aturan hitungnya
> lewat trait `App\Traits\CountsSterileItems`, supaya angkanya tidak pernah berbeda dengan
> halaman Storage Steril.

## Sumber data

Baris `instrument_storages` yang **masih di rak** dan ber-`order_id` **NULL** — basis yang
sama dengan `StorageController@inventory` (tanpa menyaring status baris gudang maupun status
unit) — lalu dibatasi `expiry_date <= hari ini + days` dan diringkas **per batch sterilisasi**.
Tanggal kedaluwarsa batch = tanggal **paling dekat** di antara barisnya.

"Masih di rak" = scope `InstrumentStorage::stillInRack()`: `deleted_by` NULL, `removed_at`
NULL, `disabled_at` NULL. Dulu penyaringnya hanya `order_id` NULL, sehingga baris yang sudah
DIANGKAT dari rak — ditarik ke produksi lewat `ProductionController::closeStorageForReprocessed`,
atau di-void oleh aksi [Packaging Ulang](repackage.md) — tetap terdaftar sebagai stok
kedaluwarsa padahal barangnya sudah tidak ada di sana.

## Aksi pada halaman ini

| Aksi | Endpoint |
|---|---|
| Lihat isi batch per label kemasan | [`GET .../{sterilization}/units`](units.md) |
| Tarik label kedaluwarsa → ronde pengemasan baru | [`POST .../{sterilization}/repackage`](repackage.md) |

## Aturan jumlah unit

| Jenis baris | Dihitung |
|---|---|
| `paket` (SET) | **1 per set** — satu bungkus / nomor label kemasan = 1, berapa pun instrumen di dalamnya |
| `satuan` | **1 per unit** |

Set **tidak** dihitung per instrumen. Pengelompokan set dibatasi per rak + batch steril + nomor
label; bungkus tanpa nomor label dihitung sebagai set tersendiri.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| days | integer | Tidak | Ambang hari ke depan (default: 7). `0` = hanya yang sudah lewat / habis hari ini |
| search | string | Tidak | Cari kode & mesin batch, nama/kode instrumen, nama paket, kode rak, atau nomor label kemasan |
| page | integer | Tidak | Nomor halaman (default: 1, 20 baris per halaman) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data alat kedaluwarsa steril berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 12,
        "code": "STR-012",
        "machine": "Autoclave Pre-Vacuum 1",
        "method": "uap",
        "sterilized_at": "2026-08-02T11:21:00.000000Z",
        "expiry_date": "2026-08-09",
        "days_to_expiry": -2,
        "expired": true,
        "alert": true,
        "item_count": 5,
        "set_count": 2,
        "unit_count": 3,
        "instrument_count": 13,
        "racks": ["RAK A1", "RAK B2"]
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

| Field | Keterangan |
|---|---|
| `id` | id batch sterilisasi; `0` = baris gudang lama yang tak punya batch (`code` null) |
| `days_to_expiry` | sisa hari; negatif = sudah lewat |
| `expired` | `days_to_expiry < 0` |
| `alert` | masuk ambang `days` (termasuk yang sudah lewat) |
| `item_count` | **jumlah unit tampilan**: set = 1, satuan = 1 |
| `set_count` / `unit_count` | rincian `item_count` (berapa set + berapa instrumen satuan) |
| `instrument_count` | jumlah instrumen fisik (isi set dijabarkan) — keterangan tambahan |
| `racks` | kode rak tempat unit batch ini tersimpan |

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
