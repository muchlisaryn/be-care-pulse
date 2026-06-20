# Index

**Method:** GET  
**Endpoint:** /api/master/title-menus  
**Controller:** App\Http\Controllers\Master\TitleMenuController@index  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter berdasarkan title |
| page | integer | Tidak | Halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Berhasil mengambil data title menu.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "title": "Dashboard",
        "sort_order": 1,
        "menus": [
          { "id": 1, "title_menu_id": 1, "parent_id": null, "name": "Dashboard", "url": "/dashboard", "icon": "dashboard", "sort_order": 1, "is_open": false }
        ]
      },
      {
        "id": 2,
        "title": "Master Data",
        "sort_order": 2,
        "menus": [
          { "id": 2, "title_menu_id": 2, "parent_id": null, "name": "Master Data", "url": null,               "icon": "database", "sort_order": 1, "is_open": false },
          { "id": 3, "title_menu_id": 2, "parent_id": 2,    "name": "Authority",   "url": "/master/otoritas", "icon": "shield",   "sort_order": 1, "is_open": false },
          { "id": 4, "title_menu_id": 2, "parent_id": 2,    "name": "Menu",        "url": "/master/menu",     "icon": "menu",     "sort_order": 2, "is_open": false },
          { "id": 5, "title_menu_id": 2, "parent_id": 2,    "name": "User",        "url": "/master/user",     "icon": "users",    "sort_order": 3, "is_open": false }
        ]
      }
    ],
    "last_page": 1,
    "per_page": 20,
    "total": 2
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
