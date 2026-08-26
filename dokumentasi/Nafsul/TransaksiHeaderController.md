# TransaksiHeaderController

**Controller:** App\Http\Controllers\Nafsul\TransaksiHeaderController
**Base URL:** /api/nafsul/transaksi/header

Header transaksi iuran Nafsul. Satu baris = satu kali pembayaran (satu
kuitansi), yang bisa menampung banyak baris rincian di tabel `transactions` —
mis. satu anggota membayar iuran beberapa bulan sekaligus, atau satu ketua
kelompok menyetorkan iuran beberapa anggotanya.

Rinciannya didokumentasikan terpisah di `TransaksiController.md`.

---

## Nama kolom & field

Sama seperti `transactions`, tabel ini tidak memakai `HasLegacyAttributes` —
nama kolom database dan nama field API sama-sama Inggris. Responsnya JSON polos
(bukan pembungkus `success`/`error`) karena helper frontend
`lib/nafsul/api.ts` membaca body-nya langsung.

| Kolom database           | Field API                | Keterangan                          |
| ------------------------ | ------------------------ | ----------------------------------- |
| `uuid`                   | `uuid`                   | Kunci publik untuk view/update/delete |
| `transaction_number`     | `transaction_number`     | Nomor kuitansi, unik                |
| `date`                   | `date`                   | Tanggal uang DITERIMA (`YYYY-MM-DD`) |
| `transaction_type`       | `transaction_type`       | `kelompok` atau `pribadi`           |
| `total`                  | `total`                  | decimal(15,2)                       |
| `member_deduction`       | `member_deduction`       | Potongan anggota, **selalu rupiah** |
| `member_deduction_type`  | `member_deduction_type`  | `amount` atau `percent`             |
| `member_deduction_input` | `member_deduction_input` | Angka yang diketik petugas          |
| `group_leader_fee_percent` | `group_leader_fee_percent` | **Persentase** komisi ketua kelompok, mis. `10.00` |
| `group_leader_deduction` | `group_leader_deduction` | Potongan ketua kelompok (rupiah, **dihitung**) |
| `group_leader_fee`       | `group_leader_fee`       | Jasa ketua kelompok (rupiah, **dihitung**) |
| `payment`                | `payment`                | Uang yang benar-benar diterima      |
| `payment_method`         | `payment_method`         | `transfer`, `cash`, atau `other`    |
| `validation_at`          | `validation_at`          | Waktu kuitansi diperiksa; `null` = **belum** divalidasi |
| `validation_by`          | `validation_by`          | NAMA pemeriksa (bukan FK ke `users`) |
| —                        | `balance`                | Dihitung, lihat di bawah            |
| —                        | `transactions_count`     | Jumlah baris rincian                |
| `disabled`               | `disabled`               | `true` bila barisnya sudah dihapus  |
| `deleted_at`             | `deleted_at`             | Kapan dihapus                       |
| `deleted_by`             | `deleted_by`             | **Username** penghapus              |

---

## `disabled` menandai baris yang sudah dihapus

`transaction_headers` dan `transactions` sama-sama punya kolom `disabled`:
`true` begitu barisnya dihapus, `false` selama belum.

**Nilainya turunan, tidak pernah diisi tangan.** Trait
`App\Traits\MarksDisabledWhenDeleted` mengisinya lewat event `saving`, jadi
jalur apa pun yang menyimpan model — `delete()`, `restore()`, maupun `update()`
biasa — meninggalkannya konsisten dengan `deleted_by`. Kolomnya juga sengaja
**tidak** masuk `$fillable` supaya tidak bisa ditumpangi mass assignment dari
request.

Itu syarat yang membuatnya aman: `deleted_by` sudah jadi penentu tunggal apakah
sebuah baris terhapus (global scope `active` membacanya), jadi kolom kedua yang
menjawab pertanyaan yang sama hanya berguna kalau mustahil berselisih dengannya.

> **Jangan menghapus baris lewat pembaruan massal** (`->update([...])` pada query
> builder): itu tidak memicu event model, sehingga `disabled` tertinggal. Di
> proyek ini penghapusan memang selalu lewat instance model — `HasAuditColumns::delete()`
> pun begitu, karena hanya di sanalah `deleted_by` terisi.

