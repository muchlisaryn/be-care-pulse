# AnggotaController

**Controller:** App\Http\Controllers\Nafsul\AnggotaController
**Base URL:** /api/nafsul/anggota

Tabel & kolom database berbahasa Inggris (`members`, `region_id`, …), sedangkan
kontrak API memakai nama lama (`nama`, `kode_wilayah`, …). Penerjemahannya
ditangani trait `HasLegacyAttributes` di model `Member`.

---

## Nomor Anggota

`no_anggota` (kolom `members.member_number`) dibentuk dari tanggal aktif:

```
YY  MM  DD  NN
26  08  21  01     →  "26082101"
```

- `YYMMDD` diambil dari `tgl_aktif`; bila kosong dipakai tanggal hari ini.
- `NN` adalah urutan **per hari**, dihitung ulang setiap tanggal berganti.
- Anggota keluarga mewarisi nomor anggota utama + urutan:
  `26082101-01`, `26082101-02`, …

Nomor dibuat otomatis **hanya bila `no_anggota` dikosongkan**. Bila diisi, nilai
dari klien yang dipakai.

### Keunikan

Dijaga di dua lapis, dan keduanya memang diperlukan:

| Lapis                     | Fungsi                                                      |
| ------------------------- | ----------------------------------------------------------- |
| Validasi (`Rule::unique`) | Menolak dengan **422** dan pesan yang bisa dibaca pengguna   |
| Index unik di database    | Jaring pengaman untuk dua permintaan yang berjalan bersamaan |

Validasi adalah pengecekan baca-lalu-tulis: dua permintaan serempak bisa
sama-sama lolos lalu sama-sama menyimpan. Index unik
`members_member_number_unique` menutup celah itu.

Pengecekan mencakup baris yang sudah di-soft-delete (`withTrashed()`), sama
persis dengan cakupan index di database — supaya validasi tidak pernah
meloloskan nomor yang justru ditolak saat disimpan.

Generator nomor juga memeriksa tiap kandidat sebelum memakainya. Data lama
memakai format berbeda (`YYMM` + 3 digit), dan nomor seperti `2608211` ikut
tertangkap pola pencarian prefix lalu terbaca sebagai urutan yang keliru. Tanpa
pemeriksaan itu, impor bisa menabrak index unik dan gagal dengan galat database
mentah.

---

## 1. index

**Method:** GET
**Endpoint:** /api/nafsul/anggota
**Auth:** Bearer Token (wajib)

### Query Parameters

| Parameter      | Type    | Keterangan                                       |
| -------------- | ------- | ------------------------------------------------ |
| `search`       | string  | Cari di nama, no. anggota, no. KTP, no. KK       |
| `kode_status`  | string  | Kode master status anggota                       |
| `kode_wilayah` | string  | Kode master wilayah                              |
| `noketua`      | string  | Kode ketua kelompok                              |
| `tipe`         | string  | `pribadi` atau `kelompok`                        |
| `aktif_bulan`  | integer | 1–12; berdiri sendiri (bulan itu di semua tahun) |
| `aktif_tahun`  | integer | Berdiri sendiri (setahun penuh)                  |
| `sort`         | string  | `nama`, `no_anggota`, `tgl_aktif`, `created_at`  |
| `dir`          | string  | `asc` atau `desc` (bawaan `asc`)                 |
| `per_page`     | integer | Bawaan 25                                        |

`sort` di luar daftar yang diizinkan jatuh ke `nama` — masukan klien tidak
pernah disisipkan langsung ke klausa `ORDER BY`.

Response: objek paginator Laravel (`data`, `current_page`, `last_page`,
`per_page`, `total`).

---

## 2. statistik

**Method:** GET
**Endpoint:** /api/nafsul/anggota/statistik
**Auth:** Bearer Token (wajib)

```json
{ "pribadi": 12, "kelompok": 340, "total": 352 }
```

Tipe ditentukan dari ketuanya: anggota perorangan ditampung ketua bernama
"Pribadi". Namanya dicocokkan **persis** — master ketua juga memuat nama orang
yang kebetulan mengandung kata itu (mis. "Filosa Idham Pribadi").

---

## 3. store

**Method:** POST
**Endpoint:** /api/nafsul/anggota
**Auth:** Bearer Token (wajib)

### Body Parameters (utama)

