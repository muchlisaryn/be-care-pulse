# borrowedSummary

**Method:** GET  
**Endpoint:** `/api/master/monitoring/borrowed-summary`  
**Controller:** `App\Http\Controllers\Transaction\MonitoringController@borrowedSummary`  
**Auth:** Bearer Token (wajib)

Angka **ketiga kartu statistik** di halaman Tracking Order (`/cssd/tracking-order`):
Instrumen Sedang Dipinjam, Order Aktif, dan Instrumen Terlambat — cukup satu
permintaan, dihitung di server. Sebelumnya "Order Aktif" & "Instrumen Terlambat"
dihitung frontend dari daftar ruangan yang dimuat penuh beserta seluruh unitnya.

## Aturan hitung

Sengaja **disamakan** dengan kartu "Instrumen di Gudang Steril"
([`StorageController@summary`](../StorageController/summary.md)):

| Jenis baris | Dihitung sebagai |
|---|---|
| `paket` | **1 per SET** — satu nomor label kemasan (`packaging_item.barcode_no`) berbeda di dalam satu paket pada satu order = satu bungkus = satu set. Berapa pun jumlah instrumen di dalamnya tetap bernilai 1. |
| `satuan` | **1 per UNIT** fisik |

Bila seluruh unit sebuah paket belum punya nomor label (data lama sebelum tahap
packaging), jumlah setnya diambil dari baris permintaan (`order_request_item.quantity`),
lalu jatuh ke `1` — tidak pernah dihitung per unit, supaya isi set tidak terhitung satu
per satu.

> **Endpointnya sengaja DIPISAH dari gudang steril.** Datanya memang beda (order
> berstatus `dipinjam` vs pool gudang steril yang belum direservasi); hanya *cara
> menghitungnya* yang disamakan. Jangan menggabungkan keduanya jadi satu endpoint.

Dipisah juga dari [`rooms`](rooms.md) karena kartu statistik harus mencakup **seluruh**
order dipinjam, bukan hanya ruangan yang kebetulan ada di halaman pertama daftar ruangan.

**Basis baris:** `order_item` dengan `is_returned = false` milik order berstatus
`dipinjam`. Order `digudang` (sudah diterima, belum diantar ke ruangan) **tidak** ikut —
barangnya belum berada di ruangan.

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
  "message": "Ringkasan instrumen dipinjam berhasil diambil.",
  "data": {
    "borrowed": 12,
    "sets": 2,
    "units": 10,
    "orders": 3,
    "overdue": 4
  }
}
```

| Field | Type | Keterangan |
|-------|------|------------|
| borrowed | integer | `sets + units` — kartu "Instrumen Sedang Dipinjam" |
| sets | integer | Jumlah SET paket yang sedang dipinjam |
| units | integer | Jumlah UNIT instrumen satuan yang sedang dipinjam |
| orders | integer | Kartu "Order Aktif" — order yang masih punya unit belum dikembalikan |
| overdue | integer | Kartu "Instrumen Terlambat" — bagian dari `borrowed` yang rencana kembalinya sudah lewat (aturan set/unit yang sama) |

Contoh di atas: peminjaman 2 set paket + 10 instrumen satuan → kartu menampilkan **12**.

### Error (401)
```json
{
  "status": false,
  "message": "Unauthenticated. Silakan login terlebih dahulu."
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
