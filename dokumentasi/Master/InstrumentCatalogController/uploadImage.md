# uploadImage

**Method:** POST
**Endpoint:** `/api/master/instrument-catalogs/{instrument_catalog}/image`
**Controller:** `App\Http\Controllers\Master\InstrumentCatalogController@uploadImage`
**Auth:** Bearer Token (wajib)

Mengunggah / mengganti gambar set/paket (opsional). Dikirim sebagai `multipart/form-data`.
Gambar lama (bila ada) otomatis dihapus. Berkas disimpan di `public/uploads/instrument-catalogs/`
dan path-nya tersimpan di kolom `image`.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | multipart/form-data | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| image | file | Ya | Gambar `jpg,jpeg,png,webp`, maksimal 2 MB |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Gambar set berhasil diunggah.",
  "data": {
    "id": 1,
    "code": "KAT-MINOR",
    "name": "Set Bedah Minor",
    "image": "uploads/instrument-catalogs/cat-1-1718700000.jpg",
    "image_url": "http://localhost:8000/uploads/instrument-catalogs/cat-1-1718700000.jpg",
    "type": "paket",
    "description": null
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "image": ["The image must be an image."] }
}
```
