# InstrumentCatalogController — show

**Method:** GET
**Endpoint:** /api/master/instrument-catalogs/{id}
**Auth:** Bearer Token (wajib)

Mengambil detail katalog beserta `items` (rincian + instrumen + kondisi standar) dan `stocks` (unit fisik + kondisi).

### Response — Success (200)
```json
{
  "status": true,
  "message": "Detail katalog instrumen berhasil diambil.",
  "data": {
    "id": 1,
    "code": "TJEO",
    "name": "Set Bedah Minor",
    "type": "paket",
    "items": [
      {
        "id": 1,
        "instrument_id": 1,
        "quantity": 2,
        "standard_condition_id": 1,
        "instrument": { "id": 1, "code": "QCVN", "name": "Gunting Bedah" },
        "standard_condition": { "id": 1, "name": "Baik" }
      }
    ],
    "stocks": [
      { "id": 1, "code": "TJEO-001", "is_available": true, "condition": { "id": 1, "name": "Baik" } }
    ]
  }
}
```

### Error (404)
```json
{ "status": false, "message": "Data tidak ditemukan." }
```
