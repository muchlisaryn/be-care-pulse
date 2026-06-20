# PRD — MedAssist Backend

**Produk:** MedAssist — Sistem Manajemen Inventaris & Sterilisasi Instrumen Medis (CSSD)
**Platform:** Laravel 12 (PHP 8.2+) REST API
**Database:** MySQL (aktif), kompatibel SQLite/PostgreSQL
**Versi Dokumen:** 1.2
**Tanggal:** 2026-06-08
**Sumber Kebenaran:** Disusun dari skema database (migrations) & model yang aktif saat ini.

> **Perubahan v1.2 (terhadap PDF v1.1):**
> - Modul **Peminjaman (Order/OrderItem)** — sebelumnya "rencana" — kini **terimplementasi penuh** (model + controller + alur status + sinkron status unit).
> - Kolom `room_id` pada `users` **dihapus** sesuai arahan v1.1 §7.
> - **[BARU] Modul Sterilisasi CSSD** (`sterilizations` + `sterilization_items`): batch/siklus sterilisasi, metode, indikator kimia & biologis, dan **masa berlaku steril (expiry)**.
> - **[BARU] Set/Tray instrumen** (`instrument_sets` + `instrument_set_items`): kelompok unit yang dikelola sebagai satu paket.
> - **[BARU] Riwayat pergerakan unit** (`instrument_stock_logs`): catatan append-only setiap perubahan status unit, otomatis dari konteks (create/manual/order/sterilization).
> - **[BARU] Endpoint expiry alert**: daftar batch steril yang sudah/akan kadaluarsa.

---

## 1. Konsep Domain

| Entitas | Deskripsi |
|---|---|
| Instrument | Jenis/katalog instrumen medis. `code` unik + `name`. |
| InstrumentStock | Unit fisik individual sebuah Instrument. `code` auto `{KODE}-NNN`, `condition_id`, `status`. |
| InstrumentStockLog | Riwayat append-only perubahan status sebuah unit (tracking). |
| Condition | Lookup kondisi instrumen (mis. Baik, Rusak). |
| Room | Ruangan rumah sakit. `code` 4-huruf unik. |
| InstrumentSet | Set/tray: kumpulan unit yang dikelola sebagai satu paket. `code` auto `SET-NNN`. |
| InstrumentSetItem | Anggota set: satu unit fisik dalam sebuah set. |
| User / Authority | Akun & peran/hak akses (menentukan menu dinamis). |
| Menu / TitleMenu | Struktur navigasi dinamis per Authority. |
| Order / OrderItem | Peminjaman instrumen (header + unit yang dipinjam). `code` auto `ORD-NNN`. |
| Sterilization / SterilizationItem | Batch/siklus sterilisasi + unit di dalamnya. `code` auto `STR-NNN`. |

**Relasi inti:**
- `Instrument` (1)→(N) `InstrumentStock`
- `InstrumentStock` (1)→(N) `InstrumentStockLog`
- `InstrumentSet` (1)→(N) `InstrumentSetItem` (N)→(1) `InstrumentStock`
- `Order` (1)→(N) `OrderItem` (N)→(1) `InstrumentStock`
- `Sterilization` (1)→(N) `SterilizationItem` (N)→(1) `InstrumentStock`

---

## 2. Status & Enum

### 2.1 Status Unit (`instrument_stocks.status`)
`tersedia` (default) · `dipinjam` · `sterilisasi` · `dikembalikan`

### 2.2 Status Order (`order.status`)
`diajukan` (default) → `disetujui` → `dipinjam` → `dikembalikan` · `dibatalkan`
- `dipinjam` → unit terkait → `dipinjam`
- `dikembalikan` / `dibatalkan` → unit terkait → `tersedia`

### 2.3 Status Sterilisasi (`sterilizations.status`)
`diproses` (default) · `selesai` · `gagal`
- `diproses` / `gagal` → unit → `sterilisasi`
- `selesai` → unit → `tersedia` (steril & siap pakai)
- Metode (`method`): `uap` · `eo` · `plasma` · `panas_kering`

### 2.4 Konteks Log Unit (`instrument_stock_logs.context`)
`create` · `manual` · `order` · `sterilization`

---

## 3. Skema Database (modul baru v1.2)

### 3.1 order / order_item
Lihat PRD v1.1 §3.10–3.11 (skema tidak berubah; kini terimplementasi).

