# OrderController@distribute

**Controller:** App\Http\Controllers\Transaction\OrderController
**Method:** POST
**Endpoint:** /api/master/orders/{order}/distribute
**Auth:** Bearer Token (wajib)

Tahap 6 — Distribusikan order steril ke unit pelayanan (Double Verification).
No RM & Nama Pasien **tidak** diinput di sini — sudah diisi saat pembuatan order
dan dibawa apa adanya ke event distribusi. Efek:
- Unit keluar gudang (storage `keluar`).
- Unit → status `dipinjam` (Terdistribusi / Digunakan).
- Order → status `dipinjam`, event timeline `terdistribusi`.
- Riwayat mengunci **full traceability loop** (alat → batch sterilisasi → RM pasien).

### Path Parameter
| Parameter | Type | Keterangan |
|-----------|------|------------|
| order | integer | ID order (status `digudang`) |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| recipient | string | Ya | Ruangan / petugas penerima (hasil scan — double verification) |
| note | string | Tidak | Catatan |
| stock_ids | array\<integer\> | Tidak | Unit (`instrument_stock_id`) yang dipilih petugas di modal Distribusikan — lihat [distributionOptions](distributionOptions.md). Bila dikosongkan, dipakai alokasi FEFO otomatis dari saat order diterima. |

Bila `stock_ids` dikirim, jumlah unit terpilih **harus sama persis** dengan kebutuhan
tiap baris permintaan (instrumen + bentuk simpan). Unit yang tadinya dialokasikan
otomatis tapi tidak jadi dipilih dikembalikan ke pool stok produksi, unit terpilih
direservasi ke order ini, lalu `order_item` ditulis ulang sesuai pilihan.

### Response — Success (200)
```json
{
  "status": true,
  "message": "Alat steril berhasil didistribusikan.",
  "data": {
    "id": 10,
    "status": "dipinjam",
    "distributed_to": "OK 1",
    "medical_record_no": "RM-00123",
    "patient_name": "Budi",
    "distributed_at": "2026-06-28T09:30:00.000000Z"
  }
}
```

### Error (422)
```json
{ "status": false, "message": "Order ini belum berada di gudang steril / tidak siap didistribusikan." }
```
```json
{ "status": false, "message": "Pilihan unit \"Klem Lurus\" (satuan) harus 3 unit, terpilih 2." }
```
```json
{ "status": false, "message": "Ada unit terpilih yang tidak tersedia lagi di gudang steril. Muat ulang daftar unit." }
```
