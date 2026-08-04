<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\TitleMenus;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $dashboard = TitleMenus::where('title', 'Dashboard')->first();
        $masterData = TitleMenus::where('title', 'Master Data')->first();
        $cssd = TitleMenus::where('title', 'Cssd')->first();
        $clinicalPathway = TitleMenus::where('title', 'Clinical Pathway')->first();
        $pengaturan = TitleMenus::where('title', 'Pengaturan')->first();

        // Dashboard — langsung link, tidak ada sub_menu
        $this->menu([
            'title_menu_id' => $dashboard?->id,
            'name' => 'Dashboard',
            'url' => '/dashboard',
            'icon' => 'dashboard',
            'sort_order' => 1,
            'is_open' => false,
        ]);

        // Master Data — parent (tidak punya url), anak-anaknya yang punya url
        $masterParent = $this->menu([
            'title_menu_id' => $masterData?->id,
            'name' => 'Master Data',
            'url' => null,
            'icon' => 'database',
            'sort_order' => 1,
            'is_open' => false,
        ]);

        $children = [
            ['name' => 'Authority',  'url' => '/master/otoritas',   'icon' => 'shield', 'sort_order' => 1],
            ['name' => 'Title Menu', 'url' => '/master/title-menu', 'icon' => 'list',   'sort_order' => 2],
            ['name' => 'Menu',       'url' => '/master/menu',       'icon' => 'menu',   'sort_order' => 3],
            ['name' => 'User',       'url' => '/master/user',       'icon' => 'users',  'sort_order' => 4],
        ];

        foreach ($children as $child) {
            $this->menu([
                'title_menu_id' => $masterData?->id,
                'parent_id' => $masterParent->id,
                'name' => $child['name'],
                'url' => $child['url'],
                'icon' => $child['icon'],
                'sort_order' => $child['sort_order'],
                'is_open' => false,
            ]);
        }

        // Master CSSD — dipindah ke title "Master Data" sebagai grup tersendiri
        // (di bawah grup "Master Data"). Berisi data acuan operasional CSSD.
        // "Instrumen" tidak lagi jadi menu tersendiri — fiturnya menyatu sebagai
        // tab di dalam "Set Instrumen" (/master/katalog-instrumen) biar terpusat.
        $masterCssdParent = $this->menu([
            'title_menu_id' => $masterData?->id,
            'name' => 'Master CSSD',
            'url' => null,
            'icon' => 'box',
            'sort_order' => 2,
            'is_open' => false,
        ]);

        $masterCssdChildren = [
            ['name' => 'Ruangan',       'url' => '/master/ruangan',           'icon' => null,              'sort_order' => 1],
            ['name' => 'Set Instrumen', 'url' => '/master/katalog-instrumen', 'icon' => null,              'sort_order' => 2],
            ['name' => 'Kondisi',       'url' => '/master/kondisi',           'icon' => null,              'sort_order' => 3],
            ['name' => 'BMHP',          'url' => '/master/bmhp',              'icon' => null,              'sort_order' => 4],
            // Master mesin washer — acuan scan barcode mesin pada tahap Cleaning.
            ['name' => 'Mesin Washer',  'url' => '/master/mesin-washer',      'icon' => 'washing-machine', 'sort_order' => 5],
            // Master mesin sterilisator — pilihan mesin pada tahap Sterilization.
            ['name' => 'Mesin Sterilisator', 'url' => '/master/mesin-sterilisator', 'icon' => 'washing-machine', 'sort_order' => 6],
            // Master rak — pilihan lokasi rak saat menyimpan unit steril ke gudang.
            ['name' => 'Rak',           'url' => '/master/rak',               'icon' => 'archive',         'sort_order' => 7],
            // Master jenis kemasan — pilihan pada tahap Packaging; masa simpannya
            // menentukan tgl kedaluwarsa steril batch.
            ['name' => 'Packaging',     'url' => '/master/jenis-kemasan',     'icon' => 'package',         'sort_order' => 8],
        ];

        foreach ($masterCssdChildren as $child) {
            $this->menu([
                'title_menu_id' => $masterData?->id,
                'parent_id' => $masterCssdParent->id,
                'name' => $child['name'],
                'url' => $child['url'],
                'icon' => $child['icon'],
                'sort_order' => $child['sort_order'],
                'is_open' => false,
            ]);
        }

        // Medis — grup master data medis (di bawah title "Master Data").
        $medisParent = $this->menu([
            'title_menu_id' => $masterData?->id,
            'name' => 'Medis',
            'url' => null,
            'icon' => 'activity',
            'sort_order' => 3,
            'is_open' => false,
        ]);

        $medisChildren = [
            ['name' => 'ICD 10', 'url' => '/master/icd-10', 'icon' => 'clipboard-list', 'sort_order' => 1],
        ];

        foreach ($medisChildren as $child) {
            $this->menu([
                'title_menu_id' => $masterData?->id,
                'parent_id' => $medisParent->id,
                'name' => $child['name'],
                'url' => $child['url'],
                'icon' => $child['icon'],
                'sort_order' => $child['sort_order'],
                'is_open' => false,
            ]);
        }

        // Clinical Pathway — grup dipindah ke title "Master Data".
        // Parent group sekarang bernama "Clinical Pathway", berisi "Kategori"
        // dan "Formulir" (sebelumnya "Template Clinical Pathway").
        $clinicalPathwayParent = $this->menu([
            'title_menu_id' => $masterData?->id,
            'name' => 'Clinical Pathway',
            'url' => null,
            'icon' => 'list',
            'sort_order' => 4,
            'is_open' => false,
        ]);

        $clinicalPathwayChildren = [
            ['name' => 'Kategori', 'url' => '/clinical-pathway/kategori', 'sort_order' => 1],
            ['name' => 'Formulir', 'url' => '/clinical-pathway/formulir', 'sort_order' => 2],
        ];

        foreach ($clinicalPathwayChildren as $child) {
            $this->menu([
                'title_menu_id' => $masterData?->id,
                'parent_id' => $clinicalPathwayParent->id,
                'name' => $child['name'],
                'url' => $child['url'],
                'sort_order' => $child['sort_order'],
                'is_open' => false,
            ]);
        }

        // Title "Clinical Pathway" — menu transaksi pengisian: Asesmen pasien.
        $this->menu([
            'title_menu_id' => $clinicalPathway?->id,
            'name' => 'Asesmen',
            'url' => '/clinical-pathway/asesmen',
            'icon' => 'clipboard-list',
            'sort_order' => 1,
            'is_open' => false,
        ]);

        // CSSD — sisa 2 sub-grup di bawah title "Cssd": Transaksi & Monitoring.
        // Masing-masing parent tertutup default (is_open=false) supaya sidebar
        // ringkas; operator klik grup yang perlu.
        $cssdGroups = [
            [
                'name' => 'Transaksi',
                'icon' => 'list',
                'sort_order' => 1,
                'children' => [
                    // Aktivitas input yang menghasilkan data
                    ['name' => 'Produksi CSSD',   'url' => '/cssd/produksi',        'icon' => 'factory',   'sort_order' => 1],
                    ['name' => 'Storage Steril',  'url' => '/cssd/storage-steril',  'icon' => 'warehouse', 'sort_order' => 2],
                    ['name' => 'Order Instrumen', 'url' => '/cssd/order/instrumen', 'sort_order' => 3],
                    ['name' => 'Tracking Order',  'url' => '/cssd/tracking-order',  'sort_order' => 4],
                    ['name' => 'Distribusi BMHP', 'url' => '/cssd/distribusi',      'sort_order' => 5],
                ],
            ],
            [
                'name' => 'Monitoring',
                'icon' => 'monitor',
                'sort_order' => 2,
                'children' => [
                    // Pantau & lacak data (read-only)
                    ['name' => 'Alat Kedaluwarsa Steril', 'url' => '/cssd/kedaluwarsa', 'sort_order' => 1],
                    ['name' => 'Laporan Alat CSSD',  'url' => '/cssd/laporan',     'sort_order' => 2],
                    // Posisi 3 diisi "Laporan Transaksi Instrumen" lewat
                    // LaporanTransaksiInstrumenMenuSeeder (menu pasca-rilis), yang
                    // menggeser "Papan Monitor (TV)" ke posisi 4.
                    ['name' => 'Papan Monitor (TV)', 'url' => '/monitor',          'sort_order' => 3],
                ],
            ],
        ];

        // (Catatan) "Scan & Tracking" sudah dihapus; menu lama "Monitoring"
        // (/cssd/monitoring) kini menjadi "Tracking Order" (/cssd/tracking-order)
        // di dalam grup Transaksi.

        foreach ($cssdGroups as $group) {
            $parent = $this->menu([
                'title_menu_id' => $cssd?->id,
                'name' => $group['name'],
                'url' => null,
                'icon' => $group['icon'],
                'sort_order' => $group['sort_order'],
                'is_open' => false,
            ]);

            foreach ($group['children'] as $child) {
                $this->menu([
                    'title_menu_id' => $cssd?->id,
                    'parent_id' => $parent->id,
                    'name' => $child['name'],
                    'url' => $child['url'],
                    'icon' => $child['icon'] ?? null,
                    'sort_order' => $child['sort_order'],
                    'is_open' => false,
                ]);
            }
        }

        // Pengaturan — menu ber-url (/pengaturan). Di sidebar utama tampil sebagai
        // satu link (anak disembunyikan); sub-nav-nya (Master Printer, dst.) tampil
        // di sidebar kedua yang dibangun dari anak-anak menu ini.
        // open_sidebar=false → sidebar utama otomatis menutup saat halaman dibuka.
        $pengaturanParent = $this->menu([
            'title_menu_id' => $pengaturan?->id,
            'name' => 'Pengaturan',
            'url' => '/pengaturan',
            'icon' => 'settings',
            'sort_order' => 1,
            'is_open' => false,
            'open_sidebar' => false,
        ]);

        $this->menu([
            'title_menu_id' => $pengaturan?->id,
            'parent_id' => $pengaturanParent->id,
            'name' => 'Master Printer',
            'url' => '/pengaturan/master-printer',
            'icon' => 'printer',
            'sort_order' => 1,
            'is_open' => false,
            'open_sidebar' => false,
        ]);
    }

    /**
     * Buat menu bila belum ada; kalau sudah ada kembalikan yang lama APA ADANYA.
     *
     * Wajib idempotent: tabel `menus` tidak punya index unik, jadi create() polos
     * membuat `db:seed` ulang MENGGANDAKAN seluruh sidebar tanpa error sama sekali.
     *
     * Identitas menu = (title_menu_id, parent_id, name) — bukan `url`, karena menu
     * parent ber-url null dan beberapa nama anak bisa sama di grup berbeda.
     *
     * Baris yang sudah ada sengaja TIDAK di-update: url/icon/sort_order boleh
     * diubah admin lewat master menu, dan seeder tidak berhak menimpanya.
     * withTrashed() dipakai supaya menu yang sudah dihapus admin tidak
     * dibangkitkan ulang (sekaligus tidak digandakan).
     */
    private function menu(array $attributes): Menu
    {
        $identity = [
            'title_menu_id' => $attributes['title_menu_id'] ?? null,
            'parent_id' => $attributes['parent_id'] ?? null,
            'name' => $attributes['name'],
        ];

        // where() dengan nilai null otomatis jadi `is null` di Laravel, jadi menu
        // tingkat atas (parent_id null) tetap tercocokkan dengan benar.
        return Menu::withTrashed()->where($identity)->first()
            ?? Menu::create($attributes);
    }
}
