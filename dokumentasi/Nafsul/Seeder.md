# Seeder Master Nafsul

Data awal seluruh master modul Nafsul. Semuanya `firstOrCreate` per kunci, jadi
**aman dijalankan berulang** dan tidak menimpa data yang sudah disunting
petugas.

```bash
php artisan db:seed                                  # seluruhnya (lewat DatabaseSeeder)
php artisan db:seed --class=TarifSeeder              # satu master saja
```

| Seeder                 | Tabel              | Isi                                  |
| ---------------------- | ------------------ | ------------------------------------ |
| `WilayahSeeder`        | `regions`          | 10 wilayah                           |
| `KotaSeeder`           | `cities`           | 35 kota                              |
| `StatusAnggotaSeeder`  | `member_statuses`  | 5 status                             |
| `KetuaKelompokSeeder`  | `group_leaders`    | "Pribadi" + 3 contoh ketua           |
| `TarifSeeder`          | `rates`            | 3 iuran + 4 kas keluar               |
| `PendidikanSeeder`     | `educations`       | (sudah ada sebelumnya)               |
| `PekerjaanSeeder`      | `occupations`      | (sudah ada sebelumnya)               |
| `StatusNikahSeeder`    | `marital_statuses` | (sudah ada sebelumnya)               |

---

## Dua baris yang bukan data contoh

Sebagian besar isi seeder ini boleh dihapus atau diganti. **Dua baris berikut
tidak** — aplikasi ikut rusak kalau keduanya hilang.

### `member_statuses.STS1` — "Aktif"

Form pendaftaran anggota memakainya sebagai status bawaan (`STATUS_BAWAAN` di
`app/(app)/nafsul/master/anggota/baru/page.tsx`). Tanpa baris ini, **setiap
pendaftaran anggota baru gagal** dengan galat validasi
`exists:member_statuses,code`, padahal petugas tidak mengubah apa pun di form.

Kalau kodenya memang perlu diganti, ubah juga konstanta di halaman tersebut.

### `group_leaders` bernama "Pribadi"

Bukan orang, melainkan penampung anggota perorangan.
`AnggotaController::filterTipe()` memisahkan anggota pribadi dari anggota
kelompok dengan mencocokkan nama ketuanya ke `GroupLeader::NAMA_PRIBADI`.

Namanya dicocokkan **persis** — master ketua juga memuat nama orang yang
kebetulan mengandung kata itu (mis. "Filosa Idham Pribadi"), jadi pencocokan
`LIKE` akan salah menghitung. Karena itu jangan mengubahnya jadi
"Pribadi/Mandiri" atau semacamnya tanpa ikut mengubah konstantanya.

Tanpa baris ini, statistik "pribadi" selalu 0 dan anggota perorangan tidak punya
tempat.

---

## Nilai yang harus persis: `rates.category`

Satu tabel `rates` menampung dua hal yang dipisahkan kolom `category`. Halaman
frontend menyaring dengan nilai itu, jadi salah ketik berarti barisnya tidak
pernah muncul di mana pun:

| `category`   | Halaman                          |
| ------------ | -------------------------------- |
| `iuran`      | /nafsul/master/tarif/iuran       |
| `kas_keluar` | /nafsul/master/tarif/kas-keluar  |

Tarif berkategori `iuran` juga dipakai halaman Transaksi: `price`-nya menjadi
nominal bawaan tiap periode saat petugas memasukkan jumlah bulan.

---

## Yang perlu diganti dengan data sebenarnya

Isi seeder ini **titik awal, bukan data resmi organisasi**:

- **Wilayah & kota** — daftar wilayah Jabodetabek dan kota besar Indonesia.
  Kode kotanya sengaja berurut sederhana (`KT01`, `KT02`, …), **bukan kode
  BPS**: kode BPS tidak diverifikasi di sini, dan kode yang salah lebih
  menyesatkan daripada kode yang jelas-jelas internal.
- **Ketua kelompok** — tiga nama contoh, silakan hapus.
- **Nominal tarif** — angka bulat sebagai contoh.

Ganti lewat halaman masternya atau fitur impor Excel. Perlu diingat: **kode
adalah yang dirujuk anggota** (`kode_wilayah`, `kode_kota_lahir`,
`kode_status`, `noketua`), jadi mengubah kode setelah ada anggota terdaftar akan
memutus kaitannya. Ubah namanya saja bila memungkinkan.

---

## Catatan `WithoutModelEvents`

`DatabaseSeeder` memakai trait `WithoutModelEvents`, sehingga model event
(`creating`/`updating`) **tidak berjalan** selama `php artisan db:seed`.
Akibatnya kolom audit (`created_by`, `updated_by`) dibiarkan null untuk baris
hasil seeder. Itu memang diinginkan — tidak ada pengguna yang login saat seeder
berjalan.

Menjalankan satu seeder lewat `--class=` tidak melewati `DatabaseSeeder`,
sehingga event tetap berjalan dan kolom audit terisi bila ada sesi login.
