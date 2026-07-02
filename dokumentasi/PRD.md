# PRD — Care Pulse Backend (be-care-pulse)

**Produk:** Care Pulse — Sistem Manajemen CSSD & Clinical Pathway Rumah Sakit
**Komponen:** Backend REST API
**Platform:** Laravel 12 (PHP 8.2+)
**Database:** MySQL (aktif) · kompatibel SQLite (test in-memory) / PostgreSQL
**Auth:** Laravel Sanctum (Bearer token)
**Realtime:** Pusher (broadcasting) — dikonsumsi frontend via Laravel Echo
**PDF:** barryvdh/laravel-dompdf · **QR:** simplesoftwareio/simple-qrcode
**Versi Dokumen:** 2.0
**Tanggal:** 2026-07-01
**Sumber Kebenaran:** disusun dari migrations, models, controllers, dan `routes/api.php` yang aktif saat ini.

> **Perubahan v2.0 (terhadap v1.2):** dokumen ditulis ulang penuh agar mencerminkan sistem aktual. Cakupan meluas jauh melampaui inventaris/sterilisasi dasar: kini mencakup **pipeline CSSD end-to-end** (Order → Cleaning → Packaging → Sterilisasi → Storage → Distribusi), **Produksi CSSD internal**, **Pinjam-alih (handover) antar peminjam**, **Monitoring & papan display**, **master mesin washer**, **BMHP**, **ICD-10**, dan **modul Clinical Pathway** lengkap (kategori, template/formulir, poin, asesmen per-pasien, varian, cetak PDF).

---

## 1. Tujuan Produk

Care Pulse mendigitalkan dua alur kerja rumah sakit:

1. **CSSD (Central Sterile Supply Department)** — melacak setiap unit instrumen medis dari permintaan (order) hingga pemrosesan (cuci, kemas, steril, simpan) dan distribusi ke ruangan, lengkap dengan riwayat pergerakan per-unit dan pelacakan masa berlaku steril.
2. **Clinical Pathway** — menyediakan template alur perawatan berbasis diagnosa (ICD-10), pengisian asesmen per-pasien dengan ceklis poin, pencatatan varian (penyimpangan), verifikasi multi-peran, dan cetak PDF.

**Sasaran pengguna:** petugas CSSD, perawat/ruangan peminjam, admin sistem, dan tim mutu (clinical pathway).

---

## 2. Arsitektur & Konvensi Lintas-Modul

### 2.1 Format Response
Semua controller memakai helper base `Controller`:

```php
$this->success(string $message, mixed $data = null, int $status = 200): JsonResponse  // status: true
$this->error(string $message, int $status = 400, array $errors = []): JsonResponse     // status: false
```

Status sukses standar: GET/PUT/DELETE = 200, POST = 201.

### 2.2 Global Exception Handler (`bootstrap/app.php`)
| Exception | HTTP | Keterangan |
|---|---|---|
| `ValidationException` | 422 | menyertakan objek `errors` |
| `ModelNotFoundException` / `NotFoundHttpException` | 404 | binding gagal / route tak terdaftar |
| `AuthenticationException` | 401 | belum login / token invalid |
| `Throwable` (catch-all) | 500 | selalu tampilkan `message`, `code`, `file`, `line` |

Setiap `store`/`update`/`destroy` wajib dibungkus `try-catch (\Throwable)` dan mengembalikan pesan error asli.

### 2.3 Audit & Soft Delete (`App\Traits\HasAuditColumns`)
Setiap tabel domain memiliki 6 kolom: `created_at/by`, `updated_at/by`, `deleted_at/by`.
- `created_by`/`updated_by` otomatis dari `auth()->user()->name`.
- **Global scope `active`** memfilter `WHERE deleted_by IS NULL`.
- `delete()` selalu soft delete; `forceDelete()` hard delete; `restore()` null-kan `deleted_at/by`.
- `withTrashed()` / `onlyTrashed()` tersedia.

### 2.4 Endpoint List
Setiap index wajib: **search** via `?search=` (minimal kolom `name`/relevan) + **pagination** `->paginate(20)`. Response paginate Laravel menyertakan `data`, `current_page`, `last_page`, `per_page`, `total`.

### 2.5 Dokumentasi Endpoint
Struktur `dokumentasi/` mengikuti struktur `app/Http/Controllers/`, dibuat & diperbarui bersamaan dengan perubahan function.

