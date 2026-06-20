<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasAuditColumns;

    protected $fillable = ['title_menu_id', 'parent_id', 'name', 'url', 'icon', 'sort_order', 'is_open', 'created_by', 'updated_by'];

    protected $casts = [
        'is_open' => 'boolean',
    ];

    public function titleMenu(): BelongsTo
    {
        return $this->belongsTo(TitleMenus::class, 'title_menu_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Menu::class, 'parent_id')->orderBy('sort_order');
    }

    public function authorities(): BelongsToMany
    {
        return $this->belongsToMany(Authority::class, 'authority_menu');
    }
}
