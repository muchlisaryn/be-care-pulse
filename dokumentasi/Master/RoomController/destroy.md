# destroy

**Method:** DELETE
**Endpoint:** `/api/master/rooms/{id}`
**Controller:** `App\Http\Controllers\Master\RoomController@destroy`

## Request

### Path Parameter
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID ruangan |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Ruangan berhasil dihapus."
}
```

### Error (404)
```json
{
  "message": "No query results for model [App\\Models\\Room]."
}
```

> Data tidak dihapus secara permanen. `deleted_at` dan `deleted_by` akan diisi (soft delete).
