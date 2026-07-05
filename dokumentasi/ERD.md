# ERD — Care Pulse Backend (be-care-pulse)

**Sumber:** disusun dari seluruh migrasi aktif di `database/migrations/` (kolom hasil `create` + `alter` sudah digabung).
**Database:** MySQL (Laravel 12). **Tanggal:** 2026-07-05.

## Catatan baca diagram

- **Kolom audit** dimiliki hampir semua tabel domain dan **tidak ditulis ulang** di tiap entitas agar ringkas:
  `created_at`, `created_by`, `updated_at`, `updated_by`, `deleted_at`, `deleted_by` (pola `HasAuditColumns` + soft delete via `deleted_by`).
  Pengecualian append-only (tanpa soft delete): `instrument_stock_logs`, `order_events`, `pipeline_events` (hanya `created_by`/`created_at`).
- Tabel infrastruktur Laravel (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`,
  `password_reset_tokens`, `personal_access_tokens`) dikecualikan dari ERD domain.
- Nama tabel `order` adalah reserved keyword SQL (di-quote Laravel; model `Order` pakai `protected $table = 'order'`).
- Notasi kardinalitas Mermaid: `||--o{` = satu-ke-banyak (opsional), `||--||` = satu-ke-satu.
- **Pipeline CSSD dirangkai lewat KODE (soft link, bukan FK):** `washing.production_code → production.code`,
  `packaging.washing_code → washing.code`, `sterilizations.packaging_code → packaging.code`,
  `production.reference_code → order.code`. Digambar sebagai relasi putus-putus (`..o{`).

---

## Diagram (Mermaid)

```mermaid
erDiagram
    %% ========== AUTH & RBAC ==========
    authorities ||--o{ users : "punya"
    authorities ||--o{ authority_menu : ""
    menus ||--o{ authority_menu : ""
    title_menuses ||--o{ menus : "berisi"
    menus ||--o{ menus : "parent-child"

    %% ========== MASTER DATA ==========
    instruments ||--o{ instrument_stocks : "unit fisik"
    conditions ||--o{ instrument_stocks : "kondisi"
    instrument_stocks ||--o{ instrument_stock_logs : "riwayat"
    instrument_catalogs ||--o{ instrument_catalog_items : "komponen"
    instruments ||--o{ instrument_catalog_items : ""
    conditions ||--o{ instrument_catalog_items : "std kondisi"

    %% ========== ORDER / PEMINJAMAN ==========
    rooms ||--o{ order : "peminjam"
    users ||--o{ order : "pembuat"
    order ||--o{ order_item : "unit dipinjam"
    instrument_stocks ||--o{ order_item : ""
    conditions ||--o{ order_item : "kondisi in/out"
    order ||--o{ order_request_item : "baris permintaan"
    instruments ||--o{ order_request_item : ""
    instrument_catalogs ||--o{ order_request_item : ""
    order ||--o{ order_events : "timeline"
    rooms ||--o{ order_events : ""

    %% ========== HANDOVER (PINJAM-ALIH) ==========
    order ||--o{ order_transfers : "asal (from)"
    order ||--o{ order_transfers : "hasil (new)"
    users ||--o{ order_transfers : "holder/requester"
    rooms ||--o{ order_transfers : "tujuan"
    order_transfers ||--o{ order_transfer_items : "unit"
    instrument_stocks ||--o{ order_transfer_items : ""

    %% ========== PIPELINE CSSD (produksi -> cleaning -> packaging -> steril -> storage) ==========
    production ||--o{ production_item : "unit batch"
    instrument_stocks ||--o{ production_item : ""
    conditions ||--o{ production_item : "kondisi out"
    washer_machines ||--o{ washing : "mesin"
    sterilizations ||--o{ packaging : "batch steril"
    order ||--o{ sterilizations : "batch steril (nullable)"
    sterilizations ||--o{ sterilization_items : "unit"
    instrument_stocks ||--o{ sterilization_items : ""
    order ||--o{ instrument_storages : "penyimpanan"
    sterilizations ||--o{ instrument_storages : ""
    instrument_stocks ||--o{ instrument_storages : ""
    %% Rantai antar-tahap via kode (soft link, bukan FK):
    production ..o{ washing : "production_code"
    washing ..o{ packaging : "washing_code"
    packaging ..o{ sterilizations : "packaging_code"
    order ..o{ production : "reference_code (reprocessing)"

    %% ========== DISTRIBUSI BMHP ==========
    rooms ||--o{ distributions : "tujuan"
    users ||--o{ distributions : "sender/receiver"
    distributions ||--o{ distribution_items : "isi"
    bmhps ||--o{ distribution_items : ""

    %% ========== CLINICAL PATHWAY ==========
    icd10 ||--o{ clinical_pathway_templates : "diagnosa"
    clinical_pathway_templates ||--o{ clinical_pathway_points : "poin"
    clinical_pathway_categories ||--o{ clinical_pathway_points : "kategori"
    clinical_pathway_points ||--o{ clinical_pathway_points : "sub-poin"
    clinical_pathway_templates ||--o{ clinical_pathway_assessments : "formulir"
    rooms ||--o{ clinical_pathway_assessments : "ruang rawat"
    clinical_pathway_assessments ||--o{ clinical_pathway_assessment_points : "ceklis"
    clinical_pathway_points ||--o{ clinical_pathway_assessment_points : ""
    clinical_pathway_assessments ||--o{ clinical_pathway_variances : "varian"

    %% ================= ENTITAS =================
    users {
        bigint id PK
        string name
        string username UK
        string no_telephone
        bigint authority_id FK
        string email UK
        string password
    }
    authorities {
        bigint id PK
        string name UK
        string description
    }
    title_menuses {
        bigint id PK
        string title
        int sort_order
    }
    menus {
        bigint id PK
        bigint title_menu_id FK
        bigint parent_id FK
        string name
        string url
        string icon
        int sort_order
        bool is_open
    }
    authority_menu {
        bigint id PK
        bigint authority_id FK
        bigint menu_id FK
    }

    conditions {
        bigint id PK
        string name UK
    }
    instruments {
        bigint id PK
        string code UK
        string name
        string image
    }
    instrument_stocks {
        bigint id PK
        bigint instrument_id FK
        string code UK
        bigint condition_id FK
        string status "tersedia|dipinjam|sterilisasi|dikembalikan"
    }
    instrument_stock_logs {
        bigint id PK
        bigint instrument_stock_id FK
        string from_status
        string to_status
        string context "create|manual|order|sterilization|set"
        string reference_code
        text note
        string created_by
    }
    rooms {
        bigint id PK
        string code UK "4 huruf"
        string name UK
    }
    instrument_catalogs {
        bigint id PK
        string code UK
        string name
        string image
        enum type "single|paket"
        text description
    }
    instrument_catalog_items {
        bigint id PK
        bigint instrument_catalog_id FK
        bigint instrument_id FK
        int quantity
        bigint standard_condition_id FK
        string note
    }
    bmhps {
        bigint id PK
        string code UK "BMHP-NNN"
        string name
        string unit
        int stock_qty
        text description
    }
    icd10 {
        bigint id PK
        string code
        string display
        string version
    }
    washer_machines {
        bigint id PK
        string code UK "WSH-NNN"
        string name
        string location
        decimal min_temperature
        decimal max_temperature
        int min_duration_minutes
        int max_duration_minutes
        string status "aktif|nonaktif"
        text note
    }

    order {
        bigint id PK
        string code UK "ORD-NNN"
        string code_transaction UK "INV..."
        bigint room_id FK "nullable (produksi CSSD)"
        bigint user_id FK
        string borrowed_by
        date order_date
        time order_time
        date return_plan_date
        date return_actual_date
        string returned_by
        string medical_record_no
        string patient_name
        string distributed_to
        timestamp distributed_at
        string status "diajukan|dipinjam|dikembalikan|dibatalkan|..."
        timestamp canceled_at
        string canceled_by
        timestamp processed_at
        string processed_by
        text note
    }
    order_item {
        bigint id PK
        bigint order_id FK
        bigint instrument_stock_id FK
        string source "satuan|paket"
        string package_name
        bigint condition_out_id FK
        bigint condition_in_id FK
        bool is_returned
    }
    order_request_item {
        bigint id PK
        bigint order_id FK
        string type "satuan|paket"
        bigint instrument_id FK
        bigint instrument_catalog_id FK
        string package_name
        int quantity
    }
    order_transfers {
        bigint id PK
        bigint from_order_id FK
        bigint holder_user_id FK
        bigint requested_by_user_id FK
        bigint to_room_id FK
        string borrowed_by
        text note
        string status "pending|accepted|rejected|canceled"
        timestamp responded_at
        bigint new_order_id FK
    }
    order_transfer_items {
        bigint id PK
        bigint order_transfer_id FK
        bigint instrument_stock_id FK
        string source
        string package_name
    }
    order_events {
        bigint id PK
        bigint order_id FK
        string code_transaction "index"
        string type "dibuat|diterima|dipinjam|dikembalikan|dipindah"
        bigint room_id FK
        string actor
        string borrowed_by
        string note
        timestamp created_at
    }

    production {
        bigint id PK
        string code UK "PRD-NNN"
        string source "internal|reprocessing"
        string reference_code "-> order.code (soft)"
        string status "diproses|selesai"
        string started_by
        string completed_by
        text note
    }
    production_item {
        bigint id PK
        bigint production_id FK
        bigint instrument_stock_id FK
        string source "satuan|paket"
        string package_name
        bigint condition_out_id FK
    }
    washing {
        bigint id PK
        string code UK "WSH-NNN"
        string production_code "-> production.code (soft)"
        bigint washer_machine_id FK
        string machine_no
        string operator
        string temperature
        timestamp washed_at
        int duration_minutes
        string detergent_type
        bool alert
        string alert_message
        string failure_reason
        string status "dalam_proses|selesai|gagal|batal"
        string started_by
        string completed_by
        string canceled_by
        timestamp canceled_at
    }
    packaging {
        bigint id PK
        string code UK "PKG-NNN"
        string washing_code "-> washing.code (soft)"
        bigint sterilization_id FK "nullable"
        string operator
        timestamp packaged_at
        string chemical_indicator
        string status "diproses|selesai"
        string started_by
        string completed_by
    }
    sterilizations {
        bigint id PK
        bigint order_id FK "nullable"
        string code UK "STR-NNN"
        string packaging_code "-> packaging.code (soft)"
        string machine
        string method "uap|eo|plasma|panas_kering"
        string cycle_number
        decimal temperature
        int duration_minutes
        string operator
        datetime sterilized_at
        date expiry_date
        string chemical_indicator
        string biological_indicator
        string bio_indicator_control "Negatif|Positif"
        string bio_indicator_test "Negatif|Positif"
        string status "diproses|selesai|gagal"
        text note
    }
    sterilization_items {
        bigint id PK
        bigint sterilization_id FK
        bigint instrument_stock_id FK
        string result
    }
    instrument_storages {
        bigint id PK
        bigint order_id FK
        bigint sterilization_id FK
        bigint instrument_stock_id FK
        string rack_code
        date expiry_date
        string status "tersimpan|keluar"
        timestamp stored_at
        timestamp removed_at
    }
    pipeline_events {
        bigint id PK
        string stage "production|washing|packaging|sterilization"
        string code "PRD/WSH/PKG/STR-NNN"
        string action
        string actor
        text note
        timestamp created_at
    }

    distributions {
        bigint id PK
        string code UK "DST-NNN"
        bigint room_id FK
        bigint sender_id FK
        bigint receiver_id FK
        datetime distributed_at
        string status "terdistribusi|dibatalkan"
        text note
    }
    distribution_items {
        bigint id PK
        bigint distribution_id FK
        bigint bmhp_id FK
        int quantity
        string note
    }

    clinical_pathway_categories {
        bigint id PK
        int sort_order UK
        string label
    }
    clinical_pathway_templates {
        bigint id PK
        bigint icd10_id FK
        int max_days
        text description
        bool is_active
    }
    clinical_pathway_points {
        bigint id PK
        bigint template_id FK
        bigint category_id FK
        bigint parent_id FK
        string label
        string filled_by "dokter|perawat|farmasi|penunjang"
        json required_days
        int sort_order
    }
    clinical_pathway_assessments {
        bigint id PK
        bigint template_id FK
        string medical_record_no
        string patient_name
        string gender "L|P"
        date birth_date
        string admission_diagnosis
        string primary_disease
        string comorbidity
        string complication
        string procedure
        decimal weight
        decimal height
        datetime admitted_at
        datetime discharged_at
        int length_of_stay
        string care_plan
        bigint room_id FK
        string ward_class
        bool is_referral
        string doctor_verified_by
        timestamp doctor_verified_at
        string nurse_verified_by
        timestamp nurse_verified_at
        string executor_verified_by
        timestamp executor_verified_at
    }
    clinical_pathway_assessment_points {
        bigint id PK
        bigint assessment_id FK
        bigint point_id FK
        json checked_days
        text note
    }
    clinical_pathway_variances {
        bigint id PK
        bigint assessment_id FK
        datetime occurred_at
        text variance
        text reason
        string initials
    }
```

---

## Ringkasan relasi & aturan hapus (onDelete)

### Auth & RBAC
| Parent | Child | FK | onDelete |
|---|---|---|---|
| authorities | users | authority_id | null |
| authorities | authority_menu | authority_id | cascade |
| menus | authority_menu | menu_id | cascade |
| title_menuses | menus | title_menu_id | null |
| menus | menus (self) | parent_id | null |

### Master
| Parent | Child | FK | onDelete |
|---|---|---|---|
| instruments | instrument_stocks | instrument_id | cascade |
| conditions | instrument_stocks | condition_id | set null |
| instrument_stocks | instrument_stock_logs | instrument_stock_id | cascade |
| instrument_catalogs | instrument_catalog_items | instrument_catalog_id | cascade |
| instruments | instrument_catalog_items | instrument_id | cascade |
| conditions | instrument_catalog_items | standard_condition_id | set null |

### Order & Handover
| Parent | Child | FK | onDelete |
|---|---|---|---|
| rooms | order | room_id | restrict (kolom nullable) |
| users | order | user_id | null |
| order | order_item | order_id | cascade |
| instrument_stocks | order_item | instrument_stock_id | restrict |
| conditions | order_item | condition_out_id / condition_in_id | null |
| order | order_request_item | order_id | cascade |
| instruments | order_request_item | instrument_id | null |
| instrument_catalogs | order_request_item | instrument_catalog_id | null |
| order | order_events | order_id | cascade (append-only) |
| rooms | order_events | room_id | null |
| order | order_transfers | from_order_id | cascade |
| order | order_transfers | new_order_id | null |
| users | order_transfers | holder_user_id / requested_by_user_id | cascade |
| rooms | order_transfers | to_room_id | cascade |
| order_transfers | order_transfer_items | order_transfer_id | cascade |
| instrument_stocks | order_transfer_items | instrument_stock_id | cascade |

### Pipeline CSSD
| Parent | Child | FK / Link | onDelete |
|---|---|---|---|
| production | production_item | production_id | cascade |
| instrument_stocks | production_item | instrument_stock_id | restrict |
| conditions | production_item | condition_out_id | null |
| washer_machines | washing | washer_machine_id | null |
| sterilizations | packaging | sterilization_id (nullable) | null |
| order | sterilizations | order_id (nullable) | null |
| sterilizations | sterilization_items | sterilization_id | cascade |
| instrument_stocks | sterilization_items | instrument_stock_id | restrict, **unique(sterilization_id, instrument_stock_id)** |
| order / sterilizations | instrument_storages | order_id / sterilization_id | null |
| instrument_stocks | instrument_storages | instrument_stock_id | restrict |
| production → washing → packaging → sterilizations | (rantai) | *_code | **soft link (bukan FK)** |
| pipeline_events | — | standalone (append-only, index `stage`+`code`) | — |

### Distribusi BMHP
| Parent | Child | FK | onDelete |
|---|---|---|---|
| rooms | distributions | room_id | restrict |
| users | distributions | sender_id / receiver_id | null / restrict |
| distributions | distribution_items | distribution_id | cascade |
| bmhps | distribution_items | bmhp_id | null |

### Clinical Pathway
| Parent | Child | FK | onDelete |
|---|---|---|---|
| icd10 | clinical_pathway_templates | icd10_id | cascade |
| clinical_pathway_templates | clinical_pathway_points | template_id | cascade |
| clinical_pathway_categories | clinical_pathway_points | category_id | cascade |
| clinical_pathway_points | clinical_pathway_points (self) | parent_id | (di-handle aplikasi) |
| clinical_pathway_templates | clinical_pathway_assessments | template_id | cascade |
| rooms | clinical_pathway_assessments | room_id | null |
| clinical_pathway_assessments | clinical_pathway_assessment_points | assessment_id | cascade, **unique(assessment_id, point_id)** |
| clinical_pathway_points | clinical_pathway_assessment_points | point_id | cascade |
| clinical_pathway_assessments | clinical_pathway_variances | assessment_id | cascade |

---

## Cara melihat diagram
- **VS Code:** pasang ekstensi *Markdown Preview Mermaid Support*, lalu buka preview file ini.
- **GitHub:** blok ```mermaid dirender otomatis.
- **Online:** salin blok mermaid ke <https://mermaid.live>.