### Siapa yang menghapus

Sudah tercatat sejak awal oleh `HasAuditColumns::delete()`, tiga kolom sekaligus:

| Kolom | Isi |
| ----- | --- |
| `deleted_at` | Waktu penghapusan |
| `deleted_by` | **Username** penghapus (perhatikan: `created_by`/`updated_by` memakai `name`) |
| `deleted_user_id` | Id user penghapus, sebagai snapshot — bukan foreign key |

`disabled`, `deleted_at`, dan `deleted_by` ikut dikirim pada payload header
maupun rincian. Pada pemanggilan biasa ketiganya **selalu** bernilai
`false`/`null` karena global scope menyaring baris terhapus; isinya baru terlihat
bila sengaja diambil lewat `withTrashed()`. Tetap dikirim supaya bentuk
responsnya satu macam, tidak berubah tergantung cakupan query.

---

## `date` bukan `created_at`

`created_at` mencatat kapan BARISNYA DIBUAT di sistem; `date` mencatat kapan
UANGNYA DITERIMA. Keduanya sering berbeda — setoran Sabtu baru diinput Senin,
dan kuitansi lama dicatat ulang berbulan-bulan kemudian. Selama tidak ada kolom
ini, laporan harian memakai tanggal input dan tidak pernah cocok dengan buku kas.

Tipenya `date`, bukan `timestamp`: yang dicatat kuitansi adalah HARI penerimaan.
Jam-menitnya tidak pernah dipakai, dan menyimpannya hanya memaksa setiap
penyaringan membungkus kolomnya dengan `DATE()`.

**Nullable di database, WAJIB di API.** Nullable hanya supaya migrasi
`add_date_to_transaction_headers` tidak perlu memaksakan nilai palsu lewat
DEFAULT pada baris lama; baris lama justru diisi dari `DATE(created_at)`-nya
sendiri, perkiraan terbaik yang tersedia. Kuitansi baru selalu wajib membawa
tanggalnya.

Penyaring `date_from`/`date_to` pada `index` membaca
`COALESCE(date, created_at)` — cadangan itu menjaga baris yang tanggalnya
kebetulan kosong agar tidak diam-diam terbuang dari hasil.

Form transaksi baru mengisinya **hari ini** secara bawaan, dihitung dari waktu
LOKAL perangkat (bukan `toISOString()` yang memakai UTC — di WIB, setoran sebelum
pukul 07.00 akan tercatat mundur satu hari).

---

## UUID sebagai kunci URL

Route model binding memakai `uuid`, bukan `id`:

```
GET    /api/nafsul/transaksi/header/{uuid}
PUT    /api/nafsul/transaksi/header/{uuid}
DELETE /api/nafsul/transaksi/header/{uuid}
```

`id` yang berurutan tidak dipakai di URL karena nomornya bisa ditebak —
pengguna bisa mencoba-coba id tetangga untuk mengintip kuitansi milik orang
lain. UUID tidak memberi petunjuk apa pun soal jumlah maupun urutan data.
Mengakses endpoint dengan `id` menghasilkan **404**.

**Relasi antar tabel tetap memakai `id`**, bukan uuid: `transactions.transaction_header_id`
adalah bigint. Foreign key numerik jauh lebih ringan untuk index dibanding
string 36 karakter, dan uuid hanya diperlukan di permukaan (URL).

UUID diisi otomatis lewat event `creating` di model, bukan diserahkan ke
pemanggil — kalau setiap tempat yang membuat baris harus mengingat mengisinya
sendiri, cepat atau lambat ada satu yang lupa dan barisnya gagal disimpan
karena kolomnya unik dan tidak nullable.

---

## Nomor transaksi

```
YY  MM  DD  NNN
26  08  21  001     →  "260821001"
```

- `YYMMDD` diambil dari tanggal hari ini.
- `NNN` adalah urutan **per hari**, dihitung ulang setiap tanggal berganti.

