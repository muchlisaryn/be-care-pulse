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

**Di sinilah unit diklaim.** Menerima order tidak mengalokasikan apa pun (lihat
[acceptDistribution](acceptDistribution.md)), jadi endpoint ini yang memilih unit,
menulis `order_item`, mengisi `instrument_storages.order_id`, lalu mengeluarkannya
dari gudang.

- `stock_ids` **dikirim** → dipakai pilihan petugas. Jumlah unit terpilih harus sama
  persis dengan kebutuhan tiap baris permintaan (instrumen + bentuk simpan). Unit yang
  sempat direservasi tapi tidak jadi dipilih dikembalikan ke pool, unit terpilih
  direservasi ke order ini, lalu `order_item` ditulis ulang sesuai pilihan.
- `stock_ids` **kosong** → sistem memilihkan sendiri secara **FEFO** dari pool bebas
  (`order_id IS NULL`, `expiry_date >= hari ini`). Bila order sudah punya alokasi dari
  sebelumnya (order yang diterima sebelum perubahan ini), alokasi itu dipakai apa adanya.

### Transaksi & konkurensi
Seluruh efek (reservasi ulang, unit keluar gudang, unit → `dipinjam`, order →
`dipinjam`, event) berjalan dalam satu transaksi (`DB::transaction`). Bila ada satu
langkah gagal — misalnya unit pilihan sudah diambil order lain — semuanya di-rollback
sehingga order tidak pernah setengah terdistribusi.

Baris order dikunci (`SELECT ... FOR UPDATE`) di dalam transaksi lalu statusnya
diperiksa ulang; baris gudang kandidat juga dikunci sebelum direservasi. Dua permintaan
distribusi yang datang bersamaan (klik ganda / dua petugas) dijalankan berurutan —
yang kedua menemukan status sudah bukan `digudang` dan ditolak 422, bukan mengeluarkan
unit dua kali.

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
```json
{ "status": false, "message": "Order ini sudah didistribusikan atau statusnya berubah. Muat ulang halaman." }
```
