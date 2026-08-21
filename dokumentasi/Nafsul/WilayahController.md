# WilayahController

**Controller:** App\Http\Controllers\Nafsul\WilayahController
**Base URL:** /api/nafsul/wilayah

Master wilayah. Tabel & kolomnya berbahasa Inggris (`regions.code`,
`regions.name`), sedangkan kontrak API memakai `kode` / `nama` — penerjemahannya
ditangani model `Region`.

---

## Keunikan `kode`

`kode` (kolom `regions.code`) wajib unik. Dijaga di dua lapis:

| Lapis                     | Fungsi                                                       |
| ------------------------- | ------------------------------------------------------------ |
| Validasi (`Rule::unique`) | Menolak dengan **422** dan pesan yang bisa dibaca pengguna    |
| Index `regions_code_unique` | Jaring pengaman untuk dua permintaan yang berjalan bersamaan |

Collation kolomnya `utf8mb4_unicode_ci`, jadi perbandingannya **mengabaikan beda
huruf besar-kecil**: `"AB1"` dan `"ab1"` terhitung kode yang sama.

Cakupan pemeriksaan berbeda antara `store` dan `import`, dan itu disengaja:

- `store` memakai `->whereNull('deleted_by')` lalu `createOrRestore()`. Kode yang
  pernah dipakai baris terhapus **dipulihkan**, bukan ditolak.
- `import` memakai `withTrashed()` dan **menolak**. Impor massal tidak boleh
  diam-diam menimpa atau menghidupkan kembali data yang sudah ada.

---

## 1. index

**Method:** GET · **Endpoint:** /api/nafsul/wilayah · **Auth:** Bearer Token (wajib)

| Query      | Type    | Keterangan                                    |
| ---------- | ------- | --------------------------------------------- |
| `search`   | string  | Cari di kode & nama                           |
| `all`      | boolean | `1` = seluruh baris tanpa paginasi (untuk dropdown) |
| `per_page` | integer | Bawaan 25                                     |

---

## 2. store

**Method:** POST · **Endpoint:** /api/nafsul/wilayah · **Auth:** Bearer Token (wajib)

| Parameter | Type   | Required | Keterangan       |
| --------- | ------ | -------- | ---------------- |
| `kode`    | string | Ya       | Maks 50, unik    |
| `nama`    | string | Ya       | Maks 255         |

### Error (422)

```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "kode": ["Kode \"01\" sudah dipakai wilayah lain."] }
}
```

---

## 3. show / update / destroy

**Endpoint:** /api/nafsul/wilayah/{region} · **Auth:** Bearer Token (wajib)

`update` hanya menerima `nama` — kode tidak bisa diubah karena sudah dirujuk
master kota dan data anggota (`kode_wilayah`).

`destroy` melakukan soft delete (trait `HasAuditColumns`).

---

## 4. import

**Method:** POST · **Endpoint:** /api/nafsul/wilayah/import · **Auth:** Bearer Token (wajib)

Frontend membaca file Excel-nya sendiri lalu mengirim maksimal 50 baris per
permintaan. Alur perulangannya ada di trait `App\Traits\ImportsExcelRows`.

### Body Parameters

| Parameter | Type  | Required | Keterangan                                    |
| --------- | ----- | -------- | --------------------------------------------- |
| `rows`    | array | Ya       | 1–50 baris                                    |
| `rows.*`  | array | Ya       | Satu baris; `baris` = no. baris di file Excel |

### Kolom per baris

| Kolom  | Wajib | Keterangan                    |
| ------ | ----- | ----------------------------- |
| `kode` | Ya    | Ditolak bila sudah terpakai   |
| `nama` | Ya    |                               |

Sel kosong di Excel bisa datang sebagai string kosong maupun spasi saja;
`ambilKolom()` menyeragamkannya jadi `null` supaya aturan `required` tidak
meloloskannya.

### Success (200)

```json
{
  "berhasil": 2,
  "gagal": 1,
  "hasil": [
    { "baris": 2, "status": "ok", "kode": "01", "nama": "Jakarta Timur" },
    { "baris": 3, "status": "ok", "kode": "02", "nama": "Jakarta Barat" },
    { "baris": 4, "status": "gagal", "nama": "Kode Kembar", "pesan": "Kode 01 sudah dipakai wilayah lain." }
  ]
}
```

Status HTTP tetap **200** meski ada baris yang gagal — kegagalan per baris adalah
hasil normal untuk impor massal, bukan kegagalan permintaannya.

Modal impornya: `components/nafsul/ImportWilayahModal.tsx` (fe-care-pulse).