Dibuat otomatis saat `store` bila `transaction_number` tidak dikirim. Boleh
diisi manual untuk mencatat kuitansi lama — sama seperti perilaku nomor anggota.

### Keunikan

| Lapis                                | Fungsi                                                       |
| ------------------------------------ | ------------------------------------------------------------ |
| Validasi (`Rule::unique`)            | Menolak dengan **422** dan pesan yang bisa dibaca pengguna    |
| Index `transaction_headers_transaction_number_unique` | Jaring pengaman untuk dua permintaan bersamaan |

Generator memeriksa tiap kandidat sebelum memakainya. Urutan diambil dari nomor
terbesar hari itu, tapi angka itu bisa meleset kalau ada nomor lama berformat
lain yang ikut tertangkap pola prefix — tanpa pemeriksaan itu, penyimpanan bisa
menabrak index unik dan gagal dengan galat SQL mentah.

Pengurutannya juga memperhitungkan panjang string dulu, baru nilainya: kalau
satu hari tembus 999 transaksi, `"2608211000"` harus dianggap lebih besar dari
`"260821999"` — perbandingan teks saja akan membalik keduanya.

---

## `transaction_type` — kelompok atau pribadi

- **`kelompok`** — setoran ketua kelompok untuk anggota-anggotanya.
- **`pribadi`** — anggota perorangan membayar sendiri.

Ditaruh di header, bukan di rincian, karena `group_leader_deduction` dan
`group_leader_fee` hanya berlaku pada setoran kelompok — dan keduanya kolom
header. Satu kuitansi karenanya hanya boleh berjenis satu.

### Konsekuensinya

**Server menolkan potongan & jasa ketua kelompok pada kuitansi `pribadi`**,
berapa pun nilai yang dikirim. Form memang menyembunyikan kedua field itu di tab
Pribadi, tapi form yang menyembunyikan field tidak menghalangi permintaan yang
disusun sendiri, dan angka nyasar itu tetap akan menggeser `balance`.

```
POST { transaction_type: "pribadi", group_leader_deduction: 9999, group_leader_fee: 8888 }
→ tersimpan 0.00 dan 0.00
```

**Form mengunci tabnya begitu ada rincian** (`app/(app)/nafsul/transaksi/baru`).
Membiarkan tab berpindah di tengah pengisian akan menghasilkan kuitansi yang
isinya bertentangan dengan jenisnya sendiri. Untuk menggantinya, hapus dulu
seluruh rincian.

Tab juga tercatat di URL (`?tab=pribadi`) supaya bisa ditautkan langsung dan
bertahan saat halaman dimuat ulang.

---

## Potongan anggota: rupiah atau persen

Petugas boleh mengisinya sebagai **rupiah** atau **persen dari total rincian**,
lewat satu isian dengan pilihan satuan `Rp | %` di sebelahnya.

Yang dikirim klien adalah **nilai ketik + satuan**, bukan rupiah jadi:

| Field                    | Contoh "Rp 25.000" | Contoh "10%" |
| ------------------------ | ------------------ | ------------ |
| `member_deduction_type`  | `amount`           | `percent`    |
| `member_deduction_input` | `25000`            | `10`         |

`member_deduction` dihitung server di `terapkanPotonganAnggota()` dari `total`
final. Nominal yang dikirim klien **diabaikan** — sama seperti jasa ketua
kelompok, supaya angka di layar tidak bisa berselisih dengan yang tersimpan.

### Kenapa dua-duanya disimpan

**Rupiahnya** yang mengikat. Kalau hanya persennya yang disimpan, potongan ikut
bergeser begitu rincian kuitansi diedit — kuitansi yang sudah tercetak jadi
tidak cocok lagi dengan yang tersimpan.

**Persennya** ikut disimpan, bukan dihitung mundur `rupiah ÷ total`: hasilnya
bisa meleset karena pembulatan, dan tidak bisa dihitung sama sekali kalau
totalnya nol. Alasan yang sama dengan `group_leader_fee_percent`.

`member_deduction_input` memakai 4 desimal supaya persen pecahan (mis. 2,5%)
tidak dibulatkan.

