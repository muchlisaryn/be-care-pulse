# Index

**Method:** GET  
**Endpoint:** /api/master/users  
**Controller:** App\Http\Controllers\Master\UserController@index

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan name, username, atau email |
| page | integer | Tidak | Halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Berhasil mengambil data user.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "name": "John Doe",
        "username": "johndoe",
        "email": "john@example.com",
        "no_telephone": "081234567890",
        "authority_id": 1,
        "authority": {
          "id": 1,
          "name": "Admin"
        }
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated."
}
```
