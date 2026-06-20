# Auth & Authority

**Base URL:** /api/auth | /api/master/authorities

> **Default credentials (seeder):**
>
> - Username: `administrator` | Password: `Admin@12345` → akses semua menu
> - Operator dibuat tanpa user default — buat via endpoint register/user

> **Multi-session:** Satu akun dapat login dari beberapa perangkat secara bersamaan. Setiap login menghasilkan token berbeda yang independen.

> **Authority** adalah peran/role yang menentukan menu apa saja yang bisa diakses user. Alur: buat otoritas → lampirkan menu → assign ke user → saat login, `menus` di response sudah difilter sesuai otoritas.

---

## Ringkasan Endpoint

### Auth (`/api/auth`)

| No  | Method | Endpoint                    | Auth         | Fungsi                            |
| --- | ------ | --------------------------- | ------------ | --------------------------------- |
| 1   | POST   | `/api/auth/register`        | Bearer Token | Daftarkan user baru               |
| 2   | POST   | `/api/auth/login`           | Tidak        | Login, terima token + menu        |
| 3   | POST   | `/api/auth/logout`          | Bearer Token | Logout sesi saat ini              |
| 4   | GET    | `/api/auth/me`              | Bearer Token | Ambil data user aktif             |
| 5   | PUT    | `/api/auth/update`          | Bearer Token | Update profil + password          |
| 6   | PUT    | `/api/auth/profile`         | Bearer Token | Update profil saja                |
| 7   | PUT    | `/api/auth/change-password` | Bearer Token | Ganti password (hapus semua sesi) |
| 8   | GET    | `/api/auth/sessions`        | Bearer Token | Lihat semua sesi aktif            |
| 9   | DELETE | `/api/auth/sessions/{id}`   | Bearer Token | Logout sesi tertentu              |
| 10  | DELETE | `/api/auth/sessions`        | Bearer Token | Logout semua sesi                 |

### Authority (`/api/master/authorities`)

| No  | Method | Endpoint                       | Auth         | Fungsi                 |
| --- | ------ | ------------------------------ | ------------ | ---------------------- |
| 11  | GET    | `/api/master/authorities`      | Bearer Token | Daftar semua otoritas  |
| 12  | POST   | `/api/master/authorities`      | Bearer Token | Buat otoritas baru     |
| 13  | GET    | `/api/master/authorities/{id}` | Bearer Token | Detail otoritas + menu |
| 14  | PUT    | `/api/master/authorities/{id}` | Bearer Token | Update otoritas        |
| 15  | DELETE | `/api/master/authorities/{id}` | Bearer Token | Hapus otoritas         |

---

# Auth

## 1. Register

**Method:** POST  
**Endpoint:** /api/auth/register  
**Auth:** Bearer Token (wajib)

> Hanya admin yang sudah login yang dapat mendaftarkan akun baru.

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Body (JSON)

| Parameter             | Type   | Required | Keterangan                   |
| --------------------- | ------ | -------- | ---------------------------- |
| name                  | string | Ya       | Nama lengkap                 |
| username              | string | Ya       | Username unik                |
| email                 | string | Ya       | Email unik, format valid     |
| password              | string | Ya       | Minimal 8 karakter           |
| password_confirmation | string | Ya       | Harus sama dengan `password` |

### Response

#### Success (201)

```json
{
    "status": true,
    "message": "Registrasi berhasil.",
    "data": {
        "user": {
            "id": 2,
            "name": "John Doe",
            "username": "johndoe",
            "email": "john@example.com",
            "created_at": "2026-05-29T08:00:00.000000Z",
            "updated_at": "2026-05-29T08:00:00.000000Z"
        },
        "token": "2|xxxxxxxxxxxxxxxx"
    }
}
```

#### Error (422)

```json
{
    "status": false,
    "message": "Data yang dikirim tidak valid.",
    "errors": {
        "email": ["The email has already been taken."]
    }
}
```

---

## 2. Login

**Method:** POST  
**Endpoint:** /api/auth/login  
**Auth:** Tidak diperlukan

### Body (JSON)

| Parameter   | Type   | Required | Keterangan                                                  |
| ----------- | ------ | -------- | ----------------------------------------------------------- |
| username    | string | Ya       | Username akun                                               |
| password    | string | Ya       | Password akun                                               |
| device_name | string | Tidak    | Label perangkat, misal "HP Android" (default: `auth_token`) |

### Response

#### Success (200) — Administrator (semua menu)

