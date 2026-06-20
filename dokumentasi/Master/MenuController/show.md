# Show

**Method:** GET  
**Endpoint:** /api/master/menus/{id}  
**Controller:** App\Http\Controllers\Master\MenuController@show

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| id | integer | Ya | ID menu |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Berhasil mengambil detail menu.",
  "data": {
    "id": 2,
    "title_menu_id": 2,
    "parent_id": null,
    "name": "Master Data",
    "url": null,
    "icon": "database",
    "sort_order": 1,
    "is_open": false,
    "title_menu": { "id": 2, "title": "Master Data" },
    "parent": null,
    "children": [
      { "id": 3, "title_menu_id": 2, "parent_id": 2, "name": "Authority", "url": "/master/otoritas", "icon": "shield", "sort_order": 1, "is_open": false },
      { "id": 4, "title_menu_id": 2, "parent_id": 2, "name": "Menu", "url": "/master/menu", "icon": "menu", "sort_order": 2, "is_open": false },
      { "id": 5, "title_menu_id": 2, "parent_id": 2, "name": "User", "url": "/master/user", "icon": "users", "sort_order": 3, "is_open": false }
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
