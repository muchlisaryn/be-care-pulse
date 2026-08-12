# index

**Method:** GET  
**Endpoint:** `/api/master/orders`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@index`  
**Auth:** Bearer Token (wajib)

> Daftar yang dikembalikan **hanya order milik akun yang login** (difilter `user_id = id user login`). Tiap akun hanya melihat order yang dibuatnya sendiri.
>
> Batch **Produksi CSSD** (internal, `room_id` null) **dikecualikan** — itu bukan order peminjaman; tempatnya di pipeline Cleaning.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| search | string | Tidak | Filter (like) berdasarkan `code` order, `borrowed_by` (nama peminjam), `medical_record_no` (no. RM pasien), `patient_name` (nama pasien), atau `name` ruangan |
| status | string | Tidak | Filter berdasarkan status order (`diajukan`, `disetujui`, `dipinjam`, `dikembalikan`, `dibatalkan`) |
| date_from | date (YYYY-MM-DD) | Tidak | Batas awal (inklusif) rentang `order_date` (tanggal pinjam) |
| date_to | date (YYYY-MM-DD) | Tidak | Batas akhir (inklusif) rentang `order_date` (tanggal pinjam) |
| page | integer | Tidak | Nomor halaman (default: 1) |

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data peminjaman berhasil diambil.",
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 1,
        "code": "ORD-001",
        "room_id": 1,
        "user_id": 1,
        "order_date": "2026-06-08",
        "return_plan_date": "2026-06-10",
        "return_actual_date": null,
        "status": "diajukan",
        "note": "Untuk operasi minor",
        "items_count": 2,
        "paket_items_count": 1,
        "satuan_items_count": 1,
        "requested_qty": "3",
        "item_count": 3,
        "created_by": "Administrator",
        "updated_by": "Administrator",
        "deleted_at": null,
        "deleted_by": null,
        "created_at": "2026-06-08T08:00:00.000000Z",
        "updated_at": "2026-06-08T08:00:00.000000Z",
        "room": { "id": 1, "code": "JWGL", "name": "poli umum" },
        "user": { "id": 1, "name": "Administrator", "username": "administrator" }
      }
    ],
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

### Angka jumlah instrumen

| Field | Isi |
|---|---|
| `item_count` | **Angka yang dipajang kolom "Instruments"**. PAKET dihitung per **SET**, SATUAN per unit — satu set berisi 10 instrumen tetap bernilai 1 |
| `requested_qty` | Jumlah `order_request_item.quantity` — sumber `item_count` |
| `items_count` | Jumlah **unit fisik** (`order_item`). Baru terisi setelah CSSD menerima order & mengalokasikan unit |
| `paket_items_count` / `satuan_items_count` | Jumlah unit fisik per asal — penanda jenis order di frontend |

`item_count` sengaja dibaca dari **baris permintaan**, bukan dari unit fisik: angkanya
sudah ada sejak order baru **diajukan**, jauh sebelum unit dialokasikan. Dulu kolom itu
membaca `items_count` sehingga order yang masih pengajuan selalu tampil `0` padahal
instrumennya jelas sudah diminta.

Order hasil **pinjam-alih** tidak punya baris permintaan (unitnya dioper dari order
lain tanpa order ulang ke CSSD). Untuk order seperti itu `item_count` dihitung dari unit
fisiknya: satuan per unit, paket per nomor label kemasan (satu label = satu bungkus =
satu set); paket tanpa label dihitung 1 set. Cukup dua query untuk seluruh halaman.
