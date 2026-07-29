# UserController@import

**Controller:** App\Http\Controllers\Master\UserController  
**Method:** POST  
**Endpoint:** /api/master/users/import  
**Auth:** Bearer Token (wajib)

Import user **per batch**. Berkas TIDAK diunggah utuh: klien mem-parse berkas Excel/CSV
di browser lalu memanggil endpoint ini berkali-kali, maksimum **200 baris per panggilan**,
sambil menampilkan progres. Dengan begitu berkas 1000+ baris tidak pernah menjadi satu
request panjang yang rawan timeout / kehabisan memori.

Satu baris gagal **tidak** membatalkan baris lain: tiap baris divalidasi & disimpan
sendiri. Baris yang gagal dikembalikan di `errors` lengkap dengan nomor barisnya di
berkas asal, supaya bisa diperbaiki lalu diimport ulang.

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| rows | array | Ya | Baris user, min 1 maks **200** |
| rows[].row | integer | Tidak | Nomor baris di berkas asal — dipakai pada laporan `errors` |
| rows[].name | string | Ya* | Nama user |
| rows[].username | string | Ya* | Unik di antara user aktif |
| rows[].email | string | Ya* | Unik di antara user aktif |
| rows[].no_telephone | string | Tidak | Maks 20 karakter |
| rows[].authority_id | integer | Tidak | Bila diisi, menang atas `authority` |
| rows[].authority | string | Tidak | NAMA otoritas, dicocokkan **tanpa peka huruf besar/kecil** |
| rows[].password | string | Tidak | Bila kosong, dipakai `default_password` |
| default_password | string | Ya | Min 8 karakter. Password untuk baris tanpa password sendiri — sengaja **tidak** ada default tersembunyi di server |

*) wajib pada level baris: bila kosong, baris itu masuk `errors`, bukan menggagalkan request.

Otoritas wajib terisi hasil akhirnya: `authority_id` langsung, atau `authority` yang
namanya cocok dengan data otoritas. Bila keduanya gagal, barisnya ditolak.

### Response

#### Success (200)
```json
{
  "status": true,
  "message": "Batch import selesai.",
  "data": {
    "processed": 5,
    "created": 2,
    "failed": 3,
    "errors": [
      { "row": 4, "username": "imp_tiga", "message": "The name field is required." },
      { "row": 5, "username": "imp_satu", "message": "The username has already been taken." },
      { "row": 6, "username": "imp_lima", "message": "Kolom authority kosong atau nama otoritasnya tidak dikenal." }
    ]
  }
}
```

#### Error (422)
Dikembalikan bila payload batch-nya sendiri tidak valid — mis. `rows` lebih dari 200
atau `default_password` kurang dari 8 karakter.
```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "rows": ["The rows field must not have more than 200 items."] }
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

---

## Catatan Export

Tidak ada endpoint export tersendiri. Halaman Master User menyusun berkas `.xlsx` di
browser dari [index](index.md), ditarik halaman demi halaman memakai
`?per_page=` (dibatasi maksimum **200**) agar tidak ada satu response raksasa.
Kolom `password` tidak ikut diekspor.
