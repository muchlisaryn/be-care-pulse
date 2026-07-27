# board

**Method:** GET  
**Endpoint:** `/api/master/monitoring/board`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@board`  
**Auth:** Bearer Token (wajib)

Papan monitor (display TV) untuk dipajang di layar gudang. Menampilkan daftar
**order aktif** (status `diajukan`, `disetujui`, atau `dipinjam`), diurutkan
berdasarkan `order_date` lalu `created_at`. Item pada tiap order dikelompokkan
per instrumen, lalu jumlahnya digabung menjadi `qty`.

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

Tidak ada query parameter. Mengembalikan seluruh baris order aktif (tanpa paginasi).

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
| status | Status order: `diajukan` / `disetujui` / `dipinjam` (penentu warna dot di papan) |
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