```json
{
    "status": true,
    "message": "Login berhasil.",
    "data": {
        "username": "administrator",
        "token": "1|xxxxxxxxxxxxxxxx",
        "menus": [
            {
                "title_menu": "Dashboard",
                "menus": [
                    {
                        "name": "Dashboard",
                        "url": "/dashboard",
                        "icon": "dashboard",
                        "sort_order": 1,
                        "is_open": false,
                        "menu": []
                    }
                ]
            },
            {
                "title_menu": "Master Data",
                "menus": [
                    {
                        "name": "Master Data",
                        "url": null,
                        "icon": "database",
                        "sort_order": 1,
                        "is_open": false,
                        "menu": [
                            { "name": "Authority", "url": "/master/otoritas" },
                            { "name": "Menu", "url": "/master/menu" },
                            { "name": "User", "url": "/master/user" }
                        ]
                    }
                ]
            }
        ]
    }
}
```

#### Success (200) — Operator (hanya Dashboard)

```json
{
    "status": true,
    "message": "Login berhasil.",
    "data": {
        "username": "operator",
        "token": "2|xxxxxxxxxxxxxxxx",
        "menus": [
            {
                "title_menu": "Dashboard",
                "menus": [
                    {
                        "name": "Dashboard",
                        "url": "/dashboard",
                        "icon": "dashboard",
                        "sort_order": 1,
                        "is_open": false,
                        "menu": []
                    }
                ]
            }
        ]
    }
}
```

> **Catatan struktur untuk frontend:**
> - Struktur 3 tingkat: `title_menu` (group/section sidebar) → `menus` (menu induk) → `menu` (sub-menu/anak).
> - `menus` (induk) punya field: `name`, `url`, `icon`, `sort_order`, `is_open`, `menu`.
> - `menu` (anak) hanya punya `name` & `url` — **tanpa `icon`** (icon hanya di level `menus`).
> - `menu: []` (kosong) → menu induk langsung jadi link via `url`. `menu` berisi array → menu induk jadi header accordion.
> - `is_open` → status default accordion saat halaman dimuat (`true` = terbuka, `false` = tertutup). Default dari server `false`.

#### Error (401)

```json
{
    "status": false,
    "message": "Email/username atau password salah."
}
```

#### Error (403)

```json
{
    "status": false,
    "message": "Akun Anda telah dinonaktifkan."
}
```

---

## 3. Logout

**Method:** POST  
**Endpoint:** /api/auth/logout  
**Auth:** Bearer Token (wajib)

Hanya menghapus token sesi saat ini. Sesi lain tetap aktif.

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Logout berhasil.",
    "data": null
}
```

---

## 4. Me

**Method:** GET  
**Endpoint:** /api/auth/me  
**Auth:** Bearer Token (wajib)

Ambil data user aktif beserta menu aksesnya. Gunakan saat page refresh untuk rehydrate state.

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Data user berhasil diambil.",
    "data": {
        "username": "administrator",
        "menus": [
            {
                "title_menu": "Dashboard",
                "menus": [
                    {
                        "name": "Dashboard",
                        "url": "/dashboard",
                        "icon": "dashboard",
                        "sort_order": 1,
                        "is_open": false,
                        "menu": []
                    }
                ]
            },
            {
                "title_menu": "Master Data",
                "menus": [
                    {
                        "name": "Master Data",
                        "url": null,
                        "icon": "database",
                        "sort_order": 1,
                        "is_open": false,
                        "menu": [
                            { "name": "Authority", "url": "/master/otoritas" },
                            { "name": "Menu", "url": "/master/menu" },
                            { "name": "User", "url": "/master/user" }
                        ]
                    }
                ]
            }
        ]
    }
}
```

---

## 5. Update (Profil + Password)

**Method:** PUT  
**Endpoint:** /api/auth/update  
**Auth:** Bearer Token (wajib)

Update nama, username, email, dan password sekaligus. Password bersifat opsional.

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Body (JSON)

