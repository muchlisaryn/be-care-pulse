<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class Instrument extends Model
{
    use HasAuditColumns;

    protected $fillable = ['code', 'name', 'image', 'created_by', 'updated_by'];

    protected $appends = ['image_url'];

    /** URL publik gambar instrumen (null bila belum ada). */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? url($this->image) : null;
    }

    public function stocks()
    {
        return $this->hasMany(InstrumentStock::class);
    }
}