---

## 3. Modul & Domain

### 3.1 Autentikasi & Otorisasi (`Auth/`)
- **AuthController** — login (publik), register (admin), logout, `me` (profil + menu untuk rehidrasi), update profil, ganti password (mencabut sesi perangkat lain), manajemen sesi (list/revoke/revoke-all).
- **AuthorityController** — peran/hak akses. Setiap Authority menentukan **menu dinamis** yang dilihat user.
- **Menu / TitleMenu** — struktur navigasi hierarkis (title → menu → submenu) yang dirakit per Authority menjadi flat list dan dibangun ke tree di frontend.

### 3.2 Master Data (`Master/`)
| Entitas | Deskripsi |
|---|---|
| **User** | akun pengguna, terkait Authority. |
| **Condition** | lookup kondisi instrumen (Baik, Rusak, dst). |
| **Room** | ruangan RS; `code` 4-huruf unik. |
| **Instrument** | jenis/katalog instrumen; `code` unik + `name` + gambar opsional; endpoint `stats`. |
| **InstrumentStock** | unit fisik individual; `code` auto `{KODE}-NNN`, `condition_id`, `status`; **QR scan & generate label**, **logs** riwayat pergerakan. |
| **InstrumentCatalog / Item** | katalog Set/paket CSSD (definisi tray beserta komponennya) + gambar. |
| **Bmhp** | Bahan Medis Habis Pakai (consumables). |
| **Icd10** | master diagnosa ICD-10 + **impor massal Excel** (skip duplikat `code`+`version`). |
| **WasherMachine** | mesin pencuci/disinfector; `code` `WSH-NNN`; **scan barcode** sebelum cuci. |

### 3.3 Transaksi CSSD — Pipeline (`Transaction/`)

Alur unit instrumen bergerak melalui tahap-tahap berikut; status `order` merepresentasikan posisi dalam pipeline:

```
Order (diajukan) → Terima/Alokasi → Cleaning → Packaging → Sterilisasi
     → Storage (penyimpanan steril) → Distribusi (ke ruangan/RM pasien) → Dikembalikan
```

- **OrderController** — inti pipeline. `apiResource` + banyak aksi tahap:
  - Permintaan: `store`, `index` (search+status+paginate), `show`, `update`, `destroy`.
  - Scan & tracking: `scan` (ORD-NNN), `borrowable` (order pihak lain yang dipinjam).
  - Penerimaan: `allocation`, `receive`.
  - Cleaning/Packaging: `process`, `packaging`, `pack`, `pack/scan`, `pack/check`, `pack/uncheck`, `packagingComplete` (inspection checklist per-unit).
  - Sterilisasi: `readyToSterilize`, `sterilize`, `sterilize/validate` (Steril/Gagal).
  - Distribusi: `acceptDistribution` (alokasi unit steril FEFO), `readyToDistribute`, `distribute` (+ RM pasien).
- **ProductionController** — Produksi CSSD internal: mulai batch dari stok milik CSSD langsung ke tahap Cleaning (tanpa order ruangan).
- **CleaningController** — tahap cuci: `index`, `process`, `updateWashing`, `alerts` (notifikasi suhu/waktu di luar ambang mesin).
- **SterilizationController** — batch/siklus sterilisasi: CRUD + `expiring` (batch steril kadaluarsa/akan kadaluarsa `?days=`). Mencatat metode, suhu, indikator kimia & biologis, **masa berlaku steril**.
- **StorageController** — penyimpanan steril: `incoming`, `inventory`, `store` (simpan unit steril ke rak).
- **DistributionController** — serah-terima alat steril CSSD → unit/ruangan (`apiResource`).
- **OrderTransferController** — pinjam-alih (handover) antar peminjam tanpa order ulang ke CSSD: `incoming-count`, `index`, `store`, `accept`, `reject`, `cancel`.
- **MonitoringController** — `rooms` (unit dipinjam per ruangan), `incoming` (order masuk lintas user), `returned` (riwayat), `board` (papan display TV).
- **ReportController** — `reports/cssd-per-item` (satu baris per unit per batch sterilisasi).

