# TransaksiImportController

**Controller:** App\Http\Controllers\Nafsul\TransaksiImportController
**Base URL:** /api/nafsul/transaksi/import

Impor transaksi iuran dari file Excel. Dipakai tombol **Import Excel** di halaman
`/nafsul/transaksi`; frontend yang membaca filenya, endpoint ini menerima hasil
bacaannya sebagai JSON.

---

## Satu file, dua sheet data

| Sheet      | Isi                     | Dikirim sebagai |
| ---------- | ----------------------- | --------------- |
| `Kuitansi` | satu baris per kuitansi | `headers`       |
| `Rincian`  | satu baris per iuran    | `rows`          |

Keduanya dihubungkan kolom **`Kode Kuitansi`**. Kode itu hanya berlaku di dalam
file — dipakai untuk merangkai, tidak disimpan. Nomor kuitansi yang sebenarnya
tetap dibuat server (`TransactionHeader::generateNumber()`, format YYMMDD + urut
harian), sama seperti kuitansi yang dibuat lewat form.

Bentuk dua sheet dipilih daripada satu sheet yang mengulang kolom kuitansi di
tiap baris rincian: pengulangan membuat satu kuitansi bisa menyebut dua nilai
"Dibayar" yang berbeda, dan tidak ada cara benar memilih salah satunya. Dengan
sheet terpisah keadaan itu tidak bisa terjadi.

### Contoh

Sheet **Kuitansi**

| Kode Kuitansi | Tanggal    | Jenis   | Dibayar | Metode | Potongan Anggota | Potongan Ketua |
| ------------- | ---------- | ------- | ------- | ------ | ---------------- | -------------- |
| K1            | 2026-08-23 | pribadi | 150000  | cash   | 0                | 0              |
| K2            | 2026-08-23 | pribadi | 25000   | cash   | 0                | 0              |

Sheet **Rincian**

| Kode Kuitansi | No. Anggota | Kode Tarif | Periode | Nominal | Diskon |
| ------------- | ----------- | ---------- | ------- | ------- | ------ |
| K1            | 26082101    | IUR01      | 01/2026 |         | 0      |
| K1            | 26082101    | IUR01      | 02/2026 |         | 0      |
| K1            | 26082101    | IUR01      | 03/2026 |         | 0      |
| K2            | 26082102    | IUR03      |         | 25000   | 0      |

→ 2 kuitansi: satu berisi 3 rincian, satu berisi 1 rincian sekali bayar.

---

## import

**Method:** POST · **Endpoint:** /api/nafsul/transaksi/import · **Auth:** Bearer Token (wajib)

| Parameter   | Type  | Required | Keterangan                                  |
| ----------- | ----- | -------- | ------------------------------------------- |
| `rows`      | array | Ya       | Baris sheet Rincian. 1–200 baris            |
| `headers`   | array | Tidak    | Baris sheet Kuitansi. Maks 200              |

`headers` dikirim **utuh di setiap permintaan**, tidak ikut dipecah bersama
rincian: tiap batch butuh induk milik barisnya, dan jumlahnya jauh lebih sedikit.

### Kolom `rows[]`

| Field           | Required | Keterangan                                                   |
| --------------- | -------- | ------------------------------------------------------------ |
| `baris`         | Tidak    | Nomor baris di file Excel, dipakai pesan galat                |
| `kode_kuitansi` | Ya       | Harus ada di `headers`                                        |
| `no_anggota`    | Ya       | Dicocokkan ke `members.member_number`                         |
| `kode_tarif`    | Ya       | Dicocokkan ke `rates.code`                                    |
| `periode`       | Tergantung | "MM/YYYY". Wajib untuk tarif `recurring`, **harus kosong** untuk `one_time` |
| `nominal`       | Tidak    | Kosong → dipakai `rates.price`                                |
| `diskon`        | Tidak    | Bawaan 0                                                      |

Anggota & tarif dirujuk lewat kode yang tampil di aplikasi, bukan id database —
id tidak pernah muncul di layar mana pun, jadi tidak ada cara wajar mengisinya.

### Kolom `headers[]`

| Field              | Required | Keterangan                          |
| ------------------ | -------- | ----------------------------------- |
| `kode_kuitansi`    | Ya       | Kunci penghubung ke `rows`          |
| `tanggal`          | Ya       | Tanggal uang DITERIMA, `YYYY-MM-DD` |
| `jenis`            | Ya       | `kelompok` / `pribadi`              |
| `dibayar`          | Ya       | Jumlah yang diterima                |
| `metode`           | Ya       | `cash` / `transfer` / `other`       |
| `potongan_anggota` | Tidak    | Bawaan 0                            |
| `potongan_ketua`   | Tidak    | **Persen** (10 = 10%), 0–100. Bawaan 0, dinolkan bila `pribadi` |