| Parameter             | Type   | Required    | Keterangan                            |
| --------------------- | ------ | ----------- | ------------------------------------- |
| name                  | string | Ya          | Nama lengkap                          |
| username              | string | Ya          | Username unik (kecuali milik sendiri) |
| email                 | string | Ya          | Email unik (kecuali milik sendiri)    |
| password              | string | Tidak       | Password baru, minimal 8 karakter     |
| password_confirmation | string | Kondisional | Wajib jika `password` diisi           |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Data berhasil diperbarui.",
    "data": {
        "id": 1,
        "name": "John Updated",
        "username": "johnupdated",
        "email": "johnupdated@example.com",
        "updated_at": "2026-05-29T09:00:00.000000Z"
    }
}
```

> Token lama **tetap aktif**. Gunakan `PUT /api/auth/change-password` untuk mencabut semua sesi.

#### Error (422)

```json
{
    "status": false,
    "message": "Data yang dikirim tidak valid.",
    "errors": {
        "username": ["The username has already been taken."]
    }
}
```

---

## 6. Update Profile

**Method:** PUT  
**Endpoint:** /api/auth/profile  
**Auth:** Bearer Token (wajib)

Update nama, username, dan email saja — tanpa password.

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Body (JSON)

| Parameter | Type   | Required | Keterangan                            |
| --------- | ------ | -------- | ------------------------------------- |
| name      | string | Ya       | Nama lengkap                          |
| username  | string | Ya       | Username unik (kecuali milik sendiri) |
| email     | string | Ya       | Email unik (kecuali milik sendiri)    |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Profil berhasil diperbarui.",
    "data": {
        "id": 1,
        "name": "John Updated",
        "username": "johnupdated",
        "email": "johnupdated@example.com",
        "updated_at": "2026-05-29T09:00:00.000000Z"
    }
}
```

#### Error (422)

```json
{
    "status": false,
    "message": "Data yang dikirim tidak valid.",
    "errors": {
        "username": ["The username has already been taken."]
    }
}
```

---

## 7. Change Password

**Method:** PUT  
**Endpoint:** /api/auth/change-password  
**Auth:** Bearer Token (wajib)

Ganti password dan hapus semua sesi aktif. Token baru dikembalikan langsung.

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Body (JSON)

| Parameter             | Type   | Required | Keterangan                        |
| --------------------- | ------ | -------- | --------------------------------- |
| current_password      | string | Ya       | Password saat ini                 |
| password              | string | Ya       | Password baru, minimal 8 karakter |
| password_confirmation | string | Ya       | Harus sama dengan `password`      |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Password berhasil diubah. Silakan login ulang.",
    "data": {
        "token": "3|xxxxxxxxxxxxxxxx"
    }
}
```

> Semua token lama dihapus. Simpan token baru ke `localStorage` dan update store.

#### Error (422)

```json
{
    "status": false,
    "message": "Password saat ini tidak sesuai."
}
```

---

## 8. Lihat Semua Sesi Aktif

**Method:** GET  
**Endpoint:** /api/auth/sessions  
**Auth:** Bearer Token (wajib)

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Berhasil mengambil daftar sesi aktif.",
    "data": [
        {
            "id": 1,
            "device_name": "HP Android Siti",
            "last_used": "2026-05-29 08:30:00",
            "created_at": "2026-05-29 07:00:00",
            "is_current": true
        },
        {
            "id": 2,
            "device_name": "Laptop Kantor",
            "last_used": "2026-05-29 06:00:00",
            "created_at": "2026-05-28 09:00:00",
            "is_current": false
        }
    ]
}
```

> `is_current: true` = sesi yang sedang dipakai untuk request ini.

---

## 9. Logout Sesi Tertentu

**Method:** DELETE  
**Endpoint:** /api/auth/sessions/{id}  
**Auth:** Bearer Token (wajib)

### Path Parameters

| Parameter | Type    | Required | Keterangan                            |
| --------- | ------- | -------- | ------------------------------------- |
| id        | integer | Ya       | ID sesi dari `GET /api/auth/sessions` |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Sesi berhasil dihapus.",
    "data": null
}
```

#### Error (404)

```json
{
    "status": false,
    "message": "Sesi tidak ditemukan."
}
```

---

## 10. Logout Semua Sesi

**Method:** DELETE  
**Endpoint:** /api/auth/sessions  
**Auth:** Bearer Token (wajib)

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Semua sesi berhasil dihapus.",
    "data": null
}
```

> Semua token tidak valid setelah ini. Arahkan user ke halaman login.

---

# Authority

## 11. Index

**Method:** GET  
**Endpoint:** /api/master/authorities  
**Auth:** Bearer Token (wajib)

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Query Parameters

