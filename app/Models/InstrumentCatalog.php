<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class InstrumentCatalog extends Model
{
    use HasAuditColumns;

    protected $fillable = [
        'code',
        'name',
        'image',
        'type',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $appends = ['image_url'];

    /** URL publik gambar set/paket (null bila belum ada). */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? url($this->image) : null;
    }

    public function items()
    {
        return $this->hasMany(InstrumentCatalogItem::class);
    }
}
