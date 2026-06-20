# Index

**Method:** GET  
**Endpoint:** /api/master/menus  
**Controller:** App\Http\Controllers\Master\MenuController@index

## Request

### Headers

| Key           | Value          | Required |
| ------------- | -------------- | -------- |
| Authorization | Bearer {token} | Ya       |

### Query Parameters

| Parameter | Type   | Required | Keterangan                   |
| --------- | ------ | -------- | ---------------------------- |
| search    | string | Tidak    | Filter berdasarkan nama menu |

## Response

Data disusun 3 tingkat:

1. **Grup `title_menu`** — diambil dari relasi `titleMenu` (`title_menu.title`).
2. **`menus`** — daftar menu induk (`parent_id = null`). Field: `id`, `title_menu_id`, `parent_id`, `name`, `url`, `icon`, `sort_order`, `is_open`, `menu`.
3. **`menu`** — daftar anak (sub-menu) dari masing-masing menu induk. Field hanya `id`, `name`, `url` — **tanpa `icon`** (icon hanya ada di level `menus`).

### Success (200)

```json
{
    "status": true,
    "message": "Berhasil mengambil data menu.",
    "data": [
        {
            "title_menu": "Dashboard",
            "menus": [
                {
                    "id": 1,
                    "title_menu_id": 1,
                    "parent_id": null,
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
                    "id": 2,
                    "title_menu_id": 2,
                    "parent_id": null,
                    "name": "Master Data",
                    "url": null,
                    "icon": "database",
                    "sort_order": 1,
                    "is_open": false,
                    "menu": [
                        { "id": 3, "name": "Authority", "url": "/master/otoritas" },
                        { "id": 4, "name": "Menu", "url": "/master/menu" },
                        { "id": 5, "name": "User", "url": "/master/user" }
                    ]
                }
            ]
        }
    ]
}
```

> Catatan:
> - `title_menu` bisa bernilai `null` untuk grup menu yang tidak terhubung ke title menu manapun (`title_menu_id = null`).
> - Menu induk tanpa anak akan punya `menu: []` (array kosong) — biasanya dipakai langsung sebagai link via `url`.

### Error (401)

```json
{
    "status": false,
    "message": "Unauthenticated."
}
```
