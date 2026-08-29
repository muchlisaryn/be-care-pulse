<?php

namespace App\Http\Controllers\Nafsul;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Education;
use App\Models\GroupLeader;
use App\Models\Member;
use App\Models\MemberFamily;
use App\Models\MemberStatus;
use App\Models\Occupation;
use App\Models\Region;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Anggota Nafsul Muthmainah.
 *
 * Tabel & kolom database berbahasa Inggris (`members`, `region_id`, …),
 * sedangkan kontrak API tetap memakai nama lama (`nama`, `kode_wilayah`,
 * `noketua`, …). Penerjemahan dua arah ditangani model Member lewat trait
 * HasLegacyAttributes — di dalam controller, kolom selalu ditulis dengan nama
 * barunya.
 */
class AnggotaController extends Controller
{
    private const RELATIONS = [
        'region', 'groupLeader', 'birthCity', 'memberStatus', 'education', 'occupation', 'families',
    ];

    public function index(Request $request)
    {
        $query = Member::query()
            ->with(['region', 'groupLeader', 'birthCity', 'memberStatus', 'education', 'occupation']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('member_number', 'like', "%{$search}%")
                    ->orWhere('id_card_number', 'like', "%{$search}%")
                    ->orWhere('family_card_number', 'like', "%{$search}%");
            });
        }

        // Parameter filter tetap dikirim frontend sebagai kode master, jadi
        // pencocokannya lewat relasi, bukan langsung ke kolom id.
        if ($status = $request->query('kode_status')) {
            $query->whereHas('memberStatus', fn ($q) => $q->where('code', $status));
        }

        if ($wilayah = $request->query('kode_wilayah')) {
            $query->whereHas('region', fn ($q) => $q->where('code', $wilayah));
        }

        if ($ketua = $request->query('noketua')) {
            $query->whereHas('groupLeader', fn ($q) => $q->where('code', $ketua));
        }

        /**
         * Sembunyikan anggota tertentu dari hasil.
         *
         * Dipakai form transaksi: anggota yang sudah masuk daftar rincian tidak
         * ditawarkan lagi di dropdown. Penyaringannya di server, bukan di
         * browser, karena daftar ini berpaginasi — membuang baris setelah
         * diterima akan menyisakan halaman yang lebih pendek dari `per_page`
         * dan bisa tampak kosong padahal masih ada anggota lain di belakangnya.
         */
        if ($kecuali = $request->query('exclude_ids')) {
            $ids = array_filter(array_map('intval', explode(',', (string) $kecuali)));

            if ($ids !== []) {
                $query->whereNotIn('id', $ids);
            }
        }

        $tipe = $request->query('tipe');
        if (in_array($tipe, ['pribadi', 'kelompok'], true)) {
            $this->filterTipe($query, $tipe);
        }

        // Filter bulan/tahun: masing-masing berdiri sendiri — tahun saja = setahun penuh,
        // bulan saja = bulan itu di semua tahun.
        if ($bulan = $request->integer('aktif_bulan')) {
            $query->whereMonth('active_date', $bulan);
        }
        if ($tahun = $request->integer('aktif_tahun')) {
            $query->whereYear('active_date', $tahun);
        }

        // Nama sort tetap memakai istilah API, lalu dipetakan ke kolom database.
        $sort = $request->query('sort', 'nama');
        $dir = $request->query('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $allowedSorts = ['nama', 'no_anggota', 'tgl_aktif', 'created_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'nama';
        }
        $query->orderBy(Member::kolomBaru($sort), $dir);

        /**
         * `all=1` → seluruh baris tanpa paginasi, sebagai ARRAY polos.
         *
         * Dipakai pengisi sheet referensi di impor transaksi: file template
         * memuat daftar No. Anggota ↔ nama supaya petugas tidak perlu menebak
         * nomornya. Sama dengan master Nafsul lain yang sudah punya `all`.
         *
         * Tanpa ini, pemanggil yang mengirim `all=1` tetap menerima OBJEK
         * paginasi — bentuk yang tidak bisa di-`map` dan bikin pemanggilnya
         * gagal dengan galat yang tidak menyebut sebabnya.
         */
        if ($request->boolean('all')) {
            return response()->json($query->get());
        }

        /**
         * Periode iuran TERAKHIR yang sudah dibayar tiap anggota.
         *
         * Subquery berkorelasi, bukan `withMax`: periodenya tersimpan di DUA
         * kolom (`month` + `year`), dan yang terbesar bukan bulan terbesar
         * melainkan pasangan tahun-bulan terbesar — 01/2026 lebih baru daripada
         * 12/2025. `year * 100 + month` mengurutkan keduanya sekaligus sebagai
         * satu bilangan.
         *
         * Ditambahkan SETELAH cabang `all`: pemanggil `all=1` menarik seluruh
         * anggota untuk mengisi dropdown, dan subquery per baris di sana berarti
         * puluhan ribu subquery untuk kolom yang tidak pernah ditampilkannya.
         *
         * `select('members.*')` wajib disebut: begitu `addSelect` dipanggil pada
         * query yang belum memilih kolom apa pun, `*`-nya hilang dan yang
         * terkirim hanya subquery-nya.
         */
        $query->select('members.*')->addSelect([
            'periode_terakhir_raw' => Transaction::selectRaw('MAX(year * 100 + month)')
                ->whereColumn('member_id', 'members.id')
                ->whereNotNull('month'),
        ]);

        $halaman = $query->paginate($request->integer('per_page', 25));

        // Angka gabungan tadi diterjemahkan ke bentuk yang dipakai di layar
        // ("MM/YYYY"), dan bentuk mentahnya dibuang supaya tidak ikut terkirim —
        // 202601 tidak berarti apa-apa bagi pemanggilnya.
        $halaman->through(function (Member $anggota) {
            $anggota->periode_terakhir_bayar = self::formatPeriode($anggota->periode_terakhir_raw);
            unset($anggota->periode_terakhir_raw);

            return $anggota;
        });

        return response()->json($halaman);
    }

    /** `202601` → `"01/2026"`. Null tetap null: anggota itu belum pernah bayar. */
    private static function formatPeriode(int|string|null $gabungan): ?string
    {
        if ($gabungan === null || $gabungan === '') {
            return null;
        }

        $angka = (int) $gabungan;

        return str_pad((string) ($angka % 100), 2, '0', STR_PAD_LEFT).'/'.intdiv($angka, 100);
    }

    /**
     * Periode iuran terakhir seorang anggota, beserta berapa bulan ia
     * tertinggal dari bulan berjalan.
     *
     * Terpisah dari `riwayatTransaksi()` walau jawabannya ada di sana juga.
     * Yang ini dipanggil form transaksi SETIAP KALI anggota dipilih, dan
     * riwayat menarik seluruh barisnya — pada anggota terlama di data ini 187
     * baris — hanya untuk membaca satu angka di ujungnya. Di sini cukup satu
     * agregat, tanpa satu pun baris ikut terkirim.
     *
     * `bulan_tertinggal` dihitung di server terhadap bulan berjalan, bukan
     * diserahkan ke peramban: jam peramban bisa meleset atau disetel zona lain,
     * dan angka tunggakan yang berbeda-beda tergantung mesin petugas adalah
     * angka yang tidak bisa dipakai berdebat.
     */
    public function pembayaranTerakhir(Member $member)
    {
        $gabungan = Transaction::where('member_id', $member->id)
            ->whereNotNull('month')
            ->selectRaw('MAX(year * 100 + month) AS v')
            ->value('v');

        if ($gabungan === null) {
            return response()->json([
                'periode_terakhir' => null,
                'bulan_tertinggal' => null,
                'pernah_bayar' => false,
            ]);
        }

        $terakhir = (int) $gabungan;
        $sekarang = now();

        // Selisih dihitung sebagai jarak BULAN, bukan selisih hari: iuran
        // menandai bulan, dan "05/2026 ke Agustus" adalah tiga bulan entah
        // tanggal berapa pun hari ini.
        $tertinggal = ($sekarang->year * 12 + $sekarang->month)
            - (intdiv($terakhir, 100) * 12 + $terakhir % 100);

        return response()->json([
            'periode_terakhir' => self::formatPeriode($terakhir),
            // Negatif berarti anggotanya justru sudah membayar di muka —
            // dilaporkan apa adanya, biar pemanggilnya yang memutuskan.
            'bulan_tertinggal' => $tertinggal,
            'pernah_bayar' => true,
        ]);
    }

    /**
     * Riwayat iuran seorang anggota — seluruh rincian yang pernah tercatat
     * atas namanya, terbaru lebih dulu.
     *
     * Dipakai modal di master anggota, yang terbuka saat kolom "Periode Terakhir
     * Bayar" diklik. Berdiri sendiri, tidak menumpang `show`: `show` dipakai form
     * ubah anggota dan tidak ada gunanya ikut menyeret ratusan baris iuran ke
     * sana setiap kali form itu dibuka.
     *
     * Baris tanpa kuitansi (`transaction_header_id` null) ikut ditampilkan —
     * itulah tagihan yang sudah dicatat tapi belum dibayar, dan justru itu yang
     * dicari orang saat membuka riwayat.
     */
    public function riwayatTransaksi(Member $member)
    {
        $baris = Transaction::where('member_id', $member->id)
            ->with(['rate:id,code,name', 'header:id,transaction_number,date,payment_method,validation_at'])
            // Yang belum berperiode (tarif sekali bayar) ditaruh paling bawah:
            // ia tidak punya tempat dalam urutan kronologis periode.
            ->orderByRaw('(year IS NULL) asc')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'anggota' => [
                'id' => $member->id,
                'no_anggota' => $member->member_number,
                'nama' => $member->name,
            ],
            'ringkasan' => [
                'jumlah_baris' => $baris->count(),
                'total_dibayar' => number_format(
                    $baris->sum(fn ($t) => (float) $t->total), 2, '.', ''
                ),
                'periode_terakhir' => self::formatPeriode(
                    $baris->filter(fn ($t) => $t->month !== null)
                        ->map(fn ($t) => (int) $t->year * 100 + (int) $t->month)
                        ->max()
                ),
            ],
            'riwayat' => $baris->map(fn ($t) => [
                'id' => $t->id,
                'uuid' => $t->uuid,
                'periode' => $t->payment_period,
                'tarif' => $t->rate?->name,
                'kode_tarif' => $t->rate?->code,
                'nominal' => $t->amount,
                'diskon' => $t->discount,
                'total' => $t->total,
                // Null = rincian ini belum masuk kuitansi mana pun, alias belum
                // dibayar. Frontend membedakannya lewat kolom ini.
                'no_kuitansi' => $t->header?->transaction_number,
                'tanggal' => optional($t->header?->date)->toDateString(),
                'metode' => $t->header?->payment_method,
                'divalidasi' => $t->header?->validation_at !== null,
            ]),
        ]);
    }

    /**
     * Jumlah anggota per tipe, dihitung dengan COUNT di database — bukan dengan
     * menarik seluruh baris lalu menghitungnya di aplikasi.
     */
    public function statistik()
    {
        $pribadi = $this->filterTipe(Member::query(), 'pribadi')->count();
        $kelompok = $this->filterTipe(Member::query(), 'kelompok')->count();

        return response()->json([
            'pribadi' => $pribadi,
            'kelompok' => $kelompok,
            'total' => $pribadi + $kelompok,
        ]);
    }

    /**
     * Saring anggota menurut tipe — dipakai bersama oleh daftar & statistik
     * supaya definisinya tidak pernah berbeda di antara keduanya.
     *
     * Tipe ditentukan ketuanya: anggota perorangan ditampung ketua bernama
     * "Pribadi", selain itu terhitung kelompok. Namanya dicocokkan **persis** —
     * master ketua juga memuat nama orang yang kebetulan memuat kata itu
     * (mis. "Filosa Idham Pribadi").
     *
     * Anggota lama yang belum punya ketua sama sekali ikut dihitung pribadi
     * supaya tetap terjangkau filter maupun statistik.
     */
    private function filterTipe(Builder $query, string $tipe): Builder
    {
        if ($tipe === 'kelompok') {
            return $query->whereHas('groupLeader', fn ($q) => $q->where('name', '!=', GroupLeader::NAMA_PRIBADI));
        }

        return $query->where(fn ($q) => $q
            ->whereDoesntHave('groupLeader')
            ->orWhereHas('groupLeader', fn ($k) => $k->where('name', GroupLeader::NAMA_PRIBADI)));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $keluarga = $data['keluarga'] ?? null;
        unset($data['keluarga']);

        if (empty($data['no_anggota'])) {
            $data['no_anggota'] = $this->generateNoAnggota($data['tgl_aktif'] ?? null);
        }

        $member = DB::transaction(function () use ($data, $keluarga) {
            $member = Member::create(Member::fromLegacy($data));
            if ($keluarga !== null) {
                $member->families()->createMany(
                    $this->numberKeluarga($member->member_number, $keluarga)
                );
            }

            return $member;
        });

        return response()->json($member->load(self::RELATIONS), 201);
    }

    public function show(Member $member)
    {
        return response()->json($member->load(self::RELATIONS));
    }

    public function update(Request $request, Member $member)
    {
        $data = $this->validateData($request, $member);
        $keluarga = $data['keluarga'] ?? null;
        unset($data['keluarga']);

        DB::transaction(function () use ($member, $data, $keluarga) {
            $member->update(Member::fromLegacy($data));
            if ($keluarga !== null) {
                $member->families()->delete();
                $member->families()->createMany(
                    $this->numberKeluarga($member->member_number, $keluarga)
                );
            }
        });

        return response()->json($member->load(self::RELATIONS));
    }

    public function destroy(Member $member)
    {
        $member->delete();

        return response()->json(['message' => 'Anggota dihapus.']);
    }

    /**
     * Impor anggota dari Excel, dikirim per batch oleh frontend.
     *
     * Frontend membaca file Excel-nya sendiri lalu mengirim maksimal 10 baris
     * per permintaan supaya progres "x dari y" bisa ditampilkan dan file besar
     * tidak diproses sekaligus.
     *
     * Tiap baris divalidasi & disimpan sendiri-sendiri: satu baris gagal tidak
     * membatalkan baris lain dalam batch yang sama, dan hasilnya dikembalikan
     * per baris agar bisa ditampilkan sebagai daftar kesalahan.
     */
    public function import(Request $request)
    {
        $payload = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:50'],
            'rows.*' => ['array'],
        ]);

        $maps = $this->masterMaps();
        $hasil = [];
        $berhasil = 0;

        foreach ($payload['rows'] as $i => $row) {
            // `baris` = nomor baris di file Excel, dikirim FE supaya pesan
            // kesalahan menunjuk ke baris yang benar di file aslinya.
            $baris = (int) ($row['baris'] ?? $i + 1);
            $nama = trim((string) ($row['nama'] ?? ''));

            try {
                $member = $this->importRow($row, $maps);
                $berhasil++;
                $hasil[] = [
                    'baris' => $baris,
                    'status' => 'ok',
                    'id' => $member->id,
                    'nama' => $member->name,
                    'no_anggota' => $member->member_number,
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
    private function importRow(array $row, array $maps): Member
    {
        // Kolom ID di template hanya rujukan ke data yang sudah ada. Impor tidak
        // memperbarui anggota lama, jadi baris ber-ID ditolak daripada diam-diam
        // membuat duplikat.
        if (isset($row['id']) && trim((string) $row['id']) !== '') {
            throw ValidationException::withMessages([
                'id' => 'Kolom ID hanya rujukan data yang sudah ada — kosongkan untuk impor anggota baru.',
            ]);
        }

        $data = $this->normalizeImportRow($row, $maps);

        $validator = Validator::make(
            $data,
            $this->importRules(),
            [
                'required' => ':attribute wajib diisi.',
                'exists' => ':attribute ":input" tidak ada di master.',
                'in' => 'Isi :attribute ":input" tidak dikenali.',
                'date' => ':attribute ":input" bukan tanggal yang sah.',
                'max' => ':attribute terlalu panjang (maks :max karakter).',
                'string' => ':attribute harus berupa teks.',
                // Kolom ber-id: nilai yang bukan angka berarti namanya tidak
                // ketemu di master saat baris dinormalkan.
                'integer' => 'Isi :attribute ":input" tidak dikenali.',
            ],
            [
                'nama' => 'Nama Lengkap',
                'no_anggota' => 'No. Anggota',
                'kode_wilayah' => 'Wilayah',
                'noketua' => 'Ketua Kelompok',
                'kode_kota_lahir' => 'Kota Lahir',
                'kode_status' => 'Status Anggota',
                'pendidikan_id' => 'Pendidikan',
                'pekerjaan_id' => 'Pekerjaan',
                'nokk' => 'No. KK',
                'noktp' => 'No. KTP',
                'tgl_lahir' => 'Tanggal Lahir',
                'jenis_kelamin' => 'Jenis Kelamin',
                'status_nikah' => 'Status Nikah',
                'tgl_aktif' => 'Tanggal Aktif',
                'tgl_nonaktif' => 'Tanggal Nonaktif',
                'nama_keluarga' => 'Nama Keluarga',
                'telepon_keluarga' => 'Telepon Keluarga',
                'alamat_keluarga' => 'Alamat Keluarga',
            ]
        );
        $validator->stopOnFirstFailure();

        $data = $validator->validate();

        // Nomor anggota diambil dari file Excel bila diisi; bila kosong dibuat
        // otomatis mengikuti nomor terakhir, sama seperti pendaftaran manual.
        if (empty($data['no_anggota'])) {
            $data['no_anggota'] = $this->generateNoAnggota($data['tgl_aktif'] ?? null);
        } elseif (Member::withTrashed()->withDisabled()->where('member_number', $data['no_anggota'])->exists()) {
            throw ValidationException::withMessages([
                'no_anggota' => "No. Anggota {$data['no_anggota']} sudah dipakai anggota lain.",
            ]);
        }

        return Member::create(Member::fromLegacy($data));
    }

    /**
     * Samakan bentuk data baris Excel dengan kolom tabel anggota.
     *
     * Kolom master boleh diisi kuncinya ATAU namanya — kode untuk wilayah, kota,
     * status, dan ketua; id untuk pendidikan & pekerjaan. Nama dicocokkan tanpa
     * memperhatikan huruf besar/kecil. Nilai yang tidak dikenali dibiarkan apa
     * adanya supaya ditolak validasi dengan pesan yang menunjuk kolomnya.
     */
    private function normalizeImportRow(array $row, array $maps): array
    {
        $data = [];

        foreach (array_keys($this->importRules()) as $field) {
            $value = $row[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $data[$field] = ($value === '' || $value === null) ? null : $value;
        }

        // "Laki-laki" / "Perempuan" / "L" / "P" → satu huruf. Nilai lain
        // dibiarkan agar ditolak aturan `in:L,P`.
        if ($data['jenis_kelamin'] !== null) {
            $data['jenis_kelamin'] = mb_strtoupper(mb_substr((string) $data['jenis_kelamin'], 0, 1));
        }

        foreach ($maps as $field => $map) {
            if ($data[$field] === null) {
                continue;
            }
            $value = (string) $data[$field];
            if (! in_array($value, $map['kode'], true)) {
                $data[$field] = $map['nama'][mb_strtolower($value)] ?? $value;
            }
        }

        // Default disamakan dengan form pendaftaran manual: aktif sejak hari
        // impor dan berstatus aktif, supaya anggota langsung terhitung di Laporan.
        $data['tgl_aktif'] ??= now()->toDateString();
        if ($data['kode_status'] === null && in_array('STS1', $maps['kode_status']['kode'], true)) {
            $data['kode_status'] = 'STS1';
        }

        return $data;
    }

    /**
     * Aturan validasi impor — sama dengan store, tanpa data keluarga.
     *
     * Kuncinya memakai nama field API; nilai kolom master berupa kunci masternya,
     * jadi `exists` diarahkan ke kolom `code` (atau `id`) tabel masternya.
     */
    private function importRules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:255'],
            'no_anggota' => ['nullable', 'string', 'max:50'],
            'kode_wilayah' => ['nullable', 'string', 'exists:regions,code'],
            'noketua' => ['nullable', 'string', 'exists:group_leaders,code'],
            'kode_kota_lahir' => ['nullable', 'string', 'exists:cities,code'],
            'kode_status' => ['nullable', 'string', 'exists:member_statuses,code'],
            'nokk' => ['nullable', 'string', 'max:50'],
            'noktp' => ['nullable', 'string', 'max:50'],
            'tgl_lahir' => ['nullable', 'date'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'pendidikan_id' => ['nullable', 'integer', 'exists:educations,id'],
            'pekerjaan_id' => ['nullable', 'integer', 'exists:occupations,id'],
            'status_nikah' => ['nullable', 'string', 'max:50'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'tgl_aktif' => ['nullable', 'date'],
            'tgl_nonaktif' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'nama_keluarga' => ['nullable', 'string', 'max:255'],
            'hubungan' => ['nullable', 'string', 'max:100'],
            'alamat_keluarga' => ['nullable', 'string'],
            'telepon_keluarga' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Daftar kunci & peta nama→kunci tiap master, dibangun sekali per permintaan.
     *
     * Kuncinya tidak seragam: wilayah, kota, status, dan ketua dirujuk lewat
     * `code`, sedangkan pendidikan & pekerjaan lewat `id` karena masternya
     * memang tidak punya kolom kode.
     */
    private function masterMaps(): array
    {
        $build = function ($rows, string $kunci = 'code') {
            return [
                'kode' => $rows->pluck($kunci)->map(fn ($k) => (string) $k)->all(),
                'nama' => $rows
                    ->mapWithKeys(fn ($r) => [mb_strtolower((string) $r->name) => (string) $r->{$kunci}])
                    ->all(),
            ];
        };

        return [
            'kode_wilayah' => $build(Region::all(['code', 'name'])),
            'kode_kota_lahir' => $build(City::all(['code', 'name'])),
            'kode_status' => $build(MemberStatus::all(['code', 'name'])),
            'noketua' => $build(GroupLeader::all(['code', 'name'])),
            'pendidikan_id' => $build(Education::all(['id', 'name']), 'id'),
            'pekerjaan_id' => $build(Occupation::all(['id', 'name']), 'id'),
        ];
    }

    /**
     * Nomor anggota = 2 digit tahun + 2 digit bulan + 2 digit tanggal
     * (diambil dari tgl_aktif, atau hari ini bila kosong) + urut 2 digit yang
     * dihitung ulang setiap hari.
     *
     * Contoh: 21 Agustus 2026, anggota pertama hari itu → "26082101".
     */
    private function generateNoAnggota(?string $tglAktif): string
    {
        $prefix = ($tglAktif ? Carbon::parse($tglAktif) : now())->format('ymd');

        // Nomor terbesar di tanggal yang sama. `withTrashed()` agar nomor milik
        // record terhapus tidak dipakai ulang anggota baru.
        //
        // Urut per panjang dulu, baru per nilai: kalau satu hari tembus 99
        // anggota, "260821100" harus dianggap lebih besar dari "26082199" —
        // perbandingan teks saja akan membalik keduanya.
        $max = Member::withTrashed()->withDisabled()
            ->where('member_number', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(member_number) DESC')
            ->orderBy('member_number', 'desc')
            ->value('member_number');

        $seq = $max ? ((int) substr($max, 6)) + 1 : 1;

        // Nomor yang dihasilkan tetap diperiksa satu per satu sebelum dipakai.
        // Data lama memakai format lain (YYMM + 3 digit), dan nomor seperti
        // "2608211" ikut tertangkap pola LIKE di atas lalu terbaca sebagai urut
        // yang keliru. Tanpa pemeriksaan ini, impor bisa menabrak index unik
        // `members.member_number` dan gagal dengan galat database mentah.
        do {
            $kandidat = $prefix.str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
            $seq++;
        } while (Member::withTrashed()->withDisabled()->where('member_number', $kandidat)->exists());

        return $kandidat;
    }

    /**
     * Beri no_anggota otomatis ke tiap anggota keluarga, mengikuti nomor
     * anggota utama + urutan. Contoh utama "26082101" → "26082101-01", "26082101-02".
     *
     * Baris dikirim dengan nama field API, jadi diterjemahkan ke kolom database
     * sebelum disimpan.
     */
    private function numberKeluarga(?string $baseNo, array $keluarga): array
    {
        return array_map(function ($row, $i) use ($baseNo) {
            $row['no_anggota'] = $baseNo
                ? $baseNo.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)
                : null;

            return MemberFamily::fromLegacy($row);
        }, $keluarga, array_keys($keluarga));
    }

    /**
     * `$member` diisi saat update supaya barisnya sendiri tidak dihitung
     * sebagai bentrokan nomor.
     */
    private function validateData(Request $request, ?Member $member = null): array
    {
        return $request->validate([
            'kode_wilayah' => ['nullable', 'string', 'exists:regions,code'],
            'noketua' => ['nullable', 'string', 'exists:group_leaders,code'],
            'nokk' => ['nullable', 'string', 'max:50'],
            // Dicek lewat query tabel langsung (bukan Eloquent), jadi baris
            // yang sudah di-soft-delete ikut terhitung — sama persis dengan
            // cakupan index unik di database, supaya validasi tidak meloloskan
            // nomor yang justru ditolak saat disimpan.
            'no_anggota' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('members', 'member_number')->ignore($member?->id),
            ],
            'nama' => ['required', 'string', 'max:255'],
            'tgl_lahir' => ['nullable', 'date'],
            'kode_kota_lahir' => ['nullable', 'string', 'exists:cities,code'],
            'jenis_kelamin' => ['nullable', 'in:L,P'],
            'pendidikan_id' => ['nullable', 'integer', 'exists:educations,id'],
            'pekerjaan_id' => ['nullable', 'integer', 'exists:occupations,id'],
            'status_nikah' => ['nullable', 'string', 'max:50'],
            'noktp' => ['nullable', 'string', 'max:50'],
            'alamat' => ['nullable', 'string'],
            'telepon' => ['nullable', 'string', 'max:50'],
            'tgl_aktif' => ['nullable', 'date'],
            'tgl_nonaktif' => ['nullable', 'date'],
            'kode_status' => ['nullable', 'string', 'exists:member_statuses,code'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'nama_keluarga' => ['nullable', 'string', 'max:255'],
            'hubungan' => ['nullable', 'string', 'max:100'],
            'alamat_keluarga' => ['nullable', 'string'],
            'telepon_keluarga' => ['nullable', 'string', 'max:50'],
            'kode_pengguna' => ['nullable', 'string', 'max:50'],
            'kunjungan' => ['nullable', 'string', 'max:50'],
            'tgl_update' => ['nullable', 'date'],
            'keluarga' => ['nullable', 'array'],
            'keluarga.*.nama_ketua' => ['nullable', 'string', 'max:255'],
            'keluarga.*.no_anggota' => ['nullable', 'string', 'max:50'],
            'keluarga.*.nama_anggota' => ['required', 'string', 'max:255'],
            'keluarga.*.tgl_lahir' => ['nullable', 'date'],
            'keluarga.*.jenis_kelamin' => ['nullable', 'in:L,P'],
            'keluarga.*.pendidikan' => ['nullable', 'string', 'max:100'],
        ], [
            'no_anggota.unique' => 'No. Anggota :input sudah dipakai anggota lain.',
        ]);
    }
}