### 3.4 Clinical Pathway (`ClinicalPathway/`)
- **CategoriClinicalPathway** — kategori/section formulir (urutan unik + label).
- **TemplateClinicalPathway** — template per diagnosa (ICD-10) + maksimal hari + keterangan + status. Tidak dihapus, hanya toggle aktif/non-aktif.
- **PointClinicalPathway** — poin & sub-poin per template; penomoran mengikuti kategori (1 → 1.1 → 1.1.1); dukung `copy-points` dari formulir lain.
- **AsesmenClinicalPathway** — pengisian CP per pasien (data pasien + kelas + ceklis poin). `savePoint` auto-save per poin; `verify` per peran (dokter/perawat/pelaksana) + batal; `pdf` cetak.
- **VarianClinicalPathway** — pencatatan varian/penyimpangan per asesmen; paraf otomatis dari username login.

---

## 4. Status & Enum

### 4.1 Status Unit (`instrument_stocks.status`)
`tersedia` (default) · `dipinjam` · `sterilisasi` · `dikembalikan` (+ status turunan pipeline pemrosesan).

### 4.2 Status Order (`order.status`)
Alur pipeline: `diajukan` → (terima) → `processing`/cleaning → packaging → sterilisasi → storage → `distribusi`/`dipinjam` → `dikembalikan` · `dibatalkan`.
- Perubahan status order menyinkronkan status unit terkait.

### 4.3 Status Sterilisasi (`sterilizations.status`)
`diproses` (default) · `selesai` · `gagal`. Metode: `uap` · `eo` · `plasma` · `panas_kering`.
- `selesai` → unit → `tersedia` (steril siap pakai). `diproses`/`gagal` → unit → `sterilisasi`.

### 4.4 Status Order Transfer
`diajukan` → `accept` / `reject` / `cancel` (handover antar peminjam).

### 4.5 Konteks Log Unit (`instrument_stock_logs.context`)
`create` · `manual` · `order` · `sterilization` (append-only, tanpa soft delete).

---

## 5. Aturan Penting Implementasi

- **Sinkron status unit** wajib via `InstrumentStock::transitionMany($ids, $status, $meta)` — **bukan** `whereIn()->update()` — agar event logging & audit (`updated_by`) tetap jalan. Konteks/referensi (mis. `ORD-001`, `STR-001`) diteruskan lewat properti transien `InstrumentStock::$logMeta`.
- **Logging otomatis** lewat model event `created`/`updated` pada `InstrumentStock`.
- **FEFO/expiry**: alokasi unit steril untuk distribusi mengikuti First-Expired-First-Out; `expiring` menghitung dari batch `selesai` + `expiry_date`.
- **Broadcasting realtime** (Pusher) memicu update papan monitoring & notifikasi order masuk di frontend.
- **Nama tabel `order`** adalah reserved keyword → `protected $table = 'order'`.

---

## 6. Referensi Endpoint

Base URL `/api`. Semua endpoint (kecuali `POST /auth/login`) memerlukan Bearer token Sanctum.
Daftar lengkap per-controller ada di `routes/api.php` dan didokumentasikan penuh (request/response) di `dokumentasi/{Subfolder}/{Controller}/{function}.md`.

| Grup | Prefix | Isi |
|---|---|---|
| Auth | `/api/auth` | login, register, logout, me, profile, change-password, sessions |
| Master | `/api/master` | authorities, title-menus, menus, users, conditions, icd10(+import), instruments(+stats,+image), instrument-stocks(+scan,+qr,+logs), instrument-catalogs(+image), bmhps, rooms, washer-machines(+scan) |
| CSSD Transaksi | `/api/master` | monitoring/*, orders(+pipeline lengkap), production, cleaning(+alerts), order-transfers, distributions, storage/*, sterilizations(+expiring), reports/cssd-per-item |
| Clinical Pathway | `/api/clinical-pathway` | categories, templates(+toggle,+points,+copy-points), points, asesmen(+savePoint,+verify,+pdf,+varian) |

---

## 7. Testing
Test memakai SQLite in-memory (`DB_DATABASE=:memory:` di `phpunit.xml`). Suite `tests/Feature/` & `tests/Unit/`. Factory domain dibuat sesuai kebutuhan fitur.

## 8. Perintah Utama
```bash
composer run setup   # setup pertama kali
composer run dev     # server + queue + logs + vite konkuren
composer run test    # php artisan test
./vendor/bin/pint    # code style
php artisan migrate:fresh --seed
```
