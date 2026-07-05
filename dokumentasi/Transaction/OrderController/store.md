# store

**Method:** POST  
**Endpoint:** `/api/master/orders`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@store`  
**Auth:** Bearer Token (wajib)

Membuat order peminjaman baru (header + **daftar permintaan jumlah**). Status awal selalu `diajukan`.
Peminjam hanya menentukan jumlah; unit fisik (`order_item`) **belum dibuat** di tahap ini —
akan di-generate otomatis saat CSSD menerima pesanan. Semua baris permintaan disimpan ke
`order_request_item` dalam satu transaksi DB.

> Mencatat event timeline `dibuat` di `order_events` (tampil di tracking lewat `scan`).
> `code_transaction`-nya masih null sampai order diterima (di-backfill di `receive`).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| room_id | integer | Ya | Ruangan tujuan, harus ada di tabel rooms |
| user_id | integer | Tidak | Petugas/penanggung jawab, harus ada di tabel users |
| borrowed_by | string | Tidak | Nama peminjam (teks bebas), maksimal 255 karakter |
| medical_record_no | string | Ya | No. rekam medis pasien, maksimal 255 karakter |
| patient_name | string | Ya | Nama pasien, maksimal 255 karakter |
| order_date | date | Ya | Tanggal pengajuan/pinjam (format `YYYY-MM-DD`) |
| order_time | string | Ya | Jam pinjam, format `H:i` (mis. `14:30`) |
| return_plan_date | date | Tidak | Rencana tanggal kembali (satu-satunya field jadwal yang opsional) |
| note | string | Tidak | Catatan/keperluan |
| items | array | Ya | Minimal 1 baris permintaan |
| items[].type | string | Ya | Jenis permintaan: `satuan` atau `paket` |
| items[].quantity | integer | Ya | Jumlah yang diminta (unit untuk `satuan`, jumlah set untuk `paket`), minimal 1 |
| items[].instrument_id | integer | Wajib jika `type = satuan` | Jenis instrumen yang diminta, harus ada di `instruments` |
| items[].instrument_catalog_id | integer | Wajib jika `type = paket` | Katalog paket yang diminta, harus ada di `instrument_catalogs` |
| items[].package_name | string | Tidak | Snapshot nama paket (hanya untuk `type = paket`), maksimal 255 karakter |

### Contoh Body
```json
{
  "room_id": 1,
  "user_id": 1,
  "borrowed_by": "dr. Andi",
  "medical_record_no": "00-12-34-56",
  "patient_name": "Budi Santoso",
  "order_date": "2026-06-08",
  "order_time": "14:30",
  "return_plan_date": "2026-06-10",
  "note": "Untuk operasi minor",
  "items": [
    { "type": "satuan", "instrument_id": 1, "quantity": 3 },
    { "type": "paket", "instrument_catalog_id": 2, "package_name": "Paket Bedah Minor", "quantity": 2 }
  ]
}
```

## Response

### Success (201)
```json
{
  "status": true,
  "message": "Peminjaman berhasil dibuat.",
  "data": {
    "id": 1,
    "code": "ORD-001",
    "room_id": 1,
    "user_id": 1,
    "borrowed_by": "dr. Andi",
    "medical_record_no": "00-12-34-56",
    "patient_name": "Budi Santoso",
    "order_date": "2026-06-08",
    "order_time": "14:30:00",
    "return_plan_date": "2026-06-10",
    "return_actual_date": null,
    "status": "diajukan",
    "note": "Untuk operasi minor",
    "room": { "id": 1, "code": "JWGL", "name": "poli umum" },
    "user": { "id": 1, "name": "Administrator", "username": "administrator" },
    "request_items": [
      {
        "id": 1,
        "order_id": 1,
        "type": "satuan",
        "instrument_id": 1,
        "instrument_catalog_id": null,
        "package_name": null,
        "quantity": 3,
        "instrument": { "id": 1, "code": "ZHVQ", "name": "stetoskop" },
        "catalog": null
      },
      {
        "id": 2,
        "order_id": 1,
        "type": "paket",
        "instrument_id": null,
        "instrument_catalog_id": 2,
        "package_name": "Paket Bedah Minor",
        "quantity": 2,
        "instrument": null,
        "catalog": { "id": 2, "code": "GV", "name": "GV SET" }
      }
    ],
    "items": []
  }
}
```

### Error (422)
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "room_id": ["The room id field is required."],
    "items": ["The items field is required."]
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
