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
