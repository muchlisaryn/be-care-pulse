# Show

**Method:** GET  
**Endpoint:** /api/master/title-menus/{id}  
**Controller:** App\Http\Controllers\Master\TitleMenuController@show  
**Auth:** Bearer Token (wajib)

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID title menu |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Berhasil mengambil detail title menu.",
  "data": {
    "id": 2,
    "title": "Master Data",
    "sort_order": 2,
    "menus": [
      { "id": 2, "title_menu_id": 2, "parent_id": null, "name": "Master Data", "url": null,                "icon": "database", "sort_order": 1, "is_open": false },
      { "id": 3, "title_menu_id": 2, "parent_id": 2,    "name": "Authority",   "url": "/master/otoritas",  "icon": "shield",   "sort_order": 1, "is_open": false },
      { "id": 4, "title_menu_id": 2, "parent_id": 2,    "name": "Menu",        "url": "/master/menu",      "icon": "menu",     "sort_order": 2, "is_open": false },
      { "id": 5, "title_menu_id": 2, "parent_id": 2,    "name": "User",        "url": "/master/user",      "icon": "users",    "sort_order": 3, "is_open": false }
    ]
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
