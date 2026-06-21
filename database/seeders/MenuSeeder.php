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

        // Dashboard — langsung link, tidak ada sub_menu
        Menu::create([
            'title_menu_id' => $dashboard?->id,
            'name' => 'Dashboard',
            'url' => '/dashboard',
            'icon' => 'dashboard',
            'sort_order' => 1,
            'is_open' => false,
        ]);

        // Master Data — parent (tidak punya url), anak-anaknya yang punya url
        $masterParent = Menu::create([
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
            Menu::create([
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
        $masterCssdParent = Menu::create([
            'title_menu_id' => $masterData?->id,
            'name' => 'Master CSSD',
            'url' => null,
            'icon' => 'box',
            'sort_order' => 2,
            'is_open' => false,
        ]);

        $masterCssdChildren = [
            ['name' => 'Ruangan',       'url' => '/master/ruangan',           'sort_order' => 1],
            ['name' => 'Set Instrumen', 'url' => '/master/katalog-instrumen', 'sort_order' => 2],
            ['name' => 'Kondisi',       'url' => '/master/kondisi',           'sort_order' => 3],
            ['name' => 'BMHP',          'url' => '/master/bmhp',              'sort_order' => 4],
        ];

        foreach ($masterCssdChildren as $child) {
            Menu::create([
                'title_menu_id' => $masterData?->id,
                'parent_id' => $masterCssdParent->id,
                'name' => $child['name'],
                'url' => $child['url'],
                'sort_order' => $child['sort_order'],
                'is_open' => false,
            ]);
        }

        // Medis — grup master data medis (di bawah title "Master Data").
        $medisParent = Menu::create([
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
            Menu::create([
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
        $clinicalPathwayParent = Menu::create([
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
            Menu::create([
                'title_menu_id' => $masterData?->id,
                'parent_id' => $clinicalPathwayParent->id,
                'name' => $child['name'],
                'url' => $child['url'],
                'sort_order' => $child['sort_order'],
                'is_open' => false,
            ]);
        }

        // Title "Clinical Pathway" — menu transaksi pengisian: Asesmen pasien.
        Menu::create([
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
                    ['name' => 'Sterilisasi',     'url' => '/cssd/sterilisasi',     'sort_order' => 1],
                    ['name' => 'Order Instrumen', 'url' => '/cssd/order/instrumen', 'sort_order' => 2],
                    ['name' => 'Distribusi BMHP', 'url' => '/cssd/distribusi',      'sort_order' => 3],
                    ['name' => 'Tracking Order',  'url' => '/cssd/monitoring',      'sort_order' => 4],
                ],
            ],
            [
                'name' => 'Monitoring',
                'icon' => 'monitor',
                'sort_order' => 2,
                'children' => [
                    // Pantau & lacak data (read-only)
                    ['name' => 'Alat Kedaluwarsa',   'url' => '/cssd/kedaluwarsa', 'sort_order' => 1],
                    ['name' => 'Laporan Per Alat',   'url' => '/cssd/laporan',     'sort_order' => 2],
                    ['name' => 'Papan Monitor (TV)', 'url' => '/monitor',          'sort_order' => 3],
                ],
            ],
        ];

        // (Catatan) "Scan & Tracking" sudah dihapus; "Monitoring" /cssd/monitoring
        // kini menjadi "Tracking Order" di dalam grup Transaksi.

        foreach ($cssdGroups as $group) {
            $parent = Menu::create([
                'title_menu_id' => $cssd?->id,
                'name' => $group['name'],
                'url' => null,
                'icon' => $group['icon'],
                'sort_order' => $group['sort_order'],
                'is_open' => false,
            ]);

            foreach ($group['children'] as $child) {
                Menu::create([
                    'title_menu_id' => $cssd?->id,
                    'parent_id' => $parent->id,
                    'name' => $child['name'],
                    'url' => $child['url'],
                    'sort_order' => $child['sort_order'],
                    'is_open' => false,
                ]);
            }
        }
    }
}