### Validasi

| Kasus                                   | Hasil                                                        |
| --------------------------------------- | ------------------------------------------------------------ |
| `percent` dengan nilai > 100            | 422 — "tidak boleh lebih dari 100%"                          |
| `member_deduction_type` selain keduanya | 422 — "hanya boleh rupiah atau persen"                       |
| Potongan + potongan ketua > total       | 422 — penjaga lama, berlaku setelah persen diubah jadi rupiah |

Nilai > 100 pada satuan persen hampir selalu salah satuan (mengetik `25000` lalu
memilih `%`), jadi ditolak daripada tersimpan sebagai potongan yang melebihi
seluruh tagihan.

### Impor Excel

Kolom `potongan_anggota` di templat impor **selalu rupiah** — tidak ada kolom
satuan. `TransaksiImportController` mengisi `member_deduction_type = 'amount'`
dan menyalin nominalnya ke `member_deduction_input`, supaya kuitansi hasil impor
menampilkan potongan yang benar saat dibuka di form.

---

## `payment_method` bukan kolom ENUM

Dipakai `string(20)` dengan validasi `Rule::in`, bukan kolom ENUM MySQL.
Menambah cara bayar baru (mis. QRIS) pada ENUM berarti `ALTER TABLE` yang
mengunci tabel; di sini cukup menambah satu nilai di konstanta
`TransactionHeader::PAYMENT_METHODS`.

Nilai `other` ("lain-lain") ditambahkan persis dengan cara itu — **tanpa
migrasi**. Isinya setoran yang tidak lewat kas maupun rekening: potong tabungan,
barter, atau titipan pengurus. Ditampung satu nilai saja alih-alih kolom
keterangan bebas, karena yang dibutuhkan laporan hanya pemisahan kas/bank dan
sisanya cukup dikelompokkan.

---

## Komisi ketua kelompok dihitung dari persentase

Yang dikirim klien hanya `group_leader_fee_percent` (mis. `10` untuk 10%).
Nominal rupiahnya diturunkan server dari **total rincian final**:

```
nominal = round(total × persen / 100, 2)
group_leader_deduction = nominal
group_leader_fee       = nominal
```

Satu angka yang sama muncul dua kali karena ketua kelompok **menahan komisinya
dari uang yang ia kumpulkan**: ia mengurangi setoran (sebagai potongan) lalu
ditambahkan kembali sebagai haknya (sebagai jasa). Keduanya saling menghapus di
`balance`; yang disetorkan tetap total dikurangi potongan anggota.

| Total rincian | Persen | Potongan ketua | Jasa ketua | Harus dibayar |
| ------------- | ------ | -------------- | ---------- | ------------- |
| 500.000       | 10     | −50.000        | +50.000    | 500.000       |

Kedua kolom rupiah itu **tidak diterima dari klien**. Dikirim pun diabaikan —
angka yang bisa diketik sendiri hanya akan berselisih dengan persentase yang
tercatat di kuitansi yang sama, tanpa ada yang tahu mana yang benar.

Persentasenya ikut disimpan, bukan cuma nominalnya: form yang membuka kuitansi
lama harus menampilkan angka yang diketik petugas (10), bukan hasil hitung mundur
`rupiah ÷ total` yang bisa meleset karena pembulatan — dan tidak bisa dihitung
sama sekali kalau totalnya nol.

Pada kuitansi `pribadi` persentasenya dinolkan, sama seperti kolom ketua lainnya.

---

## `balance` dihitung, tidak disimpan

```
balance = (total - member_deduction - group_leader_deduction) - payment
```

`group_leader_fee` **tidak** ikut dijumlahkan. Komisi ketua kelompok ditahan
dari uang yang ia setorkan, jadi mengurangi setoran. Kolomnya tetap diisi
nominal yang sama sebagai catatan **hak ketua** — dipakai laporan dan pembayaran
komisi, bukan sebagai penambah setoran.

> Sebelumnya nominal itu ikut ditambahkan kembali sehingga potongan dan jasa
> saling menghapus, dan komisi ketua tidak berpengaruh sama sekali pada jumlah
> yang harus dibayar. Diubah 22 Agustus 2026.