`metode` & `jenis` dinormalkan ke huruf kecil sebelum divalidasi — "Cash" dan
"Pribadi" diterima apa adanya.

### Kolom wajib

Sheet **Kuitansi**: `Kode Kuitansi`, `Tanggal`, `Jenis`, `Dibayar`, `Metode`.
Sheet **Rincian**: `Kode Kuitansi`, `No. Anggota`, `Kode Tarif`.

Kolom bertanda wajib disorot kuning di file template, dan barisnya ditolak di
sisi klien sebelum dikirim — jadi galat "wajib diisi" muncul tanpa perlu bolak-balik
ke server.

**Kolom `Tanggal` tidak menebak.** Kuitansi tanpa tanggal ditolak, bukan diisi
tanggal impor: data lama yang dicatat ulang berbulan-bulan kemudian akan dapat
tanggal yang pasti salah, dan salahnya tidak kelihatan.

Sel yang diformat sebagai **tanggal** di Excel juga aman: pembaca file mengubahnya
jadi `YYYY-MM-DD` memakai tanggal LOKAL, sehingga tidak bergeser sehari seperti
kalau dilewatkan UTC.

**Potongan Ketua diisi persentase, bukan rupiah.** Nominalnya — potongan
sekaligus jasa ketua — diturunkan dari persentase itu dikali total rincian, jadi
tidak ada kolomnya di file: angka yang bisa diketik sendiri hanya akan
berselisih dengan hasil hitungannya. Aturannya persis sama dengan form; lihat
"Komisi ketua kelompok dihitung dari persentase" di `TransaksiHeaderController.md`.

`total` kuitansi **tidak** diambil dari file; selalu dihitung dari rinciannya.
Kalau keduanya boleh berbeda, header dan rincian bisa berselisih tanpa ada yang
tahu mana yang benar.

---

## Satu kuitansi gagal seluruhnya, bukan separuh

Tiap grup diproses di dalam satu transaksi database. Bila ada **satu** rincian
yang ditolak, seluruh kuitansi itu batal — kuitansi yang separuh terisi akan
punya total yang tidak cocok dengan "Dibayar" di filenya, dan selisih itu baru
ketahuan jauh di belakang. Grup lain tidak terpengaruh.

Baris yang tidak bersalah tetap dilaporkan gagal dengan alasan yang menyebut
penyebabnya:

```
Kuitansi "K3" batal seluruhnya — Anggota ini sudah punya transaksi untuk tarif dan periode tersebut.
```

## Baris bentrok ditolak, tidak menimpa

Sama dengan impor master lain di aplikasi ini: baris yang anggota + tarif +
periodenya sudah tercatat **ditolak**, bukan menimpa data lama. Baris gagal bisa
diunduh sebagai Excel, diperbaiki, lalu dikirim ulang.

Bentrok diperiksa di dua tempat:

| Cakupan                | Pemeriksa                     |
| ---------------------- | ----------------------------- |
| Terhadap isi database  | `periksaDuplikatPeriode()`    |
| Sesama baris satu grup | `periksaDuplikatDalamGrup()`  |

Yang kedua perlu karena barisnya belum tersimpan saat baris berikutnya diperiksa.

Rincian tarif **sekali bayar** dilewati kedua pemeriksaan itu: periodenya `null`,
dan index unik di database pun tidak membatasinya karena NULL tidak pernah sama
dengan NULL. Pungutan sekali bayar memang boleh dicatat berkali-kali.

---

## Response (200)

Selalu **200**, termasuk saat semua baris gagal — kegagalan per baris adalah hasil
yang normal bagi impor massal, bukan galat permintaan.

```json
{
  "berhasil": 3,
  "gagal": 1,
  "hasil": [
    { "baris": 2, "status": "ok", "nama": "26082101 · 01/2026", "pesan": "260822001" },
    { "baris": 3, "status": "ok", "nama": "26082101 · 02/2026", "pesan": "260822001" },
    { "baris": 4, "status": "ok", "nama": "26082101 · 03/2026", "pesan": "260822001" },
    { "baris": 5, "status": "gagal", "nama": "999999", "pesan": "baris 5: anggota dengan No. Anggota \"999999\" tidak ada di master." }
  ]
}
```

| Field             | Keterangan                                              |
| ----------------- | ------------------------------------------------------- |
| `hasil[].baris`   | Nomor baris di file Excel                                |
| `hasil[].nama`    | Penanda baris: No. Anggota + periodenya                  |
| `hasil[].pesan`   | Nomor kuitansi bila `ok`, alasan gagal bila `gagal`      |

Urutan `hasil` mengikuti nomor baris di file, bukan urutan pemrosesan per grup —
daftar galat di layar harus bisa ditelusuri sejajar dengan filenya.

### Error (422)

Hanya untuk permintaan yang bentuknya salah, mis. `rows` kosong atau melebihi 200
baris.
