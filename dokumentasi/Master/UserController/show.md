# Show

**Method:** GET  
**Endpoint:** /api/master/users/{id}  
**Controller:** App\Http\Controllers\Master\UserController@show

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID user |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Berhasil mengambil detail user.",
  "data": {
    "id": 1,
    "name": "John Doe",
    "username": "johndoe",
    "email": "john@example.com",
    "no_telephone": "081234567890",
    "authority_id": 1,
    "authority": {
      "id": 1,
      "name": "Admin",
      "description": "Administrator sistem"
    }
  }
}
```

### Error (404)
```json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
```
