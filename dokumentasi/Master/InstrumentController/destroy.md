# destroy

**Method:** DELETE
**Endpoint:** `/api/master/instruments/{id}`
**Controller:** `App\Http\Controllers\Master\InstrumentController@destroy`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID instrumen |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Instrumen berhasil dihapus."
}
```

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\Instrument]."
}
```

> Data tidak dihapus secara permanen. `deleted_at` dan `deleted_by` akan diisi (soft delete).
