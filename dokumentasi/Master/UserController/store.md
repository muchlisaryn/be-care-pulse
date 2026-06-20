# Store

**Method:** POST  
**Endpoint:** /api/master/users  
**Controller:** App\Http\Controllers\Master\UserController@store

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Ya | Nama lengkap user |
| username | string | Ya | Username unik, maks 100 karakter |
| email | string | Ya | Email unik |
| no_telephone | string | Tidak | Nomor telepon (opsional) |
| authority_id | integer | Ya | ID otoritas user (harus ada di tabel authorities) |
| password | string | Ya | Minimal 8 karakter |
| password_confirmation | string | Ya | Harus sama dengan `password` |

## Response

### Success (201)
```json
{
  "status": true,
  "message": "User berhasil dibuat.",
  "data": {
    "id": 1,
    "name": "John Doe",
    "username": "johndoe",
    "email": "john@example.com",
    "authority_id": 1,
    "authority": {
      "id": 1,
      "name": "Administrator",
      "description": "Akses penuh ke seluruh fitur sistem"
    },
    "created_at": "2026-05-26T08:00:00.000000Z",
    "updated_at": "2026-05-26T08:00:00.000000Z"
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "username": ["The username has already been taken."],
    "authority_id": ["The authority id field is required."]
  }
}
```

### Error (500)
```json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
```