Positif berarti masih kurang bayar, negatif berarti lebih bayar.

Kalau ikut disimpan, nilainya bisa melenceng dari kolom-kolom penyusunnya begitu
salah satunya diubah.

---

## 1. index

**Method:** GET · **Endpoint:** /api/nafsul/transaksi/header · **Auth:** Bearer Token (wajib)

| Query            | Type    | Keterangan                                |
| ---------------- | ------- | ----------------------------------------- |
| `search`         | string  | Cari di nomor transaksi                   |
| `payment_method` | string  | `transfer` atau `cash`                    |
| `transaction_type` | string | `kelompok` atau `pribadi`               |
| `date_from`      | date    | Tanggal transaksi, batas bawah            |
| `date_to`        | date    | Tanggal transaksi, batas atas             |
| `per_page`       | integer | Bawaan 25                                 |

`date_from` / `date_to` menyaring **tanggal transaksi** (`created_at`), bukan
periode iuran — periode itu milik rincian.

Diurutkan `transaction_number DESC`, lalu `id DESC`. Nomor transaksi sudah urut
kronologis, dan `id` dipakai sebagai pemecah seri agar urutannya tidak
berubah-ubah antar halaman.

---

## 2. store

**Method:** POST · **Endpoint:** /api/nafsul/transaksi/header · **Auth:** Bearer Token (wajib)

| Parameter                | Type    | Required | Keterangan                          |
| ------------------------ | ------- | -------- | ----------------------------------- |
| `transaction_number`     | string  | Tidak    | Dibuat otomatis bila kosong; unik   |
| `transaction_type`       | string  | **Ya**   | `kelompok` atau `pribadi`           |
| `total`                  | numeric | Ya       | ≥ 0                                 |
| `member_deduction`       | numeric | Tidak    | ≥ 0, bawaan 0                       |
| `group_leader_fee_percent` | numeric | Tidak  | 0–100, bawaan 0. **Persen**, bukan rupiah |
| `payment`                | numeric | Ya       | ≥ 0                                 |
| `payment_method`         | string  | Ya       | `transfer`, `cash`, atau `other`    |
| `date`                   | date    | Ya       | Tanggal uang diterima (`YYYY-MM-DD`) |
| `transactions`           | array   | Tidak    | Rincian iuran; lihat di bawah       |

Gabungan `member_deduction + group_leader_deduction` tidak boleh melebihi
`total` — hasilnya tagihan negatif, yang hampir selalu salah ketik dan lebih
baik ditolak daripada tersimpan diam-diam.

### Menyimpan kuitansi beserta rinciannya sekaligus

Satu permintaan membuat header **dan** seluruh baris rinciannya. Rinciannya
boleh memuat lebih dari satu anggota — satu kuitansi bisa menampung setoran
beberapa anggota dari kelompok yang sama.

| Field per baris                  | Type    | Required | Keterangan                  |
| -------------------------------- | ------- | -------- | --------------------------- |
| `transactions.*.member_id`       | integer | Ya       | `exists:members,id`         |
| `transactions.*.rate_id`         | integer | Ya       | `exists:rates,id`           |
| `transactions.*.payment_period`  | string  | Tergantung | "MM/YYYY". Wajib untuk tarif `recurring`, **harus kosong** untuk tarif `one_time` |
| `transactions.*.amount`          | numeric | Ya       | ≥ 0                         |
| `transactions.*.discount`        | numeric | Tidak    | ≥ 0, tidak boleh > `amount` |

```json
{
  "member_deduction": 5000,
  "group_leader_deduction": 2000,
  "group_leader_fee": 1000,
  "payment": 140000,
  "payment_method": "cash",
  "transactions": [
    { "member_id": 12, "rate_id": 1, "payment_period": "07/2026", "amount": 50000 },
    { "member_id": 13, "rate_id": 1, "payment_period": "07/2026", "amount": 50000, "discount": 5000 },
    { "member_id": 14, "rate_id": 1, "payment_period": "07/2026", "amount": 50000 },
    { "member_id": 12, "rate_id": 3, "payment_period": null, "amount": 25000 }
  ]
}
```

