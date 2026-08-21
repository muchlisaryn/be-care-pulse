# TransaksiController

**Controller:** App\Http\Controllers\Nafsul\TransaksiController
**Base URL:** /api/nafsul/transaksi

Transaksi iuran anggota Nafsul. Satu baris = satu tagihan iuran seorang anggota
untuk satu periode dan satu tarif.

---

## Nama kolom & field

Berbeda dengan master Nafsul lain, tabel `transactions` **tidak** memakai
`HasLegacyAttributes`. Master lain menyimpan kolom berbahasa Inggris tetapi
mengekspos nama Indonesia untuk mempertahankan kontrak API lama; tabel ini baru
dan tidak punya konsumen lama, jadi lapisan penerjemah itu hanya akan menambah
beban tanpa manfaat.

| Kolom database          | Field API               | Keterangan                       |
| ----------------------- | ----------------------- | -------------------------------- |
| `uuid`                  | `uuid`                  | Kunci publik untuk view/update/delete |
| `transaction_header_id` | `transaction_header_id` | FK `transaction_headers.id`, nullable |
| `member_id`     | `member_id`      | FK `members.id`                       |
| `rate_id`       | `rate_id`        | FK `rates.id`                         |
| `payment_period`| `payment_period` | DATE di DB, **"MM/YYYY"** di API      |
| `amount`        | `amount`         | decimal(15,2) — nominal               |
| `discount`      | `discount`       | decimal(15,2), bawaan 0               |
| —               | `total`          | Dihitung: `amount - discount`         |
| —               | `transaction_number` | Nomor kuitansi dari header (null bila belum dibayar) |

### Kaitan ke header

`transaction_header_id` **nullable** dengan sengaja: rincian bisa dicatat lebih
dulu sebagai tagihan yang belum dibayar, lalu menyusul dikaitkan ke header saat
pembayarannya terjadi. Kalau kolomnya wajib, tagihan yang belum dibayar tidak
punya tempat sama sekali.

Menghapus header **tidak** menghapus rinciannya. `nullOnDelete` di FK hanya
berlaku pada hard delete; penghapusan lewat API adalah soft delete, jadi
`transaction_header_id` tetap terisi dan hanya relasinya yang terbaca null
karena tersaring global scope. Kaitannya pulih utuh bila header di-restore —
itu sebabnya kolomnya sengaja tidak dikosongkan.

Respons dikirim sebagai JSON polos (bukan pembungkus `success`/`error`), sama
dengan controller Nafsul lainnya — helper frontend `lib/nafsul/api.ts` membaca
body-nya langsung.

### Periode disimpan sebagai DATE

`payment_period` adalah kolom DATE yang selalu dinormalkan ke **tanggal 1** di
bulan tersebut, bukan sepasang kolom bulan + tahun.

Dengan satu kolom tanggal, pengurutan kronologis dan filter rentang
("Januari–Juni 2026") jadi perbandingan biasa yang bisa memakai index. Dengan dua
kolom integer, keduanya butuh ekspresi gabungan yang tidak bisa.

Harinya dipatok ke 1 supaya dua baris periode yang sama tidak lolos dari index
unik hanya karena berbeda hari.

API memakai bentuk **"MM/YYYY"** karena itulah yang ditampilkan di UI. Validasinya
memakai `regex`, bukan aturan `date` — `"08/2026"` bukan tanggal yang sah bagi
Laravel.

### `total` dihitung, tidak disimpan

Kalau ikut disimpan, nilainya bisa melenceng dari `amount` dan `discount` saat
salah satunya diubah. Nilainya juga dijaga tidak negatif; diskon yang melebihi
nominal sudah ditolak validasi, tapi data lama bisa saja lolos.

### Keunikan

Satu anggota hanya boleh punya **satu baris per tarif per periode**. Dijaga di
dua lapis:

| Lapis                        | Fungsi                                                       |
| ---------------------------- | ------------------------------------------------------------ |
| Pemeriksaan di controller    | Menolak dengan **422** dan pesan yang bisa dibaca pengguna    |
| Index `transactions_unik`    | Jaring pengaman untuk dua permintaan yang berjalan bersamaan  |

Tanpa itu, mengirim ulang form yang sama (atau klik simpan dua kali) diam-diam
membuat tagihan ganda untuk bulan yang sama.

Pemeriksaan di controller memakai `withTrashed()` agar cakupannya sama dengan
index unik di database — kalau lebih sempit, baris lolos validasi lalu gagal
dengan galat SQL mentah yang tidak menyebut penyebabnya.

---

