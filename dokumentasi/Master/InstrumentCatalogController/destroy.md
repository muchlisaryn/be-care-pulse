# InstrumentCatalogController — destroy

**Method:** DELETE
**Endpoint:** /api/master/instrument-catalogs/{id}
**Auth:** Bearer Token (wajib)

Soft delete katalog (set `deleted_by` + `deleted_at`).

### Response — Success (200)
```json
{ "status": true, "message": "Katalog instrumen berhasil dihapus." }
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
