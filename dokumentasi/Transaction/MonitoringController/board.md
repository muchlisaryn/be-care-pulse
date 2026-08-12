# board

**Method:** GET  
**Endpoint:** `/api/master/monitoring/board`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@board`  
**Auth:** Bearer Token (wajib)

Papan monitor (display TV) untuk dipajang di layar gudang. Menampilkan daftar
**order aktif**, diurutkan per tahap lalu `order_date` dan `id`. Item pada tiap
order dikelompokkan per instrumen, lalu jumlahnya digabung menjadi `qty`.

### Penentu "order aktif" — jejak waktu, bukan `status`

Kolom `status` ditulis ulang di banyak titik sepanjang alur CSSD dan bisa
tertinggal kalau ada satu proses yang gagal memperbaruinya; papan monitor tidak
boleh kehilangan pekerjaan berjalan hanya karena itu. Karena itu penyaringnya
dibaca dari kolom audit/jejak waktu:

| Dikeluarkan dari papan | Kondisi |
|---|---|
| Order dibatalkan | `canceled_at IS NOT NULL` |
| Order dihapus | `deleted_by IS NOT NULL` (global scope `active`, trait `HasAuditColumns`) |
| Order selesai | sudah diproses (`processed_at`) tapi tidak lagi memegang unit yang belum kembali (`order_item.is_returned`) |
| Order sumber pinjam-alih yang habis | tercakup aturan di atas — unitnya berpindah ke order peminjam baru, jadi order sumber tidak lagi memegang unit |

Order yang belum diterima CSSD (`processed_at IS NULL`) belum punya `order_item`
sama sekali — itu justru pekerjaan paling awal, jadi tetap ikut tampil. "Sudah
kembali" dibaca **per unit**, bukan dari `return_actual_date` di header, karena
pengembalian boleh dicicil: order dengan sebagian unit masih di ruangan tetap
tampil di papan.

### Nama peminjam = peminjam TERAKHIR

`borrowed_by` diambil dari **event timeline terbaru milik order itu**
(`order_events.borrowed_by`), bukan langsung dari kolom order. Pinjam-alih
memindahkan unit ke **order baru** milik peminjam baru (berbagi `code_transaction`)
dan mencatat event `dipindah` di sana — jadi baris papan untuk ruangan tujuan
menampilkan nama peminjam barunya, sedangkan nama peminjam awal tetap tinggal di
baris order sumbernya (selama ia masih memegang sisa unit).

Urutan cadangan bila order belum punya event (data lama): `order.borrowed_by` →
`order.created_by`.

Pemetaannya satu query untuk seluruh papan (bukan per baris) — total endpoint ini
**6 query, tetap** berapa pun jumlah ordernya.

Sumber angka `qty` juga tidak lagi ditentukan status: begitu unit fisiknya
dialokasikan (ada `order_item` yang belum kembali), itulah yang dihitung; sebelum
itu papan memakai baris permintaan (`order_request_item`).

Kolom `status` tetap **dikirim** di response & dipakai mengurutkan baris — hanya
sebagai keterangan tahap di layar, bukan lagi penentu order mana yang tampil.

**Satuan `qty` mengikuti `jenis`:** baris `Paket` dihitung per **SET**, baris
`Satuan` per **unit** fisik. Jumlah set tidak bisa disimpulkan dari unit fisik
(1 set berisi 10 instrumen tetap 1 set), jadi untuk baris paket `qty` selalu
dibaca dari `order_request_item.quantity` — termasuk pada order yang sudah dikemas,
yang unit fisiknya dipakai hanya untuk menentukan paket mana yang masih aktif.

Dikonsumsi oleh halaman frontend fullscreen `/monitor` (auto-refresh 20 detik).

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| room_id | integer | Tidak | Saring ke satu ruangan. Dipakai papan per-ruangan `/monitor/{ruangan_id}`; tanpa ini papan itu menarik seluruh order aktif rumah sakit tiap 20 detik lalu membuang hampir semuanya di browser |

Tanpa `room_id`: mengembalikan seluruh baris order aktif (tanpa paginasi) — dipakai
papan gabungan `/monitor/all`.

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Data papan monitoring berhasil diambil.",
  "data": [
    {
      "status": "dipinjam",
      "date": "10.06.2026",
      "time": "15:00",
      "reservation": "ORD-002",
      "room_code": "RMYS",
      "room_name": "Poli gigi",
      "instrument_code": "NRQU",
      "instrument_name": "tensi",
      "qty": 3,
      "unit": "PCS"
    }
  ]
}
```

Keterangan field:

| Field | Keterangan |
|-------|------------|
| status | Tahap order (`diajukan`, `pencucian`, `pengemasan`, `selesai`, `sterilisasi`, `steril`, `digudang`, `dipinjam`) — keterangan tahap & penentu warna dot di papan, bukan penyaring baris |
| date | Tanggal order (`order_date`), format `DD.MM.YYYY` |
| time | Jam pembuatan order (`created_at`), format `HH:MM` |
| reservation | Kode order (`ORD-NNN`) |
| room_code / room_name | Kode & nama ruangan tujuan |
| instrument_code / instrument_name | Kode & nama instrumen |
| qty | Jumlah pada order tersebut — **set** bila `jenis` = `Paket`, **unit** bila `Satuan` |
| unit | Satuan, saat ini selalu `PCS` |

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

---

## Catatan QTY paket

Baris `jenis: "Paket"` selalu berisi jumlah **SET**, tidak pernah jumlah unit fisik
(1 set berisi 10 instrumen tetap dihitung 1 set). Urutan sumbernya:

1. **Penanda set unit** — `production_item.production_id|package_no` dari unit yang
   masih dipinjam; seluruh unit dalam satu set berbagi `package_no` yang sama.
   Ini yang tetap benar untuk order **pinjam-alih** (tidak punya `order_request_item`)
   maupun pengembalian sebagian.
2. Cadangan: jumlah pada `order_request_item.quantity`.
3. Jalan terakhir: `1`.

Baris `jenis: "Satuan"` berisi jumlah unit fisik. Frontend menempelkan satuannya:
`set` untuk Paket, `unit` untuk Satuan.
