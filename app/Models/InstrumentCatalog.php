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

    /** Path publik gambar set/paket, root-relatif — lihat Instrument::getImageUrlAttribute(). */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? '/'.ltrim($this->image, '/') : null;
    }

    public function items()
    {
        return $this->hasMany(InstrumentCatalogItem::class);
    }
}