| Parameter         | Type   | Required | Keterangan                                     |
| ----------------- | ------ | -------- | ---------------------------------------------- |
| `nama`            | string | Ya       | Maks 255                                       |
| `no_anggota`      | string | Tidak    | Dibuat otomatis bila kosong. Harus unik.       |
| `tgl_aktif`       | date   | Tidak    | Dasar penomoran otomatis                       |
| `kode_wilayah`    | string | Tidak    | `exists:regions,code`                          |
| `noketua`         | string | Tidak    | `exists:group_leaders,code`                    |
| `kode_kota_lahir` | string | Tidak    | `exists:cities,code`                           |
| `kode_status`     | string | Tidak    | `exists:member_statuses,code`                  |
| `pendidikan_id`   | int    | Tidak    | `exists:educations,id`                         |
| `pekerjaan_id`    | int    | Tidak    | `exists:occupations,id`                        |
| `jenis_kelamin`   | string | Tidak    | `L` atau `P`                                   |
| `keluarga`        | array  | Tidak    | Anggota keluarga; nomornya diturunkan otomatis |

### Success (201)

Objek anggota beserta relasi (`wilayah`, `ketua`, `kotaLahir`, `status`,
`pendidikan`, `pekerjaan`, `keluarga`).

### Error (422) — nomor bentrok

```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "no_anggota": ["No. Anggota 26082101 sudah dipakai anggota lain."]
  }
}
```

---

## 4. show / update / destroy

**Endpoint:** /api/nafsul/anggota/{member}
**Auth:** Bearer Token (wajib)

`update` memakai aturan validasi yang sama dengan `store`, hanya saja baris yang
sedang diubah dikecualikan dari pemeriksaan keunikan — tanpa itu, menyimpan
anggota tanpa mengubah nomornya akan ditolak oleh nomornya sendiri.

`destroy` melakukan soft delete (trait `HasAuditColumns`). Nomor anggota milik
baris terhapus **tidak** dipakai ulang.

---

## 5. import

**Method:** POST
**Endpoint:** /api/nafsul/anggota/import
**Auth:** Bearer Token (wajib)

Frontend membaca file Excel-nya sendiri lalu mengirim maksimal 50 baris per
permintaan, supaya progres "x dari y" bisa ditampilkan dan file besar tidak
diproses sekaligus.

### Body Parameters

| Parameter | Type  | Required | Keterangan                                   |
| --------- | ----- | -------- | -------------------------------------------- |
| `rows`    | array | Ya       | 1–50 baris                                   |
| `rows.*`  | array | Ya       | Satu baris; `baris` = no. baris di file Excel |

Tiap baris divalidasi dan disimpan sendiri-sendiri: satu baris gagal **tidak**
membatalkan baris lain dalam batch yang sama.

Kolom `id` di template hanya rujukan ke data yang sudah ada. Impor tidak
memperbarui anggota lama, jadi baris ber-`id` ditolak daripada diam-diam membuat
duplikat.

### Perilaku `no_anggota`

| Isi kolom di Excel        | Hasil                                                           |
| ------------------------- | --------------------------------------------------------------- |
| Kosong                    | Dibuat otomatis: `YYMMDD` + urut harian                         |
| Terisi, belum dipakai     | Dipakai apa adanya                                              |
| Terisi, sudah dipakai     | Baris **gagal** — "No. Anggota … sudah dipakai anggota lain."    |
| Kembar di dalam satu file | Baris pertama tersimpan, baris berikutnya gagal                 |

Baris kembar dalam satu file tertangkap karena tiap baris disimpan langsung
sebelum baris berikutnya diproses, jadi pemeriksaan baris kedua sudah melihat
baris pertama di database.

### Success (200)

```json
{
  "berhasil": 2,
  "gagal": 1,
  "hasil": [
    {
      "baris": 2,
      "status": "ok",
      "id": 9,
      "nama": "Ahmad Fauzi",
      "no_anggota": "26082101"
    },
    {
      "baris": 3,
      "status": "ok",
      "id": 10,
      "nama": "Siti Aminah",
      "no_anggota": "26082102"
    },
    {
      "baris": 4,
      "status": "gagal",
      "nama": "Budi",
      "pesan": "No. Anggota 26082101 sudah dipakai anggota lain."
    }
  ]
}
```

Status HTTP tetap **200** meski ada baris yang gagal — kegagalan per baris adalah
hasil normal untuk impor massal, bukan kegagalan permintaannya.

### Catatan template frontend

Kolom **No. Anggota** di template impor sengaja **tidak** ditandai wajib
(`components/nafsul/ImportAnggotaModal.tsx`). Kolom wajib disaring di sisi klien
sebelum dikirim, sehingga menandainya wajib justru membuat penomoran otomatis
tidak pernah berjalan.