**`total` dihitung ulang server dari rinciannya**, bukan diambil dari angka
kiriman klien. Kalau keduanya boleh berbeda, header dan rincian bisa berselisih
tanpa ada yang tahu mana yang benar. Bila `transactions` kosong, barulah `total`
kiriman klien yang dipakai.

Seluruhnya dibungkus satu transaksi database: kalau ada satu baris rincian yang
ditolak, header-nya ikut dibatalkan. Tanpa itu, kegagalan di tengah menyisakan
kuitansi kosong yang nomornya sudah terpakai.

Selain aturan per baris yang sama dengan simpan satuan (lihat
`TransaksiController.md`), ada satu pemeriksaan tambahan: **baris kembar di
dalam satu kiriman**. Bentrok semacam itu tidak akan tertangkap pengecekan
database karena barisnya belum tersimpan saat baris berikutnya diperiksa.

```json
{
  "errors": {
    "transactions.1": ["Baris 2 mengulang anggota, tarif, dan periode yang sama dengan baris 1."]
  }
}
```

Aturan baris dipakai bersama lewat trait `App\Traits\HandlesTransactionRows`,
supaya baris yang ditolak lewat form satuan juga ditolak saat dikirim sebagai
bagian dari kuitansi.

### Success (201)

```json
{
  "id": 3,
  "uuid": "d53a226f-7c20-4354-920b-9d8fa6cf0c76",
  "transaction_number": "260821001",
  "transaction_type": "kelompok",
  "total": "100000.00",
  "member_deduction": "5000.00",
  "group_leader_deduction": "2000.00",
  "group_leader_fee": "1000.00",
  "payment": "100000.00",
  "payment_method": "cash",
  "balance": "-6000.00",
  "transactions_count": 0,
  "created_at": "2026-08-21 09:12:03"
}
```

### Error (422)

| Field              | Pesan                                                                  |
| ------------------ | ---------------------------------------------------------------------- |
| `payment_method`   | Cara bayar hanya boleh transfer, tunai, atau lain-lain.                |
| `transaction_type` | Jenis transaksi hanya boleh kelompok atau pribadi.                     |
| `member_deduction` | Potongan anggota + potongan ketua kelompok tidak boleh melebihi total. |
| `transaction_number` | No. Transaksi "260821001" sudah dipakai.                             |

---

## 3. show

**Method:** GET · **Endpoint:** /api/nafsul/transaksi/header/{uuid}

Berbeda dengan `index`, respons `show` **menyertakan rinciannya** di field
`transactions`, diurutkan menurut periode:

```json
{
  "id": 3,
  "uuid": "d53a226f-7c20-4354-920b-9d8fa6cf0c76",
  "transaction_number": "260821001",
  "transactions_count": 3,
  "transactions": [
    {
      "id": 1,
      "uuid": "722b8d21-26a8-43bc-bddb-ce4de9e0977d",
      "member_id": 12,
      "member_number": "ZH001",
      "member_name": "Ahmad Fauzi",
      "rate_id": 1,
      "rate_name": "Iuran Bulanan",
      "payment_period": "07/2026",
      "amount": "50000.00",
      "discount": "0.00",
      "total": "50000.00"
    }
  ]
}
```

---

## 4. update / destroy

**Endpoint:** /api/nafsul/transaksi/header/{uuid}

`update` memakai aturan yang sama dengan `store`, hanya saja barisnya sendiri
dikecualikan dari pemeriksaan keunikan nomor. Mengirim `transaction_number`
kosong **tidak** mengosongkan nomor yang sudah terbit — field-nya diabaikan.

### Rincian ikut bisa diubah

Bila `transactions` **dikirim**, isinya disamakan dengan kiriman itu: baris
diperbarui, dibuat, atau dilepas sesuai kebutuhan, lalu `total` header dihitung
ulang dari jumlah rinciannya — aturan yang sama dengan `store`. Header dan
rincian karena itu tidak pernah bisa berselisih.

