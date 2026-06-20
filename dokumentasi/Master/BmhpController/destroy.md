# destroy

**Method:** DELETE
**Endpoint:** `/api/master/bmhps/{bmhp}`
**Controller:** `App\Http\Controllers\Master\BmhpController@destroy`
**Auth:** Bearer Token (wajib)

Soft delete BMHP.

## Response

### Success (200)
```json
{ "status": true, "message": "BMHP berhasil dihapus." }
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
