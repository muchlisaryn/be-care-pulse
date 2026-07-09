# store

**Method:** POST
**Endpoint:** `/api/master/printers`
**Controller:** `App\Http\Controllers\Master\PrinterController@store`
**Auth:** Bearer Token (wajib)

Tambah konfigurasi printer (struk/label). Tabel: `master_printers`.

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string (max 255) | Ya | Nama printer |
| document_type | enum `struk`\|`label` | Ya | Jenis dokumen yang dicetak |
| printer_language | enum `escpos`\|`tspl`\|`zpl`\|`epl` | Ya | Bahasa/protokol printer (default `escpos`) |
| connection_type | enum `network`\|`usb`\|`bluetooth`\|`serial` | Ya | Tipe koneksi |
| ip_address | string | Tidak | IP printer (koneksi `network`) |
| port | integer | Tidak | Port printer (default 9100) |
| device_path | string | Tidak | Path device (koneksi non-network, mis. `/dev/usb/lp0`, `COM3`) |
| paper_size | enum `58mm`\|`80mm` | Tidak | Khusus struk |
| char_per_line | integer | Tidak | Khusus struk — jumlah karakter per baris |
| auto_cut | boolean | Tidak | Khusus struk (default true) |
| label_width_mm | integer | Tidak | Khusus label |
| label_height_mm | integer | Tidak | Khusus label |
| label_gap_mm | number (float) | Tidak | Khusus label |
| code_page | string | Tidak | Code page (default `CP437`) |
| is_active | boolean | Tidak | Status aktif (default true) |

### Response — Success (201)
```json
{
  "status": true,
  "message": "Printer berhasil ditambahkan.",
  "data": {
    "id": 1,
    "name": "Printer Kasir 1",
    "document_type": "struk",
    "printer_language": "escpos",
    "connection_type": "network",
    "ip_address": "192.168.1.50",
    "port": 9100,
    "paper_size": "58mm",
    "char_per_line": 32,
    "auto_cut": true,
    "code_page": "CP437",
    "is_active": true,
    "created_at": "...",
    "updated_at": "..."
  }
}
```

### Response — Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "document_type": ["The selected document type is invalid."] }
}
```
