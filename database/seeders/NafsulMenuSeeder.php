<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\TitleMenus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Menu untuk modul Nafsul Mutmainah (keanggotaan & pelayanan jenazah).
 *
 * URL di-prefix `/nafsul/...` agar tidak bentrok dengan route CSSD; menu
 * master-nya berada satu tingkat lebih dalam: `/nafsul/master/...`.
 *
 * Yang di-seed baru sebatas grup "Master Nafsul" — halaman transaksi, layanan
 * jenazah, dan laporan belum ada di frontend repo ini, jadi menunya sengaja
 * tidak didaftarkan agar tidak jadi link mati. Backend-nya sudah tersedia
 * (Route::prefix('nafsul') di routes/api.php), tinggal tambahkan grupnya di
 * sini saat halaman FE-nya menyusul.
 *
 * Idempotent — aman dijalankan berulang, baik pada DB baru maupun DB lama.
 * Menu dicari berdasarkan `url` (untuk link) atau `name` + `title_menu_id`
 * (untuk grup, yang url-nya null).
 *
 * Urutan di DatabaseSeeder: SEBELUM AuthoritySeeder. Pada DB baru belum ada
 * authority, sehingga attach dilewati dan AuthoritySeeder-lah yang memberi
 * seluruh menu ke "Administrator". Pada DB lama yang authority-nya sudah ada,
 * menu baru di-attach ke "Administrator" SAJA — authority lain (mis. Operator)
 * sengaja tidak diberi akses otomatis agar hak aksesnya tidak melebar diam-diam.
 * Pemberian akses ke authority lain dilakukan manual lewat menu Otoritas.
 *
 * Jalankan manual: php artisan db:seed --class=NafsulMenuSeeder
 */
class NafsulMenuSeeder extends Seeder
{
    /** Id menu yang baru dibuat pada eksekusi ini (untuk di-attach ke authority). */
    private array $createdMenuIds = [];

    /**
     * Peta url lama → url baru, dijalankan sebelum menu disinkronkan.
     *
     * Menu dicari berdasarkan `url`, jadi tanpa langkah ini penggantian prefix
     * `/nafsul` → `/nafsul` (dan pemindahan menu master ke `/nafsul/master`)
     * akan membuat menu ganda di DB lama, bukan memindahkan yang sudah ada.
     */
    private const URL_RENAMES = [
        '/nafsul/anggota' => '/nafsul/master/anggota',
        '/nafsul/kota' => '/nafsul/master/kota',
        '/nafsul/wilayah' => '/nafsul/master/wilayah',
        '/nafsul/status-anggota' => '/nafsul/master/status-anggota',
        '/nafsul/ketua-kelompok' => '/nafsul/master/ketua-kelompok',
        '/nafsul/tarif/iuran' => '/nafsul/master/tarif/iuran',
    ];

    public function run(): void
    {
        $this->renameUrls();

        // Grup "Master Nafsul" ditempatkan di dalam title "Master Data" (bergabung
        // dengan Master CSSD, Medis, dll) — bukan di title "Nafsul". Urutannya
        // tepat setelah "Master CSSD" (sort_order 3); Medis & Clinical Pathway
        // sudah digeser ke 4 & 5 di MenuSeeder.
        $masterData = TitleMenus::where('title', 'Master Data')->first();

        if (! $masterData) {
            $this->command?->warn('Title "Master Data" belum ada. Jalankan TitleMenuSeeder & MenuSeeder dulu.');
        }

        // Setiap grup: title tujuan + daftar anak.
        $groups = [
            [
                'title_id' => $masterData?->id,
                'name' => 'Master Nafsul',
                'icon' => 'database',
                'sort_order' => 3,
                'children' => [
                    ['name' => 'Data Anggota',          'url' => '/nafsul/master/anggota',        'icon' => 'users', 'sort_order' => 1],
                    ['name' => 'Master Kota',           'url' => '/nafsul/master/kota',           'sort_order' => 2],
                    ['name' => 'Master Wilayah',        'url' => '/nafsul/master/wilayah',        'sort_order' => 3],
                    ['name' => 'Status Anggota',        'url' => '/nafsul/master/status-anggota', 'sort_order' => 4],
                    ['name' => 'Ketua Kelompok',        'url' => '/nafsul/master/ketua-kelompok', 'sort_order' => 5],
                    ['name' => 'Master Pendidikan',     'url' => '/nafsul/master/pendidikan',     'sort_order' => 6],
                    ['name' => 'Master Pekerjaan',      'url' => '/nafsul/master/pekerjaan',      'sort_order' => 7],
                    ['name' => 'Tarif Iuran Anggota',   'url' => '/nafsul/master/tarif/iuran',    'sort_order' => 8],
                ],
            ],
        ];

        foreach ($groups as $group) {
            $parent = $this->ensureGroup($group);

            foreach ($group['children'] as $child) {
                $this->ensureLink($group['title_id'], $parent->id, $child);
            }
        }

        $this->grantToExistingAuthorities();
    }

