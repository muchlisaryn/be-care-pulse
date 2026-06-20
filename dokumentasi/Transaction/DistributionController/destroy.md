# destroy

**Method:** DELETE
**Endpoint:** `/api/master/distributions/{distribution}`
**Controller:** `App\Http\Controllers\Transaction\DistributionController@destroy`
**Auth:** Bearer Token (wajib)

Soft delete distribusi. Bila distribusi masih aktif (belum `dibatalkan`), stok BMHP dikembalikan
dulu (`stock_qty` tiap item bertambah).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Keterangan |
|-----------|------|------------|
| distribution | integer | ID distribusi |

## Response

### Success (200)
```json
{ "status": true, "message": "Distribusi berhasil dihapus." }
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
