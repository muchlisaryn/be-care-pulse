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
| `transaction_type`       | `transaction_type`       | `kelompok` atau `pribadi`           |
| `total`                  | `total`                  | decimal(15,2)                       |
| `member_deduction`       | `member_deduction`       | Potongan anggota                    |
| `group_leader_fee_percent` | `group_leader_fee_percent` | **Persentase** komisi ketua kelompok, mis. `10.00` |
| `group_leader_deduction` | `group_leader_deduction` | Potongan ketua kelompok (rupiah, **dihitung**) |
| `group_leader_fee`       | `group_leader_fee`       | Jasa ketua kelompok (rupiah, **dihitung**) |
| `payment`                | `payment`                | Uang yang benar-benar diterima      |
| `payment_method`         | `payment_method`         | `transfer` atau `cash`              |
| —                        | `balance`                | Dihitung, lihat di bawah            |
| —                        | `transactions_count`     | Jumlah baris rincian                |

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

## `payment_method` bukan kolom ENUM

Dipakai `string(20)` dengan validasi `Rule::in`, bukan kolom ENUM MySQL.
Menambah cara bayar baru (mis. QRIS) pada ENUM berarti `ALTER TABLE` yang
mengunci tabel; di sini cukup menambah satu nilai di konstanta
`TransactionHeader::PAYMENT_METHODS`.

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
balance = (total - member_deduction - group_leader_deduction + group_leader_fee) - payment
```

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
| `payment_method`         | string  | Ya       | `transfer` atau `cash`              |
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
| `payment_method`   | Cara bayar hanya boleh transfer atau cash.                             |
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

## Urutan pendaftaran route

`transaksi/header` **wajib** didaftarkan sebelum `apiResource('transaksi')`.
Kalau terbalik, `/nafsul/transaksi/header` tertangkap sebagai
`/nafsul/transaksi/{transaksi}` dan Laravel mencari transaksi ber-id "header".
