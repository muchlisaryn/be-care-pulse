# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/printers/{printer}`
**Controller:** `App\Http\Controllers\Master\PrinterController@update`
**Auth:** Bearer Token (wajib)

Perbarui konfigurasi printer. Body sama dengan **store** (lihat `store.md`).

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| printer | integer | ID printer |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Printer berhasil diperbarui.",
  "data": { "id": 1, "name": "Printer Kasir 1", "document_type": "struk", "is_active": true, "updated_at": "..." }
}
```

### Response — Error (422)
```json
{ "status": false, "message": "Data yang dikirim tidak valid.", "errors": { "connection_type": ["The selected connection type is invalid."] } }
```
