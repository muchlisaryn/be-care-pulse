# allocation

**Method:** GET  
**Endpoint:** `/api/master/orders/{order}/allocation`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@allocation`  
**Auth:** Bearer Token (wajib)

Data untuk **menerima order**: menghitung kebutuhan unit fisik dari baris
permintaan (`order_request_item`) — baris `satuan` langsung memakai
`instrument_id × quantity`, baris `paket` di-expand menjadi tiap instrumen di
katalog (`instrument_catalog_item.quantity × quantity paket`). Tiap kebutuhan
dikelompokkan per `(instrumen, asal, nama paket)` dan dilengkapi daftar unit
yang masih `tersedia` untuk dipilih / di-generate di frontend.

Hanya bisa diakses bila status order `diajukan` atau `disetujui` (selain itu 422).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data alokasi order berhasil diambil.",
  "data": {
    "order": {
      "id": 7,
      "code": "ORD-001",
      "status": "diajukan",
      "borrowed_by": "Muchlis Aryana",
      "order_date": "2026-06-17T00:00:00.000000Z",
      "return_plan_date": "2026-06-18T00:00:00.000000Z",
      "room": { "id": 1, "name": "Annur 1" }
    },
    "requirements": [
      {
        "key": "paket|18|SET PARTUS",
        "source": "paket",
        "package_name": "SET PARTUS",
        "instrument_id": 18,
        "instrument": { "id": 18, "code": "GNE", "name": "Gunting Epis" },
        "needed_qty": 1,
        "available_units": [
          { "id": 86, "code": "GNE-001" },
          { "id": 87, "code": "GNE-002" }
        ],
        "available_count": 2
      }
    ]
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Order ini sudah diproses dan tidak bisa diterima lagi."
}
```
