# OrderController@readyToDistribute

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** GET
**Endpoint:** /api/master/orders/ready-to-distribute
**Auth:** Bearer Token (wajib)

Tahap 6 — Distribution & Tracking. Daftar order yang sudah di gudang steril
(status `digudang`) & siap didistribusikan ke unit pelayanan. Menyertakan unit +
lokasi rak (agar petugas tahu mengambil dari mana).

> Batch **Produksi CSSD** (internal, `room_id` null) **dikecualikan** — itu stok
> steril di gudang yang menunggu diorder, bukan order siap-distribusi.

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Cari kode order, no. batch, peminjam, atau ruangan |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data order siap distribusi berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 10,
        "code": "ORD-010",
        "code_transaction": "INV20260628001",
        "status": "digudang",
        "borrowed_by": "OK 1",
        "room": { "id": 3, "name": "OK 1", "code": "OK01" },
        "expiry_date": "2026-12-28",
        "unit_count": 6,
        "units": [
          { "id": 87, "code": "GNE-002", "instrument": "Gunting Epis", "rack_code": "RAK-A-2" }
        ]
      }
    ],
    "per_page": 20,
    "total": 1
  }
}
```
