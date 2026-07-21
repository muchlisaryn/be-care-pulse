# CleaningController@cancelWashing

**Controller:** App\Http\Controllers\Transaction\CleaningController
**Method:** DELETE
**Endpoint:** /api/master/cleaning/{washing}/cancel
**Auth:** Bearer Token (wajib)

Membatalkan batch cleaning (record `washing`) yang **belum diproses** — yaitu
belum ada satu pun parameter pencucian yang diisi operator dan belum selesai.

**Perilaku:**
- Record `washing` **dihapus permanen** (`forceDelete`) — pembatalan **tidak
  menyisakan riwayat** apa pun di database (tidak lagi ditandai `batal`), sehingga
  batch hilang total dari daftar & riwayat cleaning.
- Batch produksi asal juga di-`forceDelete` (slot nomor PRD kosong kembali).
- Seluruh unit yang tadinya dipotong dikembalikan ke stok **semula** (`tersedia`)
  lewat `InstrumentStock::transitionMany` (riwayat status tercatat).
- Mencatat event pipeline `batal` pada tahap `washing` & `production` (audit
  terpisah — `pipeline_events` tetap tersimpan meski record washing dihapus).
- Respons **tidak** mengembalikan data batch (hanya pesan), karena record sudah dihapus.

**Ditolak (422) bila:**
- Batch sudah **selesai** (`status = selesai`) atau sudah **dibatalkan** (`batal`).
- Batch **sudah diproses** — salah satu parameter pencucian (`washer_machine_id`,
  `operator`, `temperature`, `washed_at`, `duration_minutes`, `detergent_type`)
  sudah terisi. Gunakan **"Tandai Gagal"** bila perlu diulang.

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
  "message": "Pencucian dibatalkan, stok dikembalikan & record dihapus.",
  "data": null
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