| Parameter | Type    | Required | Keterangan                                  |
| --------- | ------- | -------- | ------------------------------------------- |
| search    | string  | Tidak    | Filter berdasarkan nama otoritas            |
| page      | integer | Tidak    | Nomor halaman (default: 1, per halaman: 20) |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Berhasil mengambil data otoritas.",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "name": "Administrator",
                "description": "Akses penuh ke seluruh fitur sistem",
                "created_by": null,
                "updated_by": null,
                "deleted_at": null,
                "deleted_by": null,
                "created_at": "2026-05-29T00:00:00.000000Z",
                "updated_at": "2026-05-29T00:00:00.000000Z"
            },
            {
                "id": 2,
                "name": "Operator",
                "description": "Akses terbatas pada fitur operasional",
                "created_by": null,
                "updated_by": null,
                "deleted_at": null,
                "deleted_by": null,
                "created_at": "2026-05-29T00:00:00.000000Z",
                "updated_at": "2026-05-29T00:00:00.000000Z"
            }
        ],
        "last_page": 1,
        "per_page": 20,
        "total": 2
    }
}
```

> Response index **tidak** menyertakan relasi `menus`. Gunakan endpoint show untuk detail beserta menu.

---

## 12. Store

**Method:** POST  
**Endpoint:** /api/master/authorities  
**Auth:** Bearer Token (wajib)

### Headers

| Key           | Value            | Required |
| ------------- | ---------------- | -------- |
| Authorization | Bearer {token}   | Ya       |
| Content-Type  | application/json | Ya       |

### Body Parameters

| Parameter   | Type             | Required | Keterangan                                                    |
| ----------- | ---------------- | -------- | ------------------------------------------------------------- |
| name        | string           | Ya       | Nama otoritas, unik, maks 100 karakter                        |
| description | string           | Tidak    | Deskripsi otoritas, maks 255 karakter                         |
| menu_ids    | array of integer | Tidak    | ID menu yang bisa diakses. Ambil dari `GET /api/master/menus` |

### Contoh Request

```json
{
    "name": "Perawat",
    "description": "Akses untuk perawat ruangan",
    "menu_ids": [1, 2, 3]
}
```

### Response

#### Success (201)

```json
{
    "status": true,
    "message": "Otoritas berhasil dibuat.",
    "data": {
        "id": 3,
        "name": "Perawat",
        "description": "Akses untuk perawat ruangan",
        "created_by": "administrator",
        "updated_by": "administrator",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-05-29T00:00:00.000000Z",
        "updated_at": "2026-05-29T00:00:00.000000Z",
        "menus": [
            {
                "id": 1,
                "title_menu_id": 1,
                "name": "Dashboard",
                "url": "/dashboard",
                "sort_order": 1
            },
            {
                "id": 2,
                "title_menu_id": 2,
                "name": "Master Data",
                "url": null,
                "sort_order": 1
            },
            {
                "id": 3,
                "title_menu_id": 2,
                "name": "Authority",
                "url": "/master/otoritas",
                "sort_order": 1
            }
        ]
    }
}
```

#### Error (422)

```json
{
    "status": false,
    "message": "Data yang dikirim tidak valid.",
    "errors": {
        "name": ["The name field is required."]
    }
}
```

#### Error (500)

```json
{
    "status": false,
    "message": "pesan error asli dari exception",
    "code": 0,
    "file": "/path/to/file.php",
    "line": 42
}
```

---

## 13. Show

**Method:** GET  
**Endpoint:** /api/master/authorities/{id}  
**Auth:** Bearer Token (wajib)

> Gunakan saat membuka halaman edit otoritas — untuk mengetahui menu mana yang sudah dicentang.

### Path Parameters

| Parameter | Type    | Required | Keterangan  |
| --------- | ------- | -------- | ----------- |
| id        | integer | Ya       | ID otoritas |

### Response

#### Success (200) — Administrator

```json
{
    "status": true,
    "message": "Berhasil mengambil detail otoritas.",
    "data": {
        "id": 1,
        "name": "Administrator",
        "description": "Akses penuh ke seluruh fitur sistem",
        "created_by": null,
        "updated_by": null,
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-05-29T00:00:00.000000Z",
        "updated_at": "2026-05-29T00:00:00.000000Z",
        "menus": [
            {
                "id": 1,
                "title_menu_id": 1,
                "name": "Dashboard",
                "url": "/dashboard",
                "sort_order": 1
            },
            {
                "id": 2,
                "title_menu_id": 2,
                "name": "Master Data",
                "url": null,
                "sort_order": 1
            },
            {
                "id": 3,
                "title_menu_id": 2,
                "name": "Authority",
                "url": "/master/otoritas",
                "sort_order": 1
            },
            {
                "id": 4,
                "title_menu_id": 2,
                "name": "Menu",
                "url": "/master/menu",
                "sort_order": 2
            },
            {
                "id": 5,
                "title_menu_id": 2,
                "name": "User",
                "url": "/master/user",
                "sort_order": 3
            }
        ]
    }
}
```

#### Success (200) — Operator

```json
{
    "status": true,
    "message": "Berhasil mengambil detail otoritas.",
    "data": {
        "id": 2,
        "name": "Operator",
        "description": "Akses terbatas pada fitur operasional",
        "menus": [
            {
                "id": 1,
                "title_menu_id": 1,
                "name": "Dashboard",
                "url": "/dashboard",
                "sort_order": 1
            }
        ]
    }
}
```

#### Error (404)

```json
{
    "status": false,
    "message": "Data tidak ditemukan."
}
```

---

## 14. Update

**Method:** PUT / PATCH  
**Endpoint:** /api/master/authorities/{id}  
**Auth:** Bearer Token (wajib)

Field `menu_ids` bersifat **replace-all**: daftar yang dikirim menggantikan seluruh relasi sebelumnya.

> - Kirim semua `menu_ids` yang dicentang (bukan hanya yang berubah)
> - `menu_ids: []` → cabut semua akses menu
> - Tidak kirim `menu_ids` → menu tidak berubah

### Path Parameters

| Parameter | Type    | Required | Keterangan  |
| --------- | ------- | -------- | ----------- |
| id        | integer | Ya       | ID otoritas |

### Body Parameters

| Parameter   | Type             | Required | Keterangan                        |
| ----------- | ---------------- | -------- | --------------------------------- |
| name        | string           | Tidak    | Nama otoritas baru, unik          |
| description | string           | Tidak    | Deskripsi otoritas                |
| menu_ids    | array of integer | Tidak    | Daftar lengkap ID menu yang aktif |

### Contoh Request

```json
{
    "name": "Perawat Senior",
    "menu_ids": [1, 2, 3, 4, 5]
}
```

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Otoritas berhasil diperbarui.",
    "data": {
        "id": 3,
        "name": "Perawat Senior",
        "description": "Akses untuk perawat ruangan",
        "menus": [
            {
                "id": 1,
                "title_menu_id": 1,
                "name": "Dashboard",
                "url": "/dashboard",
                "sort_order": 1
            },
            {
                "id": 2,
                "title_menu_id": 2,
                "name": "Master Data",
                "url": null,
                "sort_order": 1
            },
            {
                "id": 3,
                "title_menu_id": 2,
                "name": "Authority",
                "url": "/master/otoritas",
                "sort_order": 1
            },
            {
                "id": 4,
                "title_menu_id": 2,
                "name": "Menu",
                "url": "/master/menu",
                "sort_order": 2
            },
            {
                "id": 5,
                "title_menu_id": 2,
                "name": "User",
                "url": "/master/user",
                "sort_order": 3
            }
        ]
    }
}
```

