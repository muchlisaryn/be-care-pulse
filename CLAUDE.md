# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# First-time setup
composer run setup

# Run dev server (serves app + queue + logs + Vite concurrently)
composer run dev

# Run all tests
composer run test
# or
php artisan test

# Run a single test class or method
php artisan test --filter ExampleTest
php artisan test tests/Feature/ExampleTest.php

# Code style (Laravel Pint)
./vendor/bin/pint

# Database
php artisan migrate
php artisan migrate:fresh --seed
```

## Architecture

MedAssist is a **Laravel 12** backend (PHP 8.2+) for managing medical instrument inventory. The default database is SQLite (`database/database.sqlite`); tests run against SQLite in-memory.

### Domain Model

The core domain tracks physical instrument units, their sterilization cycles, loans, and condition over time:

- **`Instrument`** — a type/catalog of medical instrument (`code` unique, `name`)
- **`InstrumentStock`** — an individual physical unit of an instrument (`code` auto `{KODE}-NNN`, belongs to `Instrument` via `instrument_id`, linked to `Condition` via `condition_id`, `status` string). Status enum: `tersedia` (default) / `dipinjam` / `sterilisasi` / `dikembalikan`.
- **`InstrumentStockLog`** — append-only history of every status change of a unit (no soft delete). Auto-recorded via model `created`/`updated` events. Context: `create` / `manual` / `order` / `sterilization`.
- **`Condition`** — lookup table for instrument condition states (e.g. good, damaged)
- **`Room`** — hospital room where instruments are deployed (`code` 4-letter unique)
- **`InstrumentSet` / `InstrumentSetItem`** — a set/tray grouping many physical units managed as one package (`code` auto `SET-NNN`)
- **`Order` / `OrderItem`** — instrument loan (header + borrowed units). `code` auto `ORD-NNN`. Status: `diajukan`/`disetujui`/`dipinjam`/`dikembalikan`/`dibatalkan`. Table name is `order` (reserved keyword → `protected $table = 'order'`).
- **`Sterilization` / `SterilizationItem`** — sterilization batch/cycle + units in it. `code` auto `STR-NNN`. Status: `diproses`/`selesai`/`gagal`. Tracks method, temperature, indicators, and **sterile expiry date**.

Relations: `Instrument` → `InstrumentStock` (1:N); `InstrumentStock` → `InstrumentStockLog` (1:N); `Order`/`Sterilization`/`InstrumentSet` each → their `*Item` (1:N) → `InstrumentStock` (N:1).

**Unit status sync:** changing an Order/Sterilization status updates the related units' `status`. Always use `InstrumentStock::transitionMany($ids, $status, $meta)` (not `whereIn()->update()`) so logging & audit columns fire. Context/reference are passed via the transient `InstrumentStock::$logMeta` property.

Full domain & endpoint reference (v1.2): see `dokumentasi/PRD.md`.

### Audit Columns & Soft Delete Pattern

All domain models use the `App\Traits\HasAuditColumns` trait. Every domain table must have these 6 columns:

| Column | Type | Keterangan |
|---|---|---|
| `created_at` | timestamp | di-set otomatis oleh Laravel |
| `created_by` | string nullable | nama user yang login saat create |
| `updated_at` | timestamp | di-set otomatis oleh Laravel |
| `updated_by` | string nullable | nama user yang login saat update |
| `deleted_at` | timestamp nullable | di-set saat soft delete |
| `deleted_by` | string nullable | nama user yang login saat delete |

**Perilaku trait:**
- `created_by` dan `updated_by` diisi otomatis dari `auth()->user()->name` via model events `creating` / `updating`.
- **Global scope** `active` otomatis memfilter `WHERE deleted_by IS NULL` pada setiap query. `deleted_by` adalah satu-satunya penentu apakah record sudah dihapus atau belum.
- `$model->delete()` → **selalu soft delete** (set `deleted_at` + `deleted_by`), tidak pernah hard delete.
- `$model->forceDelete()` → hard delete, hanya untuk kebutuhan khusus.
- `$model->restore()` → null-kan `deleted_at` dan `deleted_by`.
- `Model::withTrashed()` → query termasuk record yang sudah di-soft-delete.
- `Model::onlyTrashed()` → query hanya record yang sudah di-soft-delete.

### Testing

Tests use SQLite in-memory (`DB_DATABASE=:memory:` in `phpunit.xml`). The `tests/Feature/` and `tests/Unit/` suites are both included by default. Only `UserFactory` exists; domain model factories need to be created as features are built out.

## Response Format & Error Handling

### Response helper (base Controller)

Semua controller extends `App\Http\Controllers\Controller` dan wajib menggunakan dua helper berikut — **jangan** gunakan `response()->json()` langsung:

```php
// Sukses — status: true
$this->success(string $message, mixed $data = null, int $status = 200): JsonResponse

