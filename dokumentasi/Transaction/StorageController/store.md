# StorageController@store

**Controller:** App\Http\Controllers\Transaction\StorageController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/store
**Auth:** Bearer Token (wajib)

Simpan unit-unit order steril ke lokasi rak gudang. Bila **seluruh** unit order
sudah tersimpan, order → status `digudang` ("Di Dalam Gudang Steril") dan event
timeline `disimpan` dicatat. `expiry_date` disalin dari batch sterilisasi order.

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `steril`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| items | array | Ya | Daftar unit + lokasi rak |
| items[].instrument_stock_id | integer | Ya | ID unit (harus milik order) |
| items[].rack_code | string | Ya | Kode/label rak hasil scan (mis. `RAK-A-2`) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Unit berhasil disimpan ke gudang steril.",
  "data": {
    "id": 10,
    "status": "digudang",
    "unit_count": 6,
    "stored_count": 6,
    "units": [
      { "id": 87, "code": "GNE-002", "stored": true, "rack_code": "RAK-A-2" }
    ]
  }
}
```

### Error (422)
```json
{ "status": false, "message": "Order ini belum steril / tidak siap disimpan." }
```
