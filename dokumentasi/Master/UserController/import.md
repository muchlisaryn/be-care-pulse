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
| rows[].email | string | Tidak | Boleh kosong (`users.email` nullable). Bila diisi harus format email valid & unik di antara user aktif |
| rows[].no_telephone | string | Tidak | Maks 20 karakter |
| rows[].authority_id | integer | Tidak | Bila diisi, menang atas `authority` |
| rows[].authority | string | Tidak | NAMA otoritas. Dicocokkan **tanpa peka huruf besar/kecil** dan spasi ganda dirapatkan — `administrator`, `Administrator`, dan `Perawat  CSSD` semuanya cocok |
| rows[].password | string | Tidak | Bila kosong, dipakai `default_password` |
| default_password | string | Ya | Min 8 karakter. Password untuk baris tanpa password sendiri — sengaja **tidak** ada default tersembunyi di server |

*) wajib pada level baris: bila kosong, baris itu masuk `errors`, bukan menggagalkan request.

`email` **opsional** — banyak petugas tidak punya email kantor & berkas import sering
tidak memuat kolom itu. Baris tanpa email tetap dibuat dengan `email = NULL`. Indeks
unique kolom ini dipertahankan; MySQL mengizinkan banyak baris NULL, jadi email yang
diisi tetap tidak boleh kembar. Lihat migration `make_email_nullable_on_users_table`.

Otoritas wajib terisi hasil akhirnya: `authority_id` langsung, atau `authority` yang
namanya cocok dengan data otoritas. Bila keduanya gagal, barisnya ditolak.

Berkas import dari frontend mengirim **nama**, bukan id. Alasannya keamanan: salah ketik
nama tidak akan cocok dengan apa pun sehingga barisnya ditolak, sedangkan salah ketik id
bisa mendarat di id sah yang lain dan diam-diam memberi user hak akses yang keliru —
`Rule::in` tidak bisa membedakannya. `authority_id` tetap diterima untuk pemanggil lain
yang memang sudah memegang id yang benar (mis. migrasi data antar sistem).

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
      { "row": 4, "username": "imp_tiga", "message": "Kolom name wajib diisi." },
      { "row": 5, "username": "imp_satu", "message": "Username \"imp_satu\" sudah dipakai user lain." },
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