## Rencana iuran otomatis

**Method:** GET · **Endpoint:** `/api/nafsul/transaksi/rencana` · **Auth:** Bearer Token (wajib)

Petugas cukup memasukkan **jumlah bulan**; periode, nominal, dan diskonnya
dihitung endpoint ini. Tidak menyimpan apa pun — hasilnya dipakai frontend untuk
mengisi form, lalu dikirim balik lewat `POST /transaksi/header`.

| Query       | Type    | Required | Keterangan                          |
| ----------- | ------- | -------- | ----------------------------------- |
| `member_id` | integer | Ya       | `exists:members,id`                 |
| `rate_id`   | integer | Ya       | `exists:rates,id`                   |
| `months`    | integer | Ya       | 1–120                               |

Dibatasi 120 bulan (10 tahun): di atas itu hampir pasti salah ketik, dan
barisnya jadi terlalu banyak untuk ditinjau petugas.

### Aturan

**Titik mulai** — bulan setelah pembayaran terakhir anggota itu **pada tarif yang
sama**. Tiap tarif punya jadwalnya sendiri, jadi seorang anggota bisa sudah lunas
"Iuran Bulanan" sampai 08/2026 sementara "Iuran Sosial"-nya baru sampai 03/2026.

Pencariannya memakai `withTrashed()` supaya periode milik baris yang sudah
dihapus tidak diusulkan lagi — index unik di database mencakup baris terhapus,
jadi periode itu tetap akan ditolak saat disimpan.

Anggota yang belum pernah membayar dimulai dari **bulan berjalan**.

**Bulan gratis** — tiap kelipatan 12 bulan memberi 1 bulan gratis
(`intdiv(months, 12)`), dikenakan pada bulan-bulan **terakhir** dalam rencana
dengan diskon sebesar nominal penuh.

| Diminta   | Periode dibuat | Gratis | Ditagih   |
| --------- | -------------- | ------ | --------- |
| 11 bulan  | 11             | 0      | 11 bulan  |
| 12 bulan  | 12             | 1      | 11 bulan  |
| 23 bulan  | 23             | 1      | 22 bulan  |
| 24 bulan  | 24             | 2      | 22 bulan  |

Jumlah periode **sama dengan** angka yang diminta — bulan gratis diambil dari
dalam rentang itu, bukan ditambahkan di belakangnya.

Perhitungannya sengaja ditaruh di server dan tidak diulang di browser: kalau
aturan bulan gratis ditulis di dua tempat, cepat atau lambat angka di layar
berbeda dari yang tersimpan.

### Response (200)

```json
{
  "member_id": 12,
  "rate_id": 1,
  "months": 12,
  "free_months": 1,
  "start_period": "08/2026",
  "end_period": "07/2027",
  "total": "550000.00",
  "transactions": [
    { "payment_period": "08/2026", "amount": "50000.00", "discount": "0.00", "total": "50000.00", "free": false },
    { "payment_period": "07/2027", "amount": "50000.00", "discount": "50000.00", "total": "0.00", "free": true }
  ]
}
```

`total` di sini sama dengan `total` yang nanti dihitung `POST /transaksi/header`
dari rincian yang sama — keduanya memakai rumus `amount - discount` per baris.

---

## 1. index

**Method:** GET · **Endpoint:** /api/nafsul/transaksi · **Auth:** Bearer Token (wajib)

| Query         | Type    | Keterangan                                     |
| ------------- | ------- | ---------------------------------------------- |
| `search`      | string  | Cari di nama & no. anggota                     |
| `member_id`   | integer | Saring per anggota                             |
| `transaction_header_id` | integer | Saring rincian milik satu kuitansi    |
| `rate_id`     | integer | Saring per tarif                               |
| `period_from` | string  | "MM/YYYY" — batas bawah periode                |
| `period_to`   | string  | "MM/YYYY" — batas atas periode                 |
| `per_page`    | integer | Bawaan 25                                      |

`period_from` dan `period_to` berdiri sendiri: mengisi salah satunya saja tetap
sah.

Diurutkan `payment_period DESC`, lalu `id DESC`. Periode bisa sama antar baris;
`id` dipakai sebagai pemecah seri agar urutannya tidak berubah-ubah antar halaman
sehingga ada baris yang terlewat atau tampil dua kali.

Response: objek paginator Laravel dengan `data` berisi bentuk hasil `transform()`.

---

## 2. store

**Method:** POST · **Endpoint:** /api/nafsul/transaksi · **Auth:** Bearer Token (wajib)

