# PackagingController — index

**Controller:** App\Http\Controllers\Transaction\PackagingController
**Base URL:** /api/master

---

## 1. index (Daftar Tahap Packaging)

**Method:** GET
**Endpoint:** /api/master/packaging
**Auth:** Bearer Token (wajib)

> **Format kode batch.** Identitas record `packaging` disimpan di dua kolom:
> `prefix` (`PKG` = pengemasan normal, `RPK` = pengemasan ulang unit gagal steril)
> dan `code` (**angka saja**: ymd + urutan harian, mis. `26050201`). Tiap prefix
> punya deret nomor sendiri, dijaga index unik gabungan (`prefix`, `code`). Field
> `code` pada response API sudah berisi **kode utuh** (`PKG26050201`), bukan
> angkanya saja. Prefix ditetapkan sekali saat record dibuat dan tidak pernah
> diubah — status void tetap dibaca dari kolom `disabled`.

Daftar batch pada tahap **Inspection & Packaging** pipeline produksi, digabung
dari **dua sumber**:

1. record `packaging` berstatus `diproses` maupun
   `selesai` (sudah dikemas — agar labelnya bisa dilihat/dicetak ulang lewat
   `GET /master/packaging/{packaging}/label`) → `started: true`;
2. batch cleaning berstatus `selesai` yang **belum punya record packaging**
   (antrean menunggu inspeksi) → `started: false`, dengan `id` dan `code` masih
   `null`. Recordnya baru dibuat saat "Selesai & Cetak Label" lewat `POST /master/packaging/complete`, jadi
   frontend memakai `washing_code` sebagai identitas batch antrean.

Batch yang record packagingnya di-void (`disabled`) tidak muncul lagi sebagai
antrean. Field `stage_status` (`diproses` | `selesai`) menandai kondisi tiap
batch. Dirangkai ke tahap cleaning lewat `washing_code`, dan ke produksi lewat
rantai `washing.production_code`. Unit fisik sudah dikunci sejak tahap Produksi.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari pada kode utuh (`prefix`+`code`, mis. `PKG26050201`), `washing_code` (WSH), `production_code` (PRD), atau `operator` |
| page | integer | Tidak | Halaman pagination (default 1) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Data tahap packaging berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 3,
        "code": "PKG26071903",
        "code_transaction": "PRD20260702004",
        "washing_code": "WSH26071903",
        "started": true,
        "status": "pengemasan",
        "stage_status": "diproses",
        "borrowed_by": "SET PARTUS",
        "processed_at": "2026-07-02T08:00:00.000000Z",
        "processed_by": "Admin",
        "completed_by": null,
        "completed_at": null,
        "operator": null,
        "chemical_indicator": null,
        "packaged_at": null,
        "units_count": 6,
        "items": [
          { "type": "paket", "name": "SET PARTUS", "quantity": 6 }
        ],
        "units": [
          {
            "id": 21,
            "source": "paket",
            "package_name": "SET PARTUS",
            "instrument_stock_id": 18,
            "code": "GNT-001",
            "instrument": { "id": 7, "name": "Gunting Epis" },
            "status": "sterilisasi",
            "condition_out": { "id": 1, "name": "Baik" }
          }
        ]
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 3
  }
}
```

#### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