### 3.2 sterilizations
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| code | string unique | Auto `STR-NNN` |
| machine | string | Nama/no. mesin autoclave |
| method | string default `uap` | uap/eo/plasma/panas_kering |
| cycle_number | string nullable | No. siklus mesin |
| temperature | decimal(5,2) nullable | Suhu (°C) |
| duration_minutes | unsigned int nullable | Durasi (menit) |
| operator | string nullable | Operator pelaksana |
| sterilized_at | datetime | Waktu proses |
| expiry_date | date nullable | Masa berlaku steril |
| chemical_indicator | string nullable | Hasil indikator kimia |
| biological_indicator | string nullable | Hasil indikator biologis |
| status | string default `diproses` | diproses/selesai/gagal |
| note | text nullable | |
| + audit columns | | created_by, updated_by, deleted_at, deleted_by |

### 3.3 sterilization_items
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| sterilization_id | FK → sterilizations | cascadeOnDelete |
| instrument_stock_id | FK → instrument_stocks | restrictOnDelete |
| result | string nullable | Hasil per unit (opsional) |
| + audit columns | | unique(sterilization_id, instrument_stock_id) |

### 3.4 instrument_sets
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| code | string unique | Auto `SET-NNN` |
| name | string | mis. "Set Bedah Minor" |
| room_id | FK → rooms nullable | nullOnDelete |
| status | string default `tersedia` | mengikuti enum status unit |
| note | text nullable | |
| + audit columns | | |

### 3.5 instrument_set_items
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| instrument_set_id | FK → instrument_sets | cascadeOnDelete |
| instrument_stock_id | FK → instrument_stocks | restrictOnDelete |
| + audit columns | | unique(instrument_set_id, instrument_stock_id) |

### 3.6 instrument_stock_logs (append-only — tanpa soft delete)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| instrument_stock_id | FK → instrument_stocks | cascadeOnDelete |
| from_status | string nullable | Status sebelum (null saat create) |
| to_status | string | Status sesudah |
| context | string nullable | create/manual/order/sterilization |
| reference_code | string nullable | mis. ORD-001, STR-001 |
| note | text nullable | |
| created_by | string nullable | + created_at/updated_at |

---

## 4. Fitur & Endpoint (tambahan v1.2)

Base URL `/api`. Semua endpoint butuh Bearer Token (Sanctum).

### F5 — Peminjaman Instrumen (`/api/master/orders`) — **terimplementasi**
`apiResource('orders')`: index (search+status+paginate), store, show, update (alur status + pengembalian per-item), destroy.

### F6 — Sterilisasi CSSD (`/api/master/sterilizations`)
| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/sterilizations` | List (search + filter status/method) |
| GET | `/sterilizations/expiring` | Batch steril yang sudah/akan kadaluarsa (`?days=`) |
| POST | `/sterilizations` | Buat batch (status `diproses`, unit → sterilisasi) |
| GET | `/sterilizations/{id}` | Detail batch + unit |
| PUT/PATCH | `/sterilizations/{id}` | Ubah status (sinkron unit) & data batch |
| DELETE | `/sterilizations/{id}` | Soft delete |

### F7 — Set/Tray Instrumen (`/api/master/instrument-sets`)
`apiResource('instrument-sets')`: CRUD + sinkronisasi anggota unit lewat field `items` pada store/update.

### F8 — Riwayat Pergerakan Unit
| Method | Endpoint | Fungsi |
|---|---|---|
| GET | `/instrument-stocks/{id}/logs` | Riwayat perubahan status unit (terbaru dulu) |

---

## 5. Catatan Implementasi

- **Sinkron status unit** dilakukan via `InstrumentStock::transitionMany($ids, $status, $meta)` agar event logging & audit (`updated_by`) tetap jalan — jangan pakai `whereIn()->update()` langsung untuk perubahan status.
- **Logging otomatis** lewat model event `created`/`updated` pada `InstrumentStock` (mencatat saat `status` berubah). Konteks & referensi diteruskan via properti transien `InstrumentStock::$logMeta`.
- **Dokumentasi endpoint** mengikuti konvensi repo: folder per-controller, file per-function (`dokumentasi/{Subfolder}/{Controller}/{function}.md`).
- **Expiry alert** saat ini berbasis batch (`status=selesai` + `expiry_date`). Bila perlu akurasi per-unit (unit yang disteril ulang), perlu agregasi batch terbaru per unit.