// Gagal — status: false
$this->error(string $message, int $status = 400, array $errors = []): JsonResponse
```

### HTTP status code standar

| Operasi | Status sukses |
|---|---|
| GET (index / show) | 200 |
| POST (store) | 201 |
| PUT/PATCH (update) | 200 |
| DELETE (destroy) | 200 |

### Try-catch pada operasi tulis

Setiap `store`, `update`, dan `destroy` **wajib** dibungkus try-catch. Tangkap `\Throwable` dan selalu kirim pesan error asli:

```php
try {
    $model = Model::create($validated);
    return $this->success('...', $model, 201);
} catch (\Throwable $e) {
    return $this->error($e->getMessage(), 500);
}
```

### Global exception handler (`bootstrap/app.php`)

Exception yang tidak tertangkap di controller ditangani secara global untuk semua route `api/*`:

| Exception | Status | Keterangan |
|---|---|---|
| `ValidationException` | 422 | Menyertakan `errors` object dari Laravel validator |
| `ModelNotFoundException` | 404 | Route model binding gagal |
| `NotFoundHttpException` | 404 | Endpoint tidak terdaftar |
| `AuthenticationException` | 401 | Belum login / token tidak valid |
| `Throwable` (catch-all) | 500 | Selalu tampilkan `message`, `code`, `file`, `line` — tidak disembunyikan di production |

Format error 500:
```json
{
  "status": false,
  "message": "SQLSTATE[23000]: Integrity constraint violation ...",
  "code": 23000,
  "file": "/path/to/file.php",
  "line": 42
}
```

---

## Aturan Endpoint Get Data (index / list)

Setiap function yang mengembalikan daftar data **wajib** menerapkan:

1. **Search** — filter berdasarkan query string `?search=` yang mencari minimal pada kolom `name` (atau kolom relevan lainnya).
2. **Pagination** — selalu gunakan `->paginate(20)`, bukan `->get()`.

Contoh implementasi standar:

```php
public function index(Request $request)
{
    $data = Model::when($request->search, fn($q, $s) => $q->where('name', 'like', "%{$s}%"))
        ->paginate(20);

    return response()->json($data);
}
```

Response `paginate()` Laravel sudah menyertakan `data`, `current_page`, `last_page`, `per_page`, `total` secara otomatis.

---

## API Documentation Rule

Setiap API yang dibuat **wajib didokumentasikan** dalam folder `dokumentasi/` di root project.

### Struktur folder

Struktur folder dokumentasi **mengikuti persis** struktur folder controller di `app/Http/Controllers/`. Setiap file dokumentasi dibuat **per-controller** (semua endpoint dalam 1 file `.md`), bukan per-function.

```
app/Http/Controllers/Auth/AuthController.php
  → dokumentasi/Auth/AuthController.md

app/Http/Controllers/Master/CssdController.php
  → dokumentasi/Master/CssdController.md

app/Http/Controllers/Transaction/LoanController.php
  → dokumentasi/Transaction/LoanController.md
```

### Format file dokumentasi

Setiap file `{ControllerName}.md` mendokumentasikan semua endpoint dalam satu controller, dengan format berikut:

```markdown
# {ControllerName}

**Controller:** App\Http\Controllers\{Subfolder}\{ControllerName}  
**Base URL:** /api/...

---

## 1. {FunctionName}

**Method:** GET / POST / PUT / DELETE  
**Endpoint:** /api/...  
**Auth:** Bearer Token (wajib) / Tidak diperlukan

### Headers
| Key | Value | Required |
|-----|-------|----------|
| Authorization | Bearer {token} | Ya |

### Body / Query Parameters
| Parameter | Type | Required | Keterangan |
|-----------|------|----------|------------|
| nama_field | string | Ya | ... |

### Response

#### Success (200)
\`\`\`json
{
  "status": true,
  "message": "...",
  "data": { ... }
}
\`\`\`

#### Error (422)
\`\`\`json
{
  "status": false,
  "message": "Data yang dikirim tidak valid.",
  "errors": { "field": ["pesan error"] }
}
\`\`\`

#### Error (404)
\`\`\`json
{
  "status": false,
  "message": "Data tidak ditemukan."
}
\`\`\`

#### Error (500)
\`\`\`json
{
  "status": false,
  "message": "pesan error asli dari exception",
  "code": 0,
  "file": "/path/to/file.php",
  "line": 42
}
\`\`\`

---

## 2. {FunctionName berikutnya}
...
```

### Aturan

- Buat atau perbarui file dokumentasi **bersamaan** saat membuat atau mengubah sebuah function di controller.
- Jika sebuah function dihapus, hapus juga file dokumentasinya.
- Jika controller dipindah ke subfolder lain, sesuaikan juga path dokumentasinya.
