# tracking

**Method:** GET  
**Endpoint:** `/api/master/instrument-stocks/{instrument_stock}/tracking`  
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@tracking`  
**Auth:** Bearer Token (wajib)

Melacak posisi sebuah unit instrumen di pipeline CSSD: **tahap saat ini** (Produksi → Pencucian → Pengemasan → Sterilisasi → Penyimpanan → Dipinjam) beserta **kode batch produksi** asalnya. Dipakai di halaman Master/Katalog Instrumen — saat status unit bukan `tersedia`, badge status dapat diklik untuk membuka modal tracking ini.

Rantai pipeline direkonstruksi dari `production_item` (titik masuk) lalu dirangkai lewat code antar-tahap (`washing.production_code`, `packaging.washing_code`, `sterilizations.packaging_code`), ditambah `sterilization_items`, `instrument_storages`, dan `order_item` (peminjaman aktif).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| instrument_stock | integer | ID unit instrumen |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Tracking unit instrumen berhasil diambil.",
  "data": {
    "unit": {
      "id": 12,
      "code": "GNT-003",
      "status": "sterilisasi",
      "status_label": "Dalam Proses CSSD",
      "instrument": { "code": "GNT", "name": "Gunting Bedah" },
      "condition": "Baik"
    },
    "production_code": "PRD26070508",
    "current_stage": {
      "key": "sterilisasi",
      "label": "Sterilisasi",
      "code": "STR-005",
      "status": "selesai",
      "at": "2026-07-05T06:39:00.000000Z"
    },
    "stages": [
      { "key": "produksi", "label": "Produksi", "code": "PRD26070508", "status": "selesai", "at": "..." },
      { "key": "pencucian", "label": "Pencucian & Disinfeksi", "code": "WSH26071909", "status": "selesai", "at": "..." },
      { "key": "pengemasan", "label": "Inspeksi & Pengemasan", "code": "PKG26071907", "status": "selesai", "at": "..." },
      { "key": "sterilisasi", "label": "Sterilisasi", "code": "STR-005", "status": "selesai", "at": "..." }
    ],
    "order": null,
    "history": [
      {
        "from_status": "tersedia",
        "to_status": "sterilisasi",
        "context": "production",
        "reference_code": "PRD26070508",
        "note": "Stok dipotong untuk produksi CSSD",
        "by": "Administrator",
        "at": "2026-07-05T06:30:00.000000Z"
      }
    ]
  }
}
```

**Keterangan field:**
- `production_code` — kode batch produksi (PRD-...) asal unit; `null` bila unit belum pernah masuk pipeline produksi.
- `current_stage` — tahap paling maju yang masih aktif; `null` bila unit `tersedia`.
- `stages` — seluruh tahap yang pernah/ sedang dilalui unit (urut pipeline), tiap tahap punya `code` & `status`.
- `order` — info peminjaman aktif (bila `status` unit `dipinjam`), berisi `code`, `code_transaction`, `status`, `borrowed_by`, `room`.
- `history` — hingga 30 log perubahan status terakhir (terbaru dulu) beserta `reference_code`.

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
