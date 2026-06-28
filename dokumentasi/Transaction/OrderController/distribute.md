# OrderController@distribute

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/distribute
**Auth:** Bearer Token (wajib)

Tahap 6 — Distribusikan order steril ke unit pelayanan (Double Verification +
tautan RM pasien). Efek:
- Unit keluar gudang (storage `keluar`).
- Unit → status `dipinjam` (Terdistribusi / Digunakan).
- Order → status `dipinjam` + data RM pasien, event timeline `terdistribusi`.
- Riwayat mengunci **full traceability loop** (alat → batch sterilisasi → RM pasien).

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `digudang`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| recipient | string | Ya | Ruangan / petugas penerima (hasil scan — double verification) |
| medical_record_no | string | Tidak | No. Rekam Medis pasien |
| patient_name | string | Tidak | Nama pasien |
| note | string | Tidak | Catatan |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Alat steril berhasil didistribusikan.",
  "data": {
    "id": 10,
    "status": "dipinjam",
    "distributed_to": "OK 1",
    "medical_record_no": "RM-00123",
    "patient_name": "Budi",
    "distributed_at": "2026-06-28T09:30:00.000000Z"
  }
}
```

### Error (422)
```json
{ "status": false, "message": "Order ini belum berada di gudang steril / tidak siap didistribusikan." }
```
