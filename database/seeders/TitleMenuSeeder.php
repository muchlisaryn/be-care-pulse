<?php

namespace Database\Seeders;

use App\Models\TitleMenus;
use Illuminate\Database\Seeder;

class TitleMenuSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['title' => 'Dashboard', 'sort_order' => 1],
            ['title' => 'Master Data', 'sort_order' => 2],
            ['title' => 'Cssd', 'sort_order' => 3],
            ['title' => 'Clinical Pathway', 'sort_order' => 4],
            ['title' => 'Pengaturan', 'sort_order' => 5],
        ];

        foreach ($items as $item) {
            // Idempotent: `title_menuses` tidak punya index unik pada `title`,
            // jadi create() polos akan MENGGANDAKAN seluruh title tiap kali
            // `db:seed` dijalankan ulang di DB yang sudah terisi.
            //
            // withTrashed(): title yang sudah dihapus admin tidak dibangkitkan
            // ulang — cukup dibiarkan terhapus, dan tidak digandakan.
            $exists = TitleMenus::withTrashed()->where('title', $item['title'])->exists();

            if (! $exists) {
                TitleMenus::create($item);
            }
        }
    }
}
