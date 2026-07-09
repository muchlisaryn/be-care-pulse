# show

**Method:** GET
**Endpoint:** `/api/master/printers/{printer}`
**Controller:** `App\Http\Controllers\Master\PrinterController@show`
**Auth:** Bearer Token (wajib)

Detail satu konfigurasi printer (tabel `master_printers`).

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| printer | integer | ID printer |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Detail printer berhasil diambil.",
  "data": {
    "id": 1,
    "name": "Printer Label CSSD",
    "document_type": "label",
    "printer_language": "zpl",
    "connection_type": "usb",
    "ip_address": null,
    "port": null,
    "device_path": "/dev/usb/lp0",
    "paper_size": null,
    "char_per_line": null,
    "auto_cut": true,
    "label_width_mm": 40,
    "label_height_mm": 30,
    "label_gap_mm": 2,
    "code_page": "CP437",
    "is_active": true,
    "created_at": "...",
    "updated_at": "..."
  }
}
```

### Response — Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