| Parameter        | Type    | Required | Keterangan                        |
| ---------------- | ------- | -------- | --------------------------------- |
| `transaction_header_id` | integer | Tidak | `exists:transaction_headers,id` |
| `member_id`      | integer | Ya       | `exists:members,id`               |
| `rate_id`        | integer | Ya       | `exists:rates,id`                 |
| `payment_period` | string  | Ya       | "MM/YYYY", contoh `08/2026`       |
| `amount`         | numeric | Ya       | ≥ 0                               |
| `discount`       | numeric | Tidak    | ≥ 0, bawaan 0, tidak boleh > `amount` |

### Success (201)

```json
{
  "id": 1,
  "uuid": "722b8d21-26a8-43bc-bddb-ce4de9e0977d",
  "transaction_header_id": 3,
  "transaction_number": "260821001",
  "member_id": 12,
  "member_number": "ZT001",
  "member_name": "Ahmad Fauzi",
  "rate_id": 1,
  "rate_code": "IUR-01",
  "rate_name": "Iuran Bulanan",
  "payment_period": "08/2026",
  "amount": "50000.00",
  "discount": "5000.00",
  "total": "45000.00",
  "created_at": "2026-08-21 07:58:40"
}
```

### Error (422)

```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "payment_period": ["Anggota ini sudah punya transaksi untuk tarif dan periode tersebut."]
  }
}
```

Pesan lain yang mungkin muncul:

| Field            | Pesan                                                        |
| ---------------- | ------------------------------------------------------------ |
| `payment_period` | Periode pembayaran harus berformat MM/YYYY, contoh 08/2026.  |
| `discount`       | Diskon tidak boleh melebihi nominal.                         |
| `member_id`      | Anggota tidak ada di master.                                 |
| `rate_id`        | Tarif tidak ada di master.                                   |

Diskon melebihi nominal menghasilkan tagihan negatif — hampir selalu salah ketik,
dan lebih baik ditolak daripada tersimpan diam-diam.

---

## 3. show / update / destroy

**Endpoint:** /api/nafsul/transaksi/{uuid} · **Auth:** Bearer Token (wajib)

URL memakai `uuid`, bukan `id` — alasannya sama dengan header, lihat
`TransaksiHeaderController.md`. **Relasi antar tabel tetap memakai `id`.**

`update` memakai aturan yang sama dengan `store`, hanya saja baris yang sedang
diubah dikecualikan dari pemeriksaan duplikat periode — tanpa itu, menyimpan
transaksi tanpa mengubah periodenya akan ditolak oleh barisnya sendiri.

`destroy` melakukan soft delete (trait `HasAuditColumns`).

---

## Menu

Migrasi `2026_08_22_000002_add_nafsul_transaksi_menu` membuat title menu
**"Nafsul"** berisi satu menu **"Transaksi"** (`/nafsul/transaksi`, ikon
`wallet`), lalu memberikannya ke seluruh authority yang ada.

Baris `authority_menu` itu wajib: tanpanya menu ada di database tapi tidak pernah
muncul di sidebar siapa pun — sidebar dibangun dari menu milik authority
pengguna, bukan dari seluruh isi tabel `menus`.

Master Nafsul tetap berada di title "Master Data" → grup "Master Nafsul". Yang
dipisahkan ke title sendiri hanya transaksinya, karena itu pekerjaan harian dan
bukan pengaturan data acuan.

Aturan per baris (format periode, batas diskon, larangan duplikat periode)
dipakai bersama dengan jalur simpan massal lewat trait
`App\Traits\HandlesTransactionRows`, supaya baris yang ditolak di sini juga
ditolak saat dikirim sebagai bagian dari kuitansi.

Header-nya didokumentasikan terpisah di `TransaksiHeaderController.md` —
termasuk cara menyimpan kuitansi beserta seluruh rinciannya dalam satu
permintaan.

Halaman frontend-nya (fe-care-pulse):

| Route                   | Berkas                                        | Isi                          |
| ----------------------- | --------------------------------------------- | ---------------------------- |
| `/nafsul/transaksi`      | `app/(app)/nafsul/transaksi/page.tsx`         | Daftar kuitansi + lihat & hapus |
| `/nafsul/transaksi/baru` | `app/(app)/nafsul/transaksi/baru/page.tsx`    | Form kuitansi + rincian      |

Cache daftarnya di `lib/store/slices/nafsulTransaksiSlice.ts`. Halaman form
memanggil `invalidateTransaksi()` sebelum kembali ke daftar — tanpa itu daftar
yang sudah di-cache tidak dimuat ulang dan kuitansi baru tidak muncul.
