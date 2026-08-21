<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
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
    use HasAuditColumns;

    protected $table = 'transaction_headers';

    /** Cara bayar yang diterima. */
    public const PAYMENT_METHODS = ['transfer', 'cash'];

    /** Jenis kuitansi yang diterima. */
    public const TRANSACTION_TYPES = ['kelompok', 'pribadi'];

    protected $fillable = [
        'transaction_number',
        'transaction_type',
        'total',
        'member_deduction',
        'group_leader_deduction',
        'group_leader_fee',
        'payment',
        'payment_method',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'member_deduction' => 'decimal:2',
        'group_leader_deduction' => 'decimal:2',
        'group_leader_fee' => 'decimal:2',
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
            $kandidat = $prefix.str_pad((string) $urut, 3, '0', STR_PAD_LEFT);
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
        $seharusnya = (float) $this->total
            - (float) $this->member_deduction
            - (float) $this->group_leader_deduction
            + (float) $this->group_leader_fee;

        return number_format($seharusnya - (float) $this->payment, 2, '.', '');
    }
}
