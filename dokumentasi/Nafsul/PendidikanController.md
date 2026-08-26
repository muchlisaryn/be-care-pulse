# PendidikanController

**Controller:** App\Http\Controllers\Nafsul\PendidikanController
**Base URL:** /api/nafsul/pendidikan

Master pendidikan. Tabel & kolomnya berbahasa Inggris (`educations.name`),
sedangkan kontrak API memakai `nama` — penerjemahannya ditangani model
`Education`.

Master ini tidak punya kolom kode; kuncinya `id` bawaan database. Karena itu
form anggota merujuknya lewat `pendidikan_id`, bukan kode.

---

## Keunikan `nama` (mengabaikan huruf besar-kecil)

`"SMA"`, `"sma"`, dan `"SmA"` terhitung **satu** pendidikan yang sama. Dijaga di
tiga lapis:

| Lapis                        | Cakupan                                                    |
| ---------------------------- | ---------------------------------------------------------- |
| Validasi `store` / `update`  | `Rule::unique` — case-insensitive lewat collation kolom     |
| Pemeriksaan `import`         | `LOWER(name)` eksplisit, termasuk baris terhapus            |
| Index `educations_name_unique` | Jaring pengaman untuk permintaan yang berjalan bersamaan |

Collation kolomnya `utf8mb4_unicode_ci`, yang memang sudah mengabaikan huruf
besar-kecil. Meski begitu, `import` tetap menulis perbandingannya eksplisit
dengan `LOWER()`: kalau disandarkan pada collation saja, aturannya tidak terlihat
di kode dan akan berubah diam-diam bila collation-nya suatu saat diganti.

Cakupan `store` dan `import` sengaja berbeda:

- `store` memakai `->whereNull('deleted_by')` lalu `createOrRestore()`. Nama yang
  pernah dipakai baris terhapus **dipulihkan**, bukan ditolak.
- `import` memakai `withTrashed()` dan **menolak**. Impor massal tidak boleh
  diam-diam menimpa atau menghidupkan kembali data yang sudah ada. Cakupan ini
  juga menyamai index unik di database — kalau lebih sempit, baris lolos validasi
  lalu gagal dengan galat SQL mentah.

---

## 1. index

**Method:** GET · **Endpoint:** /api/nafsul/pendidikan · **Auth:** Bearer Token (wajib)

| Query      | Type    | Keterangan                                          |
| ---------- | ------- | --------------------------------------------------- |
| `search`   | string  | Cari di nama                                        |
| `all`      | boolean | `1` = seluruh baris tanpa paginasi (untuk dropdown) |
| `per_page` | integer | Bawaan 25                                           |

Selalu diurutkan menurut `name`.

---

## 2. store

**Method:** POST · **Endpoint:** /api/nafsul/pendidikan · **Auth:** Bearer Token (wajib)

| Parameter | Type   | Required | Keterangan            |
| --------- | ------ | -------- | --------------------- |
| `nama`    | string | Ya       | Maks 255, unik (CI)   |

### Error (422)

```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "nama": ["Pendidikan \"sma\" sudah ada."] }
}
```

---

## 3. show / update / destroy

**Endpoint:** /api/nafsul/pendidikan/{education} · **Auth:** Bearer Token (wajib)

`update` memakai aturan yang sama dengan `store`, hanya saja baris yang sedang
diubah dikecualikan dari pemeriksaan keunikan — tanpa itu, menyimpan tanpa
mengubah namanya akan ditolak oleh namanya sendiri.

`destroy` melakukan soft delete (trait `HasAuditColumns`).

---

## 4. import

**Method:** POST · **Endpoint:** /api/nafsul/pendidikan/import · **Auth:** Bearer Token (wajib)

Frontend membaca file Excel-nya sendiri lalu mengirim maksimal 50 baris per
permintaan. Alur perulangannya ada di trait `App\Traits\ImportsExcelRows`.

### Body Parameters

| Parameter | Type  | Required | Keterangan                                    |
| --------- | ----- | -------- | --------------------------------------------- |
| `rows`    | array | Ya       | 1–50 baris                                    |
| `rows.*`  | array | Ya       | Satu baris; `baris` = no. baris di file Excel |

### Kolom per baris

| Kolom  | Wajib | Keterangan                                          |
| ------ | ----- | --------------------------------------------------- |
| `nama` | Ya    | Ditolak bila sudah ada, tanpa membedakan huruf besar-kecil |

Sel kosong di Excel bisa datang sebagai string kosong maupun spasi saja;
`ambilKolom()` menyeragamkannya jadi `null` supaya aturan `required` tidak
meloloskannya.

### Success (200)

```json
{
  "berhasil": 2,
  "gagal": 1,
  "hasil": [
    { "baris": 2, "status": "ok", "id": 15, "nama": "SMA" },
    { "baris": 3, "status": "ok", "id": 16, "nama": "Diploma" },
    {
      "baris": 4,
      "status": "gagal",
      "nama": "sma",
      "pesan": "Pendidikan \"sma\" sudah ada (tercatat sebagai \"SMA\")."
    }
  ]
}
```

Pesan gagalnya ikut menyebutkan ejaan yang sudah tercatat, supaya pengguna tahu
baris mana di datanya yang bentrok — tanpa itu, "sudah ada" terasa keliru bagi
pengguna yang merasa belum pernah memasukkan nama tersebut.

Status HTTP tetap **200** meski ada baris yang gagal — kegagalan per baris adalah
hasil normal untuk impor massal, bukan kegagalan permintaannya.

Modal impornya: `components/nafsul/ImportPendidikanModal.tsx` (fe-care-pulse).
