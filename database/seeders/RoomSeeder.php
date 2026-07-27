<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Daftar ruangan disalin dari data produksi (care-pulse.rsijpondokkopi.com)
     * per 27 Juli 2026. Format: [code, name, layanan].
     */
    private const ROOMS = [
        ['RXJN', 'AN - NUR 1', 'rawat_inap'],
        ['IGGG', 'AN - NUR 2 KEBIDANAN', 'rawat_inap'],
        ['IJJV', 'AN - NAJMI', 'rawat_inap'],
        ['WZQO', 'AN - NAS 1', 'rawat_inap'],
        ['OIGF', 'AN - NAS 2', 'rawat_inap'],
        ['CZZU', 'AN - NISA 1', 'rawat_inap'],
        ['WVPF', 'AN - NISA 2', 'rawat_inap'],
        ['CVQG', 'HCU - PICU AN NISA 1', 'rawat_inap'],
        ['PCND', 'ICU - HCU', 'rawat_inap'],
        ['EYUM', 'SCN - NICU', 'rawat_inap'],
        ['BPMQ', 'IGD', 'igd'],
        ['DIWU', 'HEMODIALISA ATAS', 'rawat_jalan'],
        ['ZBLJ', 'HEMODIALISA BAWAH', 'rawat_jalan'],
        ['GPEJ', 'POLI GIGI AHMAD DAHLAN', 'rawat_jalan'],
        ['BLMX', 'POLI GIGI WALIDAH', 'rawat_jalan'],
        ['QXRA', 'POLI BEDAH AHMAD DAHLAN', 'rawat_jalan'],
        ['XCWB', 'POLI BEDAH WALIDAH', 'rawat_jalan'],
        ['SRPP', 'POLI PENYAKIT DALAM WALIDAH', 'rawat_jalan'],
        ['IIXK', 'POLI PENYAKIT DALAM AHMAD DAHLAN', 'rawat_jalan'],
        ['UOPY', 'POLI KULIT & KELAMIN', 'rawat_jalan'],
        ['XSLM', 'POLI KEBIDANAN WALIDAH', 'rawat_jalan'],
        ['VLTA', 'POLI KEBIDANAN AHMAD DAHLAN', 'rawat_jalan'],
        ['BPES', 'POLI ANAK WALIDAH', 'rawat_jalan'],
        ['DUAY', 'POLI ANAK AHMAD DAHLAN', 'rawat_jalan'],
        ['CBAG', 'LABORATORIUM', 'rawat_jalan'],
        ['VXDB', 'DIAGNOSTIK', 'rawat_jalan'],
        ['WLJN', 'POLI MATA', 'rawat_jalan'],
        ['UCQQ', 'POLI ESWL', 'rawat_jalan'],
        ['OVZM', 'POLI ENDOSCOPY', 'rawat_jalan'],
        ['YDLC', 'RONTGEN', 'rawat_jalan'],
        ['DBQG', 'RAWAT INAP CCPK', 'rawat_inap'],
        ['SHUC', 'REHAB MEDIK', 'rawat_jalan'],
        ['UQGA', 'AN - NASR 1', 'rawat_inap'],
        ['WEJK', 'AN - NASR 2', 'rawat_inap'],
        ['FQYS', 'RADIOTHERAPI', 'rawat_jalan'],
        ['WJHZ', 'POLI CCPK', 'rawat_jalan'],
        ['MRNV', 'RAWAT JALAN CCPK', 'rawat_jalan'],
        ['IDPJ', 'BINKESMAS', 'rawat_jalan'],
        ['HOWE', 'MEDICAL CHECK-UP', 'rawat_jalan'],
    ];

    public function run(): void
    {
        foreach (self::ROOMS as [$code, $name, $layanan]) {
            $room = Room::withoutGlobalScopes()->where('name', $name)->first();

            // Sudah ada: cukup selaraskan layanan-nya, jangan sentuh code supaya
            // relasi/QR yang sudah tercetak tidak berubah.
            if ($room) {
                if ($room->layanan !== $layanan) {
                    $room->layanan = $layanan;
                    $room->save();
                }

                continue;
            }

            // Set code eksplisit (bukan mass-assign): aman walau event model dimatikan
            // (DatabaseSeeder pakai WithoutModelEvents). Pakai code produksi bila masih
            // bebas, kalau bentrok jatuh ke kode acak agar kolom unik tetap aman.
            $room = new Room(['name' => $name, 'layanan' => $layanan]);
            $room->code = $this->isCodeTaken($code) ? $this->uniqueCode() : $code;
            $room->save();
        }
    }

    private function isCodeTaken(string $code): bool
    {
        return Room::withoutGlobalScopes()->where('code', $code)->exists();
    }

    /** Hasilkan kode 4 huruf (A-Z) yang belum dipakai ruangan lain. */
    private function uniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= chr(random_int(65, 90));
            }
        } while ($this->isCodeTaken($code));

        return $code;
    }
}
