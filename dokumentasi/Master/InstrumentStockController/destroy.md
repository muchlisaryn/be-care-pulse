# destroy

**Method:** DELETE
**Endpoint:** `/api/master/instrument-stocks/{id}`
**Controller:** `App\Http\Controllers\Master\InstrumentStockController@destroy`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID stok instrumen |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Stok instrumen berhasil dihapus."
}
```

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\InstrumentStock]."
}
```

> Data tidak dihapus secara permanen. `deleted_at` dan `deleted_by` akan diisi (soft delete).

## Unit yang sedang dipinjam

Unit berstatus `dipinjam` **ditolak 422**: barangnya ada di tangan peminjam dan masih
tertaut order aktif, sehingga mengubah kondisi atau menghapus barisnya membuat data
order menggantung.

```json
{ "status": false, "message": "Unit INSK-001 sedang dipinjam — tidak bisa diubah atau dihapus sampai dikembalikan." }
```

> Penghapusan bersifat **soft delete** (trait `HasAuditColumns`): baris TIDAK hilang dari
> tabel — hanya diisi `deleted_at`, `deleted_by` (username), dan `deleted_user_id`, lalu
> otomatis tersaring dari query biasa oleh global scope `active`.
