# CleaningController@cancelWashing

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** DELETE
**Endpoint:** /api/master/cleaning/{washing}/cancel
**Auth:** Bearer Token (wajib)

Membatalkan batch cleaning (record `washing`) yang **belum diproses** — yaitu
belum ada satu pun parameter pencucian yang diisi operator dan belum selesai.

**Perilaku:**
- Batch **tidak dihapus** — status `washing` menjadi `batal` dan **tetap tampil
  sebagai riwayat cleaning**, mencatat `canceled_by` & `canceled_at`.
- Seluruh unit yang tadinya dipotong dikembalikan ke stok **semula** (`tersedia`)
  lewat `InstrumentStock::transitionMany` (riwayat status tercatat).
- Mencatat event pipeline `batal` pada tahap `washing` & `production`.
- Respons mengembalikan data batch (hasil `transform`) dengan `stage_status = batal`
  dan blok `washing` berisi jejak `started_by/at`, `completed_by/at`, `canceled_by/at`.

**Ditolak (422) bila:**
- Batch sudah **selesai** (`status = selesai`) atau sudah **dibatalkan** (`batal`).
- Batch **sudah diproses** — salah satu parameter pencucian (`machine_no`,
  `operator`, `temperature`, `washed_at`, `duration_minutes`, `detergent_type`,
  `washer_machine_id`) sudah terisi. Gunakan **"Tandai Gagal"** bila perlu diulang.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| washing | integer | ID record washing (batch cleaning) |

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Pencucian dibatalkan & stok dikembalikan ke semula.",
  "data": {
    "id": 12,
    "code": "WSH-012",
    "stage_status": "batal",
    "washing": {
      "status": "batal",
      "started_by": "Operator A",
      "started_at": "2026-07-05T02:10:00.000000Z",
      "completed_by": null,
      "completed_at": null,
      "canceled_by": "Operator B",
      "canceled_at": "2026-07-05T03:00:00.000000Z"
    }
  }
}
```

#### Error (422) — sudah diproses / selesai
```json
{
  "status": false,
  "message": "Pencucian sudah diproses. Tandai gagal bila perlu diulang, tidak bisa dibatalkan."
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