Bila `transactions` **tidak dikirim**, rincian tidak disentuh sama sekali.
Pembaruan yang hanya mengubah header (mis. cara bayar) tidak perlu ikut mengirim
seluruh rinciannya, dan diamnya field ini TIDAK berarti "kosongkan". Mengirim
array kosong ditolak **422**: kuitansi tanpa rincian tidak punya arti.

| Field baris  | Wajib | Keterangan |
| ------------ | ----- | ---------- |
| `uuid`       | Tidak | Diisi untuk baris yang SUDAH ADA. Menandakan baris itu diperbarui **di tempat**, bukan dihapus lalu dibuat ulang — pemeriksaan duplikat periode bercakupan `withTrashed()`, jadi baris yang dibuat ulang dengan periode yang sama akan ditolak oleh bekasnya sendiri. Baris tanpa `uuid` dianggap baru. |
| `member_id`  | Ya    | Tetap wajib walau tidak diubah form edit. |
| `rate_id`    | Ya    | Idem. |
| `payment_period` | Tergantung tarif | Wajib untuk tarif berulang, harus kosong untuk tarif sekali bayar. |
| `amount`, `discount` | `amount` wajib | — |

Baris yang tidak lagi ada di kiriman dilepas satu per satu lewat model, bukan
mass delete: `HasAuditColumns::delete()` yang mengisi `deleted_by` hanya berjalan
pada instance model, dan tanpa kolom itu barisnya tidak terhitung terhapus sama
sekali.

Kuitansi yang sudah **divalidasi** ditolak `update` maupun `destroy` — buka
kuncinya dulu lewat `batal-validasi`.

`destroy` melakukan soft delete. Rinciannya **tidak** ikut terhapus dan
`transaction_header_id` sengaja dibiarkan terisi: relasinya otomatis terbaca
null karena global scope menyaring header yang sudah dihapus, dan kaitannya
pulih utuh bila header di-restore. Mengosongkan kolomnya justru membuat restore
tidak ada gunanya.

---

## 5. reset

**Method:** POST · **Endpoint:** /api/nafsul/transaksi/header/{uuid}/reset · **Auth:** Bearer Token (wajib)

Kembalikan kuitansi ke keadaan **belum dibayar**. Dipakai saat kuitansi terlanjur
dibuat salah — nominal keliru, anggotanya tertukar, atau uangnya ternyata belum
diterima.

Dua langkah, dalam satu transaksi database:

1. Seluruh rinciannya dilepas (`transaction_header_id` → `null`) sehingga kembali
   berdiri sebagai tagihan yang menunggu pembayaran;
2. kuitansinya sendiri di-soft-delete.

### Bedanya dengan `destroy`

| | `destroy` | `reset` |
| --- | --- | --- |
| Kuitansi | soft delete | soft delete |
| `transaction_header_id` rincian | **tetap terisi** | **dikosongkan** |
| Tujuan | menghapus kuitansi, kaitannya pulih bila di-restore | membebaskan rincian untuk dibuatkan kuitansi baru |

### Rincian tidak ikut terhapus

Periodenya sudah tercatat sebagai tagihan anggota, dan menghapusnya berarti
membuang riwayat iuran yang sebenarnya sah. Karena itu pula periode yang sama
**tetap ditolak** bila diinput lagi:

```
POST /api/nafsul/transaksi { payment_period: "01/2029", ... }
→ 422 "Anggota ini sudah punya transaksi untuk tarif dan periode tersebut."
```

### Response (200)

```json
{
  "message": "Kuitansi direset. 3 rincian kembali menjadi tagihan yang belum dibayar.",
  "released": 3
}
```

Route-nya didaftarkan **sebelum** `apiResource('transaksi/header')`, alasannya
sama dengan bagian di bawah.

---

## 6. validasi

**Method:** POST
**Endpoint:** /api/nafsul/transaksi/header/{uuid}/validasi
**Auth:** Bearer Token (wajib)

Tandai kuitansi **sudah diperiksa**: `validation_at` diisi waktu sekarang dan
`validation_by` diisi nama pengguna yang login.