#### Error (404)

```json
{
    "status": false,
    "message": "Data tidak ditemukan."
}
```

#### Error (422)

```json
{
    "status": false,
    "message": "Data yang dikirim tidak valid.",
    "errors": {
        "name": ["The name has already been taken."]
    }
}
```

---

## 15. Destroy

**Method:** DELETE  
**Endpoint:** /api/master/authorities/{id}  
**Auth:** Bearer Token (wajib)

Soft delete — data tidak dihapus permanen, hanya ditandai `deleted_at` dan `deleted_by`.

> User yang memiliki otoritas ini **tidak otomatis** kehilangan akses sampai token mereka expired atau login ulang.

### Path Parameters

| Parameter | Type    | Required | Keterangan  |
| --------- | ------- | -------- | ----------- |
| id        | integer | Ya       | ID otoritas |

### Response

#### Success (200)

```json
{
    "status": true,
    "message": "Otoritas berhasil dihapus.",
    "data": null
}
```

#### Error (404)

```json
{
    "status": false,
    "message": "Data tidak ditemukan."
}
```

---

## Alur Penggunaan Frontend — Authority

### Halaman Kelola Otoritas

```
GET /api/master/authorities       → daftar otoritas
GET /api/master/title-menus       → section sidebar (untuk grouping checkbox)
GET /api/master/menus             → semua menu (untuk form checkbox)
```

### Buat otoritas baru

```
POST /api/master/authorities
Body: { name, description, menu_ids: [id yang dicentang] }
```

### Edit otoritas

```
GET  /api/master/authorities/{id}   → prefill form + ketahui menu aktif
PUT  /api/master/authorities/{id}   → simpan dengan seluruh menu_ids yang dicentang
```

### Assign ke user

Saat buat/edit user, isi `authority_id` via endpoint UserController. Setelah login, `menus` di response sudah difilter sesuai otoritas yang di-assign.
