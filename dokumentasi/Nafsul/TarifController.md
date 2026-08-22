# TarifController

**Controller:** App\Http\Controllers\Nafsul\TarifController
**Base URL:** /api/nafsul/tarif

Master tarif iuran & kas keluar. Tabel & kolomnya berbahasa Inggris (`rates`),
sedangkan kontrak API memakai `kode` / `nama` / `harga` / `kategori` —
penerjemahannya ditangani model `Rate`. URL memakai **kode tarif**, bukan id.

---

## Kolom `kategori` & `fee_type`

Dua kolom yang membedakan baris, dan keduanya dipakai frontend sebagai penyaring:

| `kategori`   | Halaman                         |
| ------------ | ------------------------------- |
| `iuran`      | /nafsul/master/tarif/iuran      |
| `kas_keluar` | /nafsul/master/tarif/kas-keluar |

| `fee_type`  | Arti                                                              |
| ----------- | ----------------------------------------------------------------- |
| `recurring` | Berulang tiap periode — `harga` dikalikan jumlah bulan di Transaksi |
| `one_time`  | Sekali bayar — nominalnya berdiri sendiri                          |

`fee_type` **boleh kosong** (`null`) dan itu berarti "belum diklasifikasi":
kolomnya menyusul lewat migrasi `add_fee_type_to_rates_table`, jadi baris yang
lahir sebelum itu tetap NULL sampai diisi lewat `update` atau `TarifSeeder`.

Berbeda dengan field lain, `fee_type` dikirim & dibaca **apa adanya dalam bahasa
Inggris** — kolomnya baru, jadi tidak punya padanan nama lama yang perlu dijaga.

---

## 1. index

**Method:** GET · **Endpoint:** /api/nafsul/tarif · **Auth:** Bearer Token (wajib)

| Query      | Type    | Keterangan                                          |
| ---------- | ------- | --------------------------------------------------- |
| `kategori` | string  | `iuran` / `kas_keluar`                              |
| `fee_type` | string  | `recurring` / `one_time`                            |
| `search`   | string  | Cari di kode, nama, & kode tarif                    |
| `all`      | boolean | `1` = seluruh baris tanpa paginasi (untuk dropdown) |
| `per_page` | integer | Bawaan 25                                           |

Diurutkan berdasarkan `kode`.

---

## 2. store

**Method:** POST · **Endpoint:** /api/nafsul/tarif · **Auth:** Bearer Token (wajib)

| Parameter    | Type    | Required | Keterangan                       |
| ------------ | ------- | -------- | -------------------------------- |
| `kode`       | string  | Ya       | Maks 50, unik                    |
| `nama`       | string  | Ya       | Maks 255                         |
| `harga`      | numeric | Ya       | Minimal 0                        |
| `kategori`   | string  | Tidak    | Maks 50                          |
| `fee_type`   | string  | Tidak    | `recurring` / `one_time`         |
| `grup_tarif` | string  | Tidak    | Maks 50                          |
| `nama_grup`  | string  | Tidak    | Maks 255                         |
| `kode_tarif` | string  | Tidak    | Maks 50                          |
| `keterangan` | string  | Tidak    | —                                |

**Sukses:** 201. Kode milik baris yang sudah di-soft-delete **dipulihkan**
(`createOrRestore`), bukan ditolak.

---

## 3. show

**Method:** GET · **Endpoint:** /api/nafsul/tarif/{kode} · **Auth:** Bearer Token (wajib)

---

## 4. update

**Method:** PUT · **Endpoint:** /api/nafsul/tarif/{kode} · **Auth:** Bearer Token (wajib)

Parameternya sama dengan `store` **tanpa `kode` dan `kategori`** — keduanya tidak
bisa diubah setelah tarif dibuat.

### Error (422)

```json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "fee_type": ["The selected fee type is invalid."] }
}
```

---

## 5. destroy

**Method:** DELETE · **Endpoint:** /api/nafsul/tarif/{kode} · **Auth:** Bearer Token (wajib)

Soft delete (`deleted_by` diisi). Response: `{ "message": "Tarif dihapus." }`
