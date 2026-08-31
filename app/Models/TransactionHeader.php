<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use App\Traits\MarksDisabledWhenDeleted;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Header transaksi iuran Nafsul — satu baris = satu kuitansi pembayaran.
 *
 * Seperti `Transaction`, model ini tidak memakai `HasLegacyAttributes`: tabelnya
 * baru dan tidak punya kontrak API lama berbahasa Indonesia yang harus
 * dipertahankan.
 */
class TransactionHeader extends Model
{
    /**
     * `MarksDisabledWhenDeleted` menurunkan kolom `disabled` dari `deleted_by`.
     * Sengaja BUKAN di `$fillable`: nilainya ditetapkan trait itu, dan kalau bisa
     * ditumpangi mass assignment ia bisa berselisih dengan keadaan hapus yang
     * sebenarnya.
     */
    use HasAuditColumns, MarksDisabledWhenDeleted;

    protected $table = 'transaction_headers';

    /**
     * Cara bayar yang diterima.
     *
     * `other` = lain-lain: setoran yang tidak lewat kas maupun rekening —
     * potong tabungan, barter, atau titipan pengurus. Ditampung satu nilai saja
     * alih-alih menambah kolom keterangan bebas: yang dibutuhkan laporan cuma
     * pemisahan kas/bank, sisanya cukup dikelompokkan.
     *
     * Kolomnya `string`, bukan ENUM, jadi menambah nilai di sini TIDAK perlu
     * migrasi — alasan yang sama dengan `transaction_type`.
     */
    public const PAYMENT_METHODS = ['transfer', 'cash', 'other'];

    /** Jenis kuitansi yang diterima. */
    public const TRANSACTION_TYPES = ['kelompok', 'pribadi'];

    protected $fillable = [
        'transaction_number',
        // Tanggal uang DITERIMA — bukan kapan barisnya dibuat (`created_at`).
        // Keduanya sering berbeda: setoran Sabtu baru diinput Senin.
        'date',
        'transaction_type',
        'total',
        'member_deduction',
        'group_leader_deduction',
        'group_leader_fee_percent',
        'group_leader_fee',
        'payment',
        'payment_method',
        // Jejak pemeriksaan kuitansi. `validation_at` NULL = belum divalidasi.
        'validation_at',
        'validation_by',
    ];

    protected $casts = [
        'date' => 'date',
        'validation_at' => 'datetime',
        'total' => 'decimal:2',
        'member_deduction' => 'decimal:2',
        'group_leader_deduction' => 'decimal:2',
        'group_leader_fee' => 'decimal:2',
        'group_leader_fee_percent' => 'decimal:2',
        'payment' => 'decimal:2',
    ];

    /**
     * UUID diisi otomatis saat baris dibuat.
     *
     * Dipasang lewat event `creating`, bukan diserahkan ke pemanggil: kalau
     * setiap tempat yang membuat baris harus mengingat mengisinya sendiri,
     * cepat atau lambat ada satu yang lupa dan barisnya gagal disimpan karena
     * kolomnya unik & tidak nullable.
     */
    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /** URL memakai uuid, bukan id yang berurutan dan mudah ditebak. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Nomor transaksi = YYMMDD + urut 3 digit yang dihitung ulang tiap hari.
     *
     * Contoh: 21 Agustus 2026, transaksi pertama hari itu → "260821001".
     *
     * Kandidatnya diperiksa satu per satu sebelum dipakai. Urutan diambil dari
     * nomor terbesar hari itu, tapi angka itu bisa meleset kalau ada nomor lama
     * berformat lain yang ikut tertangkap pola prefix — tanpa pemeriksaan ini,
     * penyimpanan bisa menabrak index unik dan gagal dengan galat SQL mentah.
     */
    /**
     * Banyak digit bagian urut pada nomor transaksi — YYMMDD + urut.
     *
     * Empat, jadi nomornya 10 karakter (mis. `2608260003`). Tidak ada validasi
     * lain yang memaksakan panjang ini: kolomnya `varchar(50)` unik, dan nomor
     * berapa pun panjangnya akan diterima. Angka di sinilah satu-satunya yang
     * menentukan bentuknya.
     *
     * Konstanta, bukan angka yang ditulis ulang di tiap pemanggil: impor massal
     * punya penomorannya sendiri (TransaksiImportController::nomorBerikut), dan
     * dua tempat yang memadatkan dengan lebar berbeda akan menghasilkan dua
     * bentuk nomor untuk hari yang sama.
     *
     * Nomor lama yang terlanjur 3 digit (`260826002`) TIDAK ikut berubah, dan
     * tidak perlu: pembacaan urut memakai `substr($nomor, 6)` yang tidak peduli
     * panjangnya, dan pengurutan di bawah sudah mendahulukan yang lebih panjang.
     */
    public const PANJANG_URUT = 4;

    public static function generateNumber(?string $tanggal = null): string
    {
        $prefix = ($tanggal ? Carbon::parse($tanggal) : now())->format('ymd');

        // Urut per panjang dulu, baru per nilai: kalau satu hari tembus 999
        // transaksi, "2608211000" harus dianggap lebih besar dari "260821999" —
        // perbandingan teks saja akan membalik keduanya.
        $max = static::withTrashed()
            ->where('transaction_number', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(transaction_number) DESC')
            ->orderBy('transaction_number', 'desc')
            ->value('transaction_number');

        $urut = $max ? ((int) substr($max, 6)) + 1 : 1;

        do {
            $kandidat = $prefix.str_pad((string) $urut, self::PANJANG_URUT, '0', STR_PAD_LEFT);
            $urut++;
        } while (static::withTrashed()->where('transaction_number', $kandidat)->exists());

        return $kandidat;
    }

    /**
     * Selisih antara yang seharusnya dibayar dan yang benar-benar diterima.
     *
     * Dihitung, bukan disimpan: kalau ikut disimpan, nilainya bisa melenceng
     * dari kolom-kolom penyusunnya begitu salah satunya diubah.
     *
     * Positif = masih kurang bayar, negatif = lebih bayar.
     */
    public function getBalanceAttribute(): string
    {
        // `group_leader_fee` TIDAK ikut dijumlahkan. Komisi ketua kelompok
        // ditahan dari uang yang ia setorkan, jadi mengurangi setoran — dulu
        // nominal yang sama ikut ditambahkan kembali sehingga keduanya saling
        // menghapus dan komisinya tidak berpengaruh sama sekali.
        //
        // Kolom `group_leader_fee` tetap diisi sebagai catatan HAK ketua
        // (dipakai laporan/pembayaran komisi), bukan sebagai penambah setoran.
        $seharusnya = (float) $this->total
            - (float) $this->member_deduction
            - (float) $this->group_leader_deduction;

        return number_format($seharusnya - (float) $this->payment, 2, '.', '');
    }
}
