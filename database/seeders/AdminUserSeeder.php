<?php

namespace Database\Seeders;

use App\Models\Authority;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * User bawaan Administrator — satu-satunya akun di database baru, dipakai untuk
 * login pertama kali sebelum user lain dibuat lewat menu Pengguna.
 *
 * Kredensial: username `admin` / password `Admin@12345`.
 *
 * WAJIB dijalankan SETELAH AuthoritySeeder: authority "Administrator" (yang
 * memegang seluruh menu) baru ada di sana, dan tanpa `authority_id` user ini
 * login tanpa satu pun menu.
 *
 * Idempotent lewat firstOrNew + save: dijalankan berulang tidak menggandakan
 * baris (kolom `username` unik) dan sekaligus MEMULIHKAN akun — password
 * dikembalikan ke nilai bawaan dan penanda soft delete dibersihkan, jadi seeder
 * ini juga jalan darurat saat admin terkunci dari sistemnya sendiri.
 * Konsekuensinya: password yang sudah diganti lewat UI ikut ter-reset tiap
 * seeder ini dijalankan ulang.
 *
 * withTrashed() dipakai karena global scope `active` menyembunyikan user yang
 * sudah di-soft-delete — tanpa itu firstOrNew mencoba INSERT baris baru dan
 * gagal dengan 1062 Duplicate entry di index unik `username`.
 *
 * forceFill(), bukan fill(): kolom audit (`deleted_at`/`deleted_by`/
 * `deleted_user_id`) sengaja tidak masuk `$fillable` User, jadi mass assignment
 * biasa akan mengabaikannya dan akun tetap tampak terhapus.
 *
 * Jalankan sendiri: php artisan db:seed --class=AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $authorityId = Authority::withTrashed()
            ->where('name', 'Administrator')
            ->value('id');

        if ($authorityId === null) {
            $this->command?->warn('Authority "Administrator" belum ada. Jalankan AuthoritySeeder dulu, lalu ulangi seeder ini.');
        }

        $admin = User::withTrashed()->firstOrNew(['username' => 'admin']);

        $admin->forceFill([
            'name' => 'Administrator',
            // Email opsional & unik: pertahankan yang sudah diisi petugas,
            // isi bawaan hanya saat akunnya benar-benar baru.
            'email' => $admin->email ?: 'admin@care-pulse.local',
            // Cast `hashed` pada model User yang meng-hash nilai ini.
            'password' => 'Admin@12345',
            'authority_id' => $authorityId ?? $admin->authority_id,
            'deleted_at' => null,
            'deleted_by' => null,
            'deleted_user_id' => null,
        ])->save();

        $this->command?->info('User "admin" siap dipakai — password: Admin@12345');
    }
}
