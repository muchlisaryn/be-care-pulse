# bulkStore

**Method:** POST  
**Endpoint:** `/api/master/orders/bulk`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@bulkStore`  
**Auth:** Bearer Token (wajib)

Membuat order peminjaman untuk **beberapa pasien sekaligus** dalam satu pengiriman form.
Data peminjaman (peminjam, ruangan, jadwal, catatan) dipakai bersama; tiap pasien —
dikelompokkan per **No. RM + nama pasien** — punya daftar permintaannya sendiri dan menjadi
**satu record order tersendiri**, persis seperti dibuat satu per satu lewat [store](store.md).

> **Satu transaksi.** Seluruh order dibuat dalam satu transaksi DB: bila satu pasien gagal
> (mis. stok steril kurang), **tidak ada** order yang tersimpan — petugas tidak perlu menebak
> pasien mana yang sudah masuk. Tiap order tetap mencatat event timeline `dibuat` dan
> disiarkan satu per satu lewat `OrderSubmitted`, sama seperti order tunggal.

> Route ini dideklarasikan **sebelum** `apiResource('orders')` agar `bulk` tidak tertangkap
> sebagai parameter `{order}`.

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
| borrowed_by | string | Tidak | Nama peminjam (dipilih dari Master User di form) |
| order_date | date | Ya | Tanggal pinjam (`YYYY-MM-DD`) |
| order_time | string | Ya | Jam pinjam, format `H:i` |
| return_plan_date | date | Tidak | Rencana tanggal kembali |
| note | string | Tidak | Catatan/keperluan — berlaku untuk semua order yang dibuat |
| patients | array | Ya | Minimal 1 pasien; **tiap elemen = satu record order** |
| patients.*.medical_record_no | string | Kondisional | Wajib bila layanan ruangan `rawat_inap` |
| patients.*.patient_name | string | Kondisional | Wajib bila layanan ruangan `rawat_inap` |
| patients.*.items | array | Ya | Minimal 1 baris permintaan pasien tersebut |
| patients.*.items.*.type | string | Ya | `satuan` atau `paket` |
| patients.*.items.*.quantity | integer | Ya | Minimal 1 (satuan → unit, paket → set) |
| patients.*.items.*.instrument_id | integer | Kondisional | Wajib bila `type = satuan` |
| patients.*.items.*.instrument_catalog_id | integer | Kondisional | Wajib bila `type = paket` |
| patients.*.items.*.package_name | string | Tidak | Nama paket (untuk `type = paket`) |

`user_id` **tidak** diterima dari request — selalu akun yang sedang login (sama dengan
[store](store.md)).

### Contoh body
```json
{
  "room_id": 1,
  "borrowed_by": "Ns. Rina",
  "order_date": "2026-08-11",
  "order_time": "08:00",
  "note": "Operasi pagi",
  "patients": [
    {
      "medical_record_no": "000111",
      "patient_name": "PASIEN SATU",
      "items": [{ "type": "satuan", "instrument_id": 1, "quantity": 2 }]
    },
    {
      "medical_record_no": "000222",
      "patient_name": "PASIEN DUA",
      "items": [
        { "type": "paket", "instrument_catalog_id": 3, "package_name": "SET PARTUS", "quantity": 1 }
      ]
    }
  ]
}
```

## Aturan validasi khusus

1. **No. RM tidak boleh dobel antar pasien** → 422. Satu pasien = satu order; permintaan
   pasien yang sama harus digabung dalam satu elemen `patients`.
2. **Stok steril dicek sekali untuk gabungan seluruh pasien**, bukan per pasien — semuanya
   diambil dari kolam stok yang sama, jadi pengecekan per pasien bisa meloloskan stok yang
   sama berkali-kali. Aturan & pesan kekurangan stoknya identik dengan [store](store.md).

## Response

### Success (201)
```json
{
  "status": true,
  "message": "2 order peminjaman berhasil dibuat.",
  "data": [
    { "id": 12, "code": "ORD-012", "medical_record_no": "000111", "patient_name": "PASIEN SATU", "status": "diajukan" },
    { "id": 13, "code": "ORD-013", "medical_record_no": "000222", "patient_name": "PASIEN DUA", "status": "diajukan" }
  ]
}
```
Tiap elemen adalah order lengkap beserta relasi detailnya (sama dengan response `store`).

### Error (422) — RM dobel
```json
{
  "status": false,
  "message": "No. Rekam Medis tidak boleh sama antar pasien — gabungkan permintaannya dalam satu pasien."
}
```

### Error (422) — stok steril kurang
```json
{
  "status": false,
  "message": "Stok steril \"Gunting\" tidak mencukupi: diminta 5 unit, tersisa 2 unit."
}
```
Tidak ada order yang tersimpan.

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
