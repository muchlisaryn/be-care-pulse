# ProductionController — store

**Controller:** App\Http\Controllers\Transaction\ProductionController
**Base URL:** /api/master

---

## 1. store (Mulai Produksi)

**Method:** POST
**Endpoint:** /api/master/production
**Auth:** Bearer Token (wajib)

Awal lifecycle pemrosesan CSSD. CSSD memproses stok alat miliknya sendiri (tanpa
order peminjam) dan langsung memasukkannya ke antrean **Cleaning**. Membuat order
INTERNAL (`room_id` null, `borrowed_by` = "Produksi CSSD") berstatus `pencucian`,
sehingga mengalir ke pipeline yang ada: Cleaning → Packaging → Sterilization →
Storage. Unit fisik di-scan saat Packaging seperti order biasa.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| note | string | Tidak | Catatan opsional batch produksi |
| items | array | Ya | Baris produksi (min 1) |
| items[].type | string | Ya | `satuan` atau `paket` |
| items[].quantity | integer | Ya | Jumlah (min 1) |
| items[].instrument_id | integer | Ya jika `type=satuan` | ID instrumen (exists:instruments,id) |
| items[].instrument_catalog_id | integer | Ya jika `type=paket` | ID katalog/set (exists:instrument_catalogs,id) |
| items[].package_name | string | Tidak | Nama paket (untuk `type=paket`) |

### Response

#### Success (201)
```json
{
  "status": true,
  "message": "Batch produksi berhasil dibuat & masuk tahap Cleaning.",
  "data": {
    "id": 12,
    "code": "ORD-012",
    "room_id": null,
    "borrowed_by": "Produksi CSSD",
    "status": "pencucian",
    "processed_at": "2026-06-30T08:00:00.000000Z",
    "request_items": [
      { "id": 30, "type": "satuan", "instrument_id": 7, "quantity": 2 }
    ],
    "washing": { "id": 9, "status": "dalam_proses" }
  }
}
```

#### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "items": ["The items field is required."] }
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
