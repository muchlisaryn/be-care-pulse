# SterileExpiryController — units

**Controller:** App\Http\Controllers\Transaction\SterileExpiryController
**Base URL:** /api/master/sterile-expiry

---

## 3. units (Rincian isi batch per label)

**Method:** GET
**Endpoint:** /api/master/sterile-expiry/{sterilization}/units
**Auth:** Bearer Token (wajib)

Rincian isi satu batch steril di gudang, **dipecah per LABEL KEMASAN** — dasar
pilihan pada aksi `repackage`.

Satu baris hasil = satu **bungkus fisik**. Sterilitas melekat pada bungkus, bukan
pada instrumen (lihat `InstrumentStorage::blockedPackagingBarcodes`), jadi petugas
memilih per label. Instrumen satuan menjadi barisnya sendiri — aturan pengelompokan
yang sama persis dengan `countAsItems()`, sehingga jumlah baris di sini cocok dengan
`item_count` pada daftar batch.

`{sterilization}` adalah **id batch steril mentah**, bukan route model binding:
daftar memakai `id = 0` untuk baris gudang lama yang tidak punya batch, dan nilai itu
harus tetap bisa dibuka (walau `repackage` menolaknya).

Basisnya sama dengan `index`: baris gudang yang **masih di rak** (`stillInRack()` —
belum dihapus, belum diangkat, belum di-void), `order_id` NULL, dan kedaluwarsa dalam
ambang `days`.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| days | integer | Tidak | Ambang hari ke depan, default 7. Pakai nilai yang SAMA dengan daftar agar isinya tidak berbeda dengan angka pada baris yang diklik. |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Rincian isi batch steril berhasil diambil.",
  "data": {
    "sterilization_id": 12,
    "labels": [
      {
        "key": "R-01|12|PKG260719011",
        "barcode_no": "PKG260719011",
        "type": "paket",
        "name": "Set Partus",
        "rack_code": "R-01",
        "expiry_date": "2026-08-01",
        "days_to_expiry": -22,
        "expired": true,
        "unit_count": 5,
        "storage_ids": [101, 102, 103, 104, 105],
        "units": [
          { "storage_id": 101, "stock_id": 44, "code": "GNT-001", "name": "Gunting Bedah" }
        ]
      },
      {
        "key": "satuan#118",
        "barcode_no": "PKG260719013",
        "type": "satuan",
        "name": "Pinset Anatomis",
        "rack_code": "R-02",
        "expiry_date": "2026-08-30",
        "days_to_expiry": 7,
        "expired": false,
        "unit_count": 1,
        "storage_ids": [118],
        "units": [
          { "storage_id": 118, "stock_id": 77, "code": "PIN-004", "name": "Pinset Anatomis" }
        ]
      }
    ]
  }
}
```

> **`key` bersifat opaque** — dipakai frontend sebagai identitas baris pilihan saja,
> jangan diurai. Yang dikirim ke `repackage` adalah `storage_ids`.
>
> Baris diurut **yang sudah kedaluwarsa lebih dulu**, lalu menurut tanggal terdekat.
> Label yang `expired: false` tetap dikirim supaya petugas melihat isi utuh batchnya,
> tapi tidak boleh dicentang — `repackage` menolaknya.

#### Error (401)
```json
{ "status": false, "message": "Unauthenticated." }
```
