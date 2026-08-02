# roomsSummary

**Method:** GET  
**Endpoint:** `/api/master/monitoring/rooms-summary`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@roomsSummary`  
**Auth:** Bearer Token (wajib)

Angka kartu **"Distribusi per Ruangan"** di halaman Tracking Order: nama ruangan +
jumlah instrumen yang sedang dipinjam & yang terlambat. **Tanpa** daftar instrumennya.

Dipisah dari [`rooms`](rooms.md) yang memuat seluruh unit beserta relasi instrumen,
kondisi, dan baris permintaan tiap ruangan — payload itu hanya dibutuhkan saat daftar
order dibuka atau kartu ruangan diklik, bukan untuk memajang angka di kartu. Di sini
yang dibaca hanya baris `order_item` + kolom seperlunya, jadi jauh lebih ringan.

**Aturan hitung** sama dengan kartu statistik & gudang steril: paket dihitung per SET
(satu nomor label kemasan = satu bungkus = satu set), instrumen satuan per UNIT fisik.
Karena itu `borrowed_count` tidak sama dengan jumlah baris unit.

**Terlambat** = masih dipinjam tapi `return_plan_date` sudah lewat — turunan, bukan
status di database.

Hanya ruangan yang sedang meminjam yang dikembalikan, urut dari yang terbanyak.

> Sengaja **tidak dipaginasi**: ini agregat (satu baris per ruangan), sama seperti
> endpoint ringkasan lain ([`counts`](counts.md), [`borrowed-summary`](borrowedSummary.md)) —
> frontend memang butuh seluruhnya sekaligus untuk kartu & modal "semua ruangan".

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

Tanpa query parameter.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Ringkasan distribusi per ruangan berhasil diambil.",
  "data": [
    {
      "id": 2,
      "code": "RMYS",
      "name": "Poli gigi",
      "borrowed_count": 5,
      "overdue_count": 1
    },
    {
      "id": 1,
      "code": "JWGL",
      "name": "poli umum",
      "borrowed_count": 2,
      "overdue_count": 0
    }
  ]
}
```

| Field | Type | Keterangan |
|-------|------|------------|
| borrowed_count | integer | Set paket + unit satuan yang sedang dipinjam ruangan itu |
| overdue_count | integer | Bagian dari `borrowed_count` yang rencana kembalinya sudah lewat |

Kartu menampilkan `borrowed_count − overdue_count` sebagai "dipinjam" dan
`overdue_count` sebagai "terlambat", jadi keduanya tidak pernah timpang.

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated. Silakan login terlebih dahulu."
}
```
