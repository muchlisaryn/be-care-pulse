# store

**Method:** POST
**Endpoint:** `/api/master/distributions`
**Controller:** `App\Http\Controllers\Transaction\DistributionController@store`
**Auth:** Bearer Token (wajib)

Membuat distribusi BMHP (bahan medis habis pakai). Saat berhasil, `bmhp.stock_qty` tiap item dikurangi sebesar `quantity`.

> Catatan: distribusi alat instrumen (pakai-ulang) **tidak** lewat endpoint ini, melainkan lewat **Order Instrumen** karena alat harus dikembalikan dan disterilkan ulang.

Pengirim (`sender_id`) otomatis = user yang login. Status awal `terdistribusi`.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| room_id | integer | Ya | Unit/ruangan tujuan |
| receiver_id | integer | Ya | User penerima |
| distributed_at | datetime | Tidak | Default sekarang |
| note | string | Tidak | Keterangan |
| items | array | Ya | Minimal 1 item |
| items[].bmhp_id | integer | Ya | BMHP yang didistribusikan |
| items[].quantity | integer | Tidak | Jumlah (default 1) |
| items[].note | string | Tidak | Keterangan per item |

### Contoh
```json
{
  "room_id": 1,
  "receiver_id": 2,
  "note": "Distribusi pagi",
  "items": [
    { "bmhp_id": 1, "quantity": 10 },
    { "bmhp_id": 2, "quantity": 5, "note": "untuk ruang tindakan" }
  ]
}
```

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Distribusi BMHP berhasil dibuat.",
  "data": {
    "id": 1,
    "code": "DST-001",
    "status": "terdistribusi",
    "room": { "id": 1, "name": "RAWAT INAP" },
    "sender": { "id": 1, "name": "tri.aji" },
    "receiver": { "id": 2, "name": "AMBAR MELANI" },
    "items": [
      {
        "id": 1, "bmhp_id": 1, "quantity": 10, "note": null,
        "bmhp": { "id": 1, "code": "BMHP-001", "name": "Kasa Steril", "unit": "pcs" }
      },
      {
        "id": 2, "bmhp_id": 2, "quantity": 5, "note": "untuk ruang tindakan",
        "bmhp": { "id": 2, "code": "BMHP-002", "name": "Sarung Tangan Steril", "unit": "pasang" }
      }
    ]
  }
}
```

### Error (422) — stok tidak cukup / validasi
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "items": ["Stok BMHP Kasa Steril tidak mencukupi (tersisa 3)."] }
}
```
