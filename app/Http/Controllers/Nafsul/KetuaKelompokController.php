<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\GroupLeader;
use App\Traits\RecreatesSoftDeleted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Master ketua kelompok.
 *
 * Tabel & kolomnya berbahasa Inggris (`group_leaders`), sedangkan kontrak API
 * tetap memakai `noketua`, `nama`, dst — penerjemahannya ditangani
 * model GroupLeader. URL tetap memakai kode ketua, bukan id.
 */
class KetuaKelompokController extends Controller
{
    use RecreatesSoftDeleted;

    public function index(Request $request)
    {
        // `anggota_count` dihitung lewat subquery COUNT, bukan dengan memuat
        // seluruh anggota tiap ketua.
        $query = GroupLeader::query()->withCount(['members as anggota_count']);

        // Halaman "Anggota Kelompok" hanya menampilkan kelompok sungguhan,
        // di luar ketua penampung anggota perorangan.
        if ($request->boolean('tanpa_pribadi')) {
            $query->kelompok();
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('all')) {
            return response()->json($query->orderBy('name')->get());
        }

        return response()->json($query->orderBy('name')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'noketua' => ['nullable', 'string', 'max:50', Rule::unique('group_leaders', 'code')->whereNull('deleted_by')],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:50'],
        ]);

        $data = GroupLeader::fromLegacy($data);

        if (empty($data['code'])) {
            $data['code'] = $this->generateKodeKetua();
        }

        $groupLeader = $this->createOrRestore(GroupLeader::class, 'code', $data);

        return response()->json($groupLeader, 201);
    }

    /**
     * Kode ketua otomatis: "KKL" + 2 digit tahun + 2 digit bulan + 3 digit urut
     * yang dihitung ulang tiap bulan. Contoh: ketua pertama Agustus 2026 →
     * "KKL2608001".
     */
    private function generateKodeKetua(): string
    {
        $prefix = 'KKL'.now()->format('ym');

        // Kode terbesar di bulan yang sama. `withTrashed()` wajib: kode yang
        // sudah di-soft-delete tetap menempati indeks unik, jadi kode baru harus
        // menghindarinya juga.
        //
        // Urut per panjang dulu, baru per nilai: kalau satu bulan pernah tembus
        // 999 ketua, "KKL26081000" harus dianggap lebih besar dari "KKL2608999" —
        // perbandingan teks saja akan membalik keduanya.
        $max = GroupLeader::withTrashed()
            ->where('code', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(code) DESC')
            ->orderBy('code', 'desc')
            ->value('code');

        $seq = $max ? ((int) substr((string) $max, strlen($prefix))) : 0;

        // Loop pengaman: dua permintaan bersamaan bisa membaca `max` yang sama,
        // dan kolom `code` ber-indeks unik akan menolak yang kedua.
        do {
            $seq++;
            $kode = $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        } while (GroupLeader::withTrashed()->where('code', $kode)->exists());

        return $kode;
    }

    /**
     * Impor ketua kelompok dari Excel, dikirim per batch oleh frontend.
     *
     * Pola & bentuk responsnya sama dengan impor anggota: tiap baris divalidasi
     * dan disimpan sendiri-sendiri sehingga satu baris gagal tidak membatalkan
     * baris lain di batch yang sama, dan hasilnya dikembalikan per baris.
     */
    public function import(Request $request)
    {
        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*' => ['array'],
        ]);

        $hasil = [];
        $berhasil = 0;

        foreach ($payload['rows'] as $i => $row) {
            // `baris` = nomor baris di file Excel, dikirim FE supaya pesan
            // kesalahan menunjuk ke baris yang benar di file aslinya.
            $baris = (int) ($row['baris'] ?? $i + 1);
            $nama = trim((string) ($row['nama'] ?? ''));

            try {
                $ketua = $this->importRow($row);
                $berhasil++;
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'ok',
                    'noketua' => $ketua->code,
                    'nama' => $ketua->name,
                ];
            } catch (ValidationException $e) {
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'gagal',
                    'nama' => $nama,
                    'pesan' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'gagal',
                    'nama' => $nama,
                    'pesan' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'berhasil' => $berhasil,
            'gagal' => count($hasil) - $berhasil,
            'hasil' => $hasil,
        ]);
    }

    /** Simpan satu baris impor. Melempar ValidationException bila baris tidak valid. */
    private function importRow(array $row): GroupLeader
    {
        $data = [];
        foreach (['noketua', 'nama', 'jenis_kelamin', 'alamat', 'telepon'] as $field) {
            $value = $row[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

        // "Laki-laki" / "Perempuan" / "L" / "P" → satu huruf. Nilai lain
        // dibiarkan agar ditolak aturan `in:L,P`.
        if ($data['jenis_kelamin'] !== null) {
            $data['jenis_kelamin'] = mb_strtoupper(mb_substr((string) $data['jenis_kelamin'], 0, 1));
        }

        $validator = Validator::make(
            $data,
            [
                'noketua' => ['nullable', 'string', 'max:50'],
                'nama' => ['required', 'string', 'max:255'],
                'jenis_kelamin' => ['nullable', 'in:L,P'],
                'alamat' => ['nullable', 'string'],
                'telepon' => ['nullable', 'string', 'max:50'],
            ],
            [
                'required' => ':attribute wajib diisi.',
                'in' => 'Isi :attribute ":input" tidak dikenali.',
                'max' => ':attribute terlalu panjang (maks :max karakter).',
                'string' => ':attribute harus berupa teks.',
            ],
            [
                'noketua' => 'No. Ketua',
                'nama' => 'Nama',
                'jenis_kelamin' => 'Jenis Kelamin',
            ]
        );
        $validator->stopOnFirstFailure();

        $data = $validator->validate();

        // Kode diambil dari file bila diisi; bila kosong dibuat otomatis,
        // sama seperti penambahan manual lewat form.
        if (empty($data['noketua'])) {
            $data['noketua'] = $this->generateKodeKetua();
        } elseif (GroupLeader::withTrashed()->where('code', $data['noketua'])->exists()) {
            // Berbeda dengan `store` yang memulihkan baris terhapus: impor massal
            // tidak boleh diam-diam menimpa data yang sudah ada.
            throw ValidationException::withMessages([
                'noketua' => "No. Ketua {$data['noketua']} sudah dipakai ketua lain.",
            ]);
        }

        return GroupLeader::create(GroupLeader::fromLegacy($data));
    }

    public function show(GroupLeader $groupLeader)
    {
        // `anggota_count` dipertahankan sebagai nama field di API.
        $groupLeader->loadCount(['members as anggota_count']);

        return response()->json($groupLeader);
    }

    public function update(Request $request, GroupLeader $groupLeader)
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:50'],
        ]);

        $groupLeader->update(GroupLeader::fromLegacy($data));

        return response()->json($groupLeader);
    }

    public function destroy(GroupLeader $groupLeader)
    {
        $groupLeader->delete();

        return response()->json(['message' => 'Ketua kelompok dihapus.']);
    }
}
