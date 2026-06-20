<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    public function run(): void
    {
        $rooms = ['Annur 1', 'Annur 2', 'An Najmi'];

        foreach ($rooms as $name) {
            // Idempoten: lewati bila ruangan dengan nama ini sudah ada.
            if (Room::withoutGlobalScopes()->where('name', $name)->exists()) {
                continue;
            }

            // Set code eksplisit (bukan mass-assign): aman walau event model dimatikan
            // (DatabaseSeeder pakai WithoutModelEvents). Kode = 4 huruf acak unik.
            $room = new Room(['name' => $name]);
            $room->code = $this->uniqueCode();
            $room->save();
        }
    }

    /** Hasilkan kode 4 huruf (A–Z) yang belum dipakai ruangan lain. */
    private function uniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= chr(random_int(65, 90));
            }
        } while (Room::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }
}
