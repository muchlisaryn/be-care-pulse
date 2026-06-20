# destroy

**Method:** DELETE
**Endpoint:** `/api/master/conditions/{id}`
**Controller:** `App\Http\Controllers\Master\ConditionController@destroy`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID kondisi |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Kondisi berhasil dihapus."
}
```

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\Condition]."
}
```

> Data tidak dihapus secara permanen. `deleted_at` dan `deleted_by` akan diisi (soft delete).
