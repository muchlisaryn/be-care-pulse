# OrderController@distribute

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/distribute
**Auth:** Bearer Token (wajib)

Tahap 6 — Distribusikan order steril ke unit pelayanan (Double Verification).
No RM & Nama Pasien **tidak** diinput di sini — sudah diisi saat pembuatan order
dan dibawa apa adanya ke event distribusi. Efek:
- Unit keluar gudang (storage `keluar`).
- Unit → status `dipinjam` (Terdistribusi / Digunakan).
- Order → status `dipinjam`, event timeline `terdistribusi`.
- Riwayat mengunci **full traceability loop** (alat → batch sterilisasi → RM pasien).

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `digudang`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| recipient | string | Ya | Ruangan / petugas penerima (hasil scan — double verification) |
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
