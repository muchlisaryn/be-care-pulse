# update

**Method:** PUT / PATCH
**Endpoint:** `/api/master/distributions/{distribution}`
**Controller:** `App\Http\Controllers\Transaction\DistributionController@update`
**Auth:** Bearer Token (wajib)

Ubah catatan atau status distribusi. Mengubah status ke `dibatalkan` akan **mengembalikan stok BMHP**
(`stock_qty` tiap item bertambah lagi sebesar `quantity`).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| status | string | Tidak | `terdistribusi` / `dibatalkan` |
| note | string | Tidak | Ubah catatan |

### Contoh — Batalkan distribusi
```json
{ "status": "dibatalkan" }
```

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Distribusi berhasil diperbarui.",
  "data": { "id": 1, "code": "DST-001", "status": "dibatalkan" }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "status": ["The selected status is invalid."] }
}
```
