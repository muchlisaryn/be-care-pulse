<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class Instrument extends Model
{
    use HasAuditColumns;

    protected $fillable = ['code', 'name', 'image', 'created_by', 'updated_by'];

    protected $appends = ['image_url'];

    /**
     * Path publik gambar instrumen (null bila belum ada).
     *
     * Sengaja ROOT-RELATIF (`/uploads/...`), bukan URL absolut lewat url(): browser
     * mengakses aplikasi lewat Next (port 3000) yang mem-proxy /uploads ke backend.
     * URL absolut akan ikut APP_URL dan menunjuk host yang salah — juga rusak bila
     * dibuka dari perangkat lain di LAN.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? '/'.ltrim($this->image, '/') : null;
    }

    public function stocks()
    {
        return $this->hasMany(InstrumentStock::class);
    }
}
