# Update

**Method:** PUT / PATCH  
**Endpoint:** /api/master/users/{id}  
**Controller:** App\Http\Controllers\Master\UserController@update

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID user |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| name | string | Tidak | Nama lengkap user |
| username | string | Tidak | Username unik |
| email | string | Tidak | Email unik |
| no_telephone | string | Tidak | Nomor telepon, maks 20 karakter |
| authority_id | integer | Tidak | ID otoritas user |
| password | string | Tidak | Password baru (minimal 8 karakter). Abaikan jika tidak ingin ganti |
| password_confirmation | string | Tidak | Wajib jika `password` diisi |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "User berhasil diperbarui.",
  "data": {
    "id": 1,
    "name": "John Doe",
    "username": "johndoe",
    "email": "john@example.com",
    "no_telephone": "081234567890",
    "authority_id": 2,
    "authority": { "id": 2, "name": "Perawat" }
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

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {}
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
