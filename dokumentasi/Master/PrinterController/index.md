# index

**Method:** GET
**Endpoint:** `/api/master/printers`
**Controller:** `App\Http\Controllers\Master\PrinterController@index`
**Auth:** Bearer Token (wajib)

Daftar konfigurasi printer (Pengaturan → Master Printer). Tabel: `master_printers`. Mendukung pencarian & pagination.

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter (like) `name`, `ip_address`, atau `device_path` |
| page | integer | Tidak | Nomor halaman (default: 1) |

### Response — Success (200)
```json
{
  "status": true,
  "message": "Data printer berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "name": "Printer Kasir 1",
        "document_type": "struk",
        "printer_language": "escpos",
        "connection_type": "network",
        "ip_address": "192.168.1.50",
        "port": 9100,
        "device_path": null,
        "paper_size": "58mm",
        "char_per_line": 32,
        "auto_cut": true,
        "label_width_mm": null,
        "label_height_mm": null,
        "label_gap_mm": null,
        "code_page": "CP437",
        "is_active": true,
        "created_at": "2026-07-09T08:00:00.000000Z",
        "updated_at": "2026-07-09T08:00:00.000000Z"
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```