Keduanya ditetapkan **server**, tidak ada satu pun field yang diterima dari body
— jejak pemeriksaan tidak boleh bisa disetel klien.

### Kenapa dua kolom, bukan satu boolean

Boolean `validated` hanya menjawab "sudah atau belum". Yang dibutuhkan saat ada
selisih justru **siapa** dan **kapan**. `validation_at` NULL sekaligus menjadi
penanda statusnya, jadi tidak ada kolom kedua yang bisa menyimpang darinya.

`validation_by` menyimpan **nama**, bukan foreign key ke `users` — mengikuti pola
`created_by`/`updated_by` di seluruh proyek ini: nama pada kuitansi lama tidak
boleh ikut berubah bila akun pemeriksanya di-rename atau dihapus.

### Hanya sekali

Kuitansi yang sudah punya `validation_at` ditolak **422**, bukan diam-diam
ditimpa. Kalau nama & waktu pemeriksa pertama bisa tergeser oleh klik kedua siapa
pun, jejaknya tidak lagi bisa dipakai menelusuri siapa yang sebenarnya memeriksa.

### Setelah divalidasi, kuitansi terkunci

`update()` dan `destroy()` menolak kuitansi yang sudah punya `validation_at`
dengan **422**. Jejak pemeriksaan tidak ada artinya kalau isi kuitansinya masih
bisa bergeser sesudahnya — nama pemeriksa tetap menempel pada angka yang bukan
lagi yang ia periksa.

Ditegakkan **di server**, bukan sekadar menyembunyikan tombolnya di halaman:
tombol yang hilang hanya menutup jalan yang lewat antarmuka.

```json
{ "message": "Kuitansi 260821001 sudah divalidasi oleh Siti Aminah, jadi tidak bisa diubah lagi." }
```

Untuk membatalkannya, `validation_at` harus dikosongkan lebih dulu. Endpointnya
sengaja belum ada supaya pembatalan validasi jadi keputusan sadar, bukan efek
samping dari sebuah edit.

### Response (200)

```json
{
  "message": "Kuitansi 260821001 berhasil divalidasi.",
  "data": {
    "uuid": "…",
    "transaction_number": "260821001",
    "validation_at": "2026-08-23 09:14:02",
    "validation_by": "Siti Aminah"
  }
}
```

### Error (422) — sudah divalidasi

```json
{ "message": "Kuitansi ini sudah divalidasi oleh Siti Aminah." }
```

Route-nya juga didaftarkan **sebelum** `apiResource('transaksi/header')`.

---

## 7. batal-validasi

**Method:** POST
**Endpoint:** /api/nafsul/transaksi/header/{uuid}/batal-validasi
**Auth:** Bearer Token (wajib)

Buka kunci: `validation_at` & `validation_by` dikosongkan lagi, sehingga kuitansi
bisa diubah & dihapus kembali.

Endpoint **terpisah** dari `update()`, bukan sekadar field yang boleh dikirim
saat mengedit. Membuka kunci adalah keputusan sendiri — kalau ia bisa menumpang
pada sebuah edit, kuncinya terbuka sebagai efek samping dan tidak ada yang
menyadarinya.

Jejak pemeriksa lama **tidak disimpan** ke mana pun: begitu dibuka, kuitansi
kembali ke keadaan belum diperiksa seutuhnya, dan validasi berikutnya mencatat
nama & waktu yang baru.

### Response (200)

```json
{
  "message": "Validasi kuitansi 260821001 dibatalkan. Kuitansi bisa diubah & dihapus lagi.",
  "data": { "validation_at": null, "validation_by": null }
}
```

### Error (422) — memang belum divalidasi

```json
{ "message": "Kuitansi 260821001 memang belum divalidasi." }
```

---

## Urutan pendaftaran route

`transaksi/header` **wajib** didaftarkan sebelum `apiResource('transaksi')`.
Kalau terbalik, `/nafsul/transaksi/header` tertangkap sebagai
`/nafsul/transaksi/{transaksi}` dan Laravel mencari transaksi ber-id "header".
