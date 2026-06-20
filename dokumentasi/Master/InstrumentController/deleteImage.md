# deleteImage

**Method:** DELETE
**Endpoint:** `/api/master/instruments/{instrument}/image`
**Controller:** `App\Http\Controllers\Master\InstrumentController@deleteImage`
**Auth:** Bearer Token (wajib)

Menghapus gambar instrumen: berkas fisik dihapus dan kolom `image` di-null-kan.

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
  "message": "Gambar instrumen berhasil dihapus.",
  "data": { "id": 1, "code": "INS-001", "name": "Stetoskop", "image": null, "image_url": null }
}
```
