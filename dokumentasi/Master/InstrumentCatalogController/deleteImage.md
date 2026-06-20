# deleteImage

**Method:** DELETE
**Endpoint:** `/api/master/instrument-catalogs/{instrument_catalog}/image`
**Controller:** `App\Http\Controllers\Master\InstrumentCatalogController@deleteImage`
**Auth:** Bearer Token (wajib)

Menghapus gambar set/paket. Berkas fisik (bila ada) dihapus dan kolom `image` di-set `null`.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Gambar set berhasil dihapus.",
  "data": {
    "id": 1,
    "code": "KAT-MINOR",
    "name": "Set Bedah Minor",
    "image": null,
    "image_url": null,
    "type": "paket",
    "description": null
  }
}
```

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