    /**
     * Terapkan URL_RENAMES pada menu yang sudah ada di DB.
     *
     * Menu yang sudah di-soft-delete ikut di-rename (withTrashed) supaya tidak
     * tertinggal memakai url lama.
     */
    private function renameUrls(): void
    {
        foreach (self::URL_RENAMES as $lama => $baru) {
            $menus = Menu::withTrashed()->where('url', $lama)->get();

            foreach ($menus as $menu) {
                $menu->url = $baru;
                $menu->save();
                $this->command?->info("URL menu \"{$menu->name}\": {$lama} → {$baru}");
            }
        }
    }

    /** Grup menu (url null) — dicari berdasarkan nama + title, karena url tidak bisa jadi kunci. */
    private function ensureGroup(array $group): Menu
    {
        $menu = Menu::where('name', $group['name'])
            ->where('title_menu_id', $group['title_id'])
            ->whereNull('parent_id')
            ->first();

        if ($menu) {
            return $menu;
        }

        $menu = Menu::create([
            'title_menu_id' => $group['title_id'],
            'name' => $group['name'],
            'url' => null,
            'icon' => $group['icon'],
            'sort_order' => $group['sort_order'],
            'is_open' => false,
        ]);

        $this->createdMenuIds[] = $menu->id;

        return $menu;
    }

    /**
     * Menu berupa link — url unik, jadi cukup dipakai sebagai kunci.
     *
     * Bila menu sudah ada, penempatannya (title, induk, urutan) diselaraskan
     * ulang dengan definisi di run() supaya pemindahan menu antar grup cukup
     * diubah di sini lalu seeder dijalankan lagi. Konsekuensinya: pengurutan
     * manual menu Nafsul lewat menu master akan direset saat seeder diulang.
     */
    private function ensureLink(?int $titleId, ?int $parentId, array $attrs): Menu
    {
        $menu = Menu::where('url', $attrs['url'])->first();

        if ($menu) {
            $menu->fill([
                'title_menu_id' => $titleId,
                'parent_id' => $parentId,
                'sort_order' => $attrs['sort_order'],
            ]);

            if ($menu->isDirty()) {
                $pindah = $menu->isDirty('parent_id') || $menu->isDirty('title_menu_id');
                $menu->save();

                if ($pindah) {
                    $this->command?->info("Menu \"{$menu->name}\" dipindahkan.");
                }
            }

            return $menu;
        }

        $menu = Menu::create([
            'title_menu_id' => $titleId,
            'parent_id' => $parentId,
            'name' => $attrs['name'],
            'url' => $attrs['url'],
            'icon' => $attrs['icon'] ?? null,
            'sort_order' => $attrs['sort_order'],
            'is_open' => false,
        ]);

        $this->createdMenuIds[] = $menu->id;

        return $menu;
    }

    /**
     * Beri akses menu baru ke authority "Administrator" saja.
     *
     * Pada DB baru tabel authorities masih kosong, jadi ini tidak melakukan
     * apa-apa dan pembagian akses diserahkan ke AuthoritySeeder. Authority lain
     * sengaja dilewati: menambah menu tidak boleh diam-diam memperluas hak akses
     * peran yang dibatasi.
     */
    private function grantToExistingAuthorities(): void
    {
        if (empty($this->createdMenuIds)) {
            $this->command?->info('Menu Nafsul sudah lengkap — tidak ada yang ditambahkan.');

            return;
        }

        $administratorId = DB::table('authorities')->where('name', 'Administrator')->value('id');

        if ($administratorId === null) {
            $this->command?->info(count($this->createdMenuIds).' menu Nafsul dibuat (akses diatur AuthoritySeeder).');

            return;
        }

        $sudahPunya = DB::table('authority_menu')
            ->where('authority_id', $administratorId)
            ->whereIn('menu_id', $this->createdMenuIds)
            ->pluck('menu_id')
            ->all();

        $rows = [];

        foreach (array_diff($this->createdMenuIds, $sudahPunya) as $menuId) {
            $rows[] = [
                'authority_id' => $administratorId,
                'menu_id' => $menuId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($rows)) {
            DB::table('authority_menu')->insert($rows);
        }

        $this->command?->info(count($this->createdMenuIds).' menu Nafsul ditambahkan & diberikan ke authority "Administrator".');
        $this->command?->line('Authority lain belum diberi akses — atur manual lewat menu Otoritas bila perlu.');
    }
}
