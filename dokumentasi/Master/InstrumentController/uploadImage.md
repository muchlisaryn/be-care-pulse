# uploadImage

**Method:** POST
**Endpoint:** `/api/master/instruments/{instrument}/image`
**Controller:** `App\Http\Controllers\Master\InstrumentController@uploadImage`
**Auth:** Bearer Token (wajib)

Mengunggah / mengganti gambar instrumen (opsional). Dikirim sebagai `multipart/form-data`.
Gambar lama (bila ada) otomatis dihapus. Berkas disimpan di `public/uploads/instruments/`
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
  "message": "Gambar instrumen berhasil diunggah.",
  "data": {
    "id": 1,
    "code": "INS-001",
    "name": "Stetoskop",
    "image": "uploads/instruments/ins-1-1718700000.jpg",
    "image_url": "http://localhost:8000/uploads/instruments/ins-1-1718700000.jpg"
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
