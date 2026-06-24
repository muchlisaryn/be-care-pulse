# receive

**Method:** POST  
**Endpoint:** `/api/master/orders/{order}/receive`  
**Controller:** `App\Http\Controllers\Transaction\OrderController@receive`  
**Auth:** Bearer Token (wajib)

Terima order: alokasikan unit fisik terpilih → buat `order_item`, ubah status
order menjadi `dipinjam`, dan transisikan stok unit ke `dipinjam` (otomatis
mengurangi stok tersedia, lewat `InstrumentStock::transitionMany` agar log audit
tetap tercatat). Sekaligus memperbarui penerima & tanggal.

Saat diterima, order juga mendapat **`code_transaction`** unik (format
`INV` + tahun + bulan + hari + nomor urut order hari itu, mis. `INV20260619001`)
yang dipakai untuk barcode. Hanya digenerate sekali (tidak berubah bila sudah ada).

> Mencatat event timeline `diterima` di `order_events` dan **backfill** `code_transaction`
> ke event lama order ini (mis. `dibuat`), agar timeline tracking utuh per invoice.

Hanya bisa dilakukan bila status order `diajukan`.

Field `selections` adalah peta `key` requirement (lihat
[allocation](allocation.md)) → daftar `instrument_stock_id`. Jumlah unit per
requirement **harus** sama dengan `needed_qty`, tiap unit harus milik instrumen
yang benar, berstatus `tersedia`, dan tidak terpilih ganda — bila tidak,
respons 422 dan seluruh transaksi di-rollback.

## Request

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |
| Content-Type | application/json | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| borrowed_by | string | Tidak | Nama penerima / peminjam |
| order_date | date | Ya | Tanggal pinjam (format `YYYY-MM-DD`) |
| order_time | string | Tidak | Jam pinjam, format `HH:mm` (mis. `09:15`) |
| return_plan_date | date | Tidak | Rencana kembali |
| selections | object | Ya | Peta `key` requirement → array `instrument_stock_id` |

### Contoh Body
```json
{
  "borrowed_by": "Bidan Siti",
  "order_date": "2026-06-17",
  "order_time": "09:15",
  "return_plan_date": "2026-06-20",
  "selections": {
    "paket|18|SET PARTUS": [86],
    "satuan|1|": [1]
  }
}
```

## Response

### Success (200)
```json
{
  "status": true,
  "message": "Order berhasil diterima. Unit instrumen telah dialokasikan.",
  "data": {
    "id": 7,
    "code": "ORD-001",
    "status": "dipinjam",
    "borrowed_by": "Bidan Siti",
    "items": [
      {
        "id": 1,
        "instrument_stock_id": 86,
        "source": "paket",
        "package_name": "SET PARTUS",
        "is_returned": false
      }
    ]
  }
}
```

### Error (422) — validasi alokasi / status
```json
{
  "status": false,
  "message": "Jumlah unit untuk \"Gunting Epis\" harus 1, terisi 0."
}
```
