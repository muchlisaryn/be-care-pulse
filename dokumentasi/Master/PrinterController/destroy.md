# destroy

**Method:** DELETE
**Endpoint:** `/api/master/printers/{printer}`
**Controller:** `App\Http\Controllers\Master\PrinterController@destroy`
**Auth:** Bearer Token (wajib)

Hapus konfigurasi printer. Tabel `master_printers` tidak memakai soft delete
(hanya `timestamps`), jadi record dihapus permanen.

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| printer | integer | ID printer |

### Response — Success (200)
```json
{ "status": true, "message": "Printer berhasil dihapus." }
```

### Response — Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
