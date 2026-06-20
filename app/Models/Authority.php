<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Authority extends Model
{
    use HasAuditColumns;

    protected $fillable = ['name', 'description', 'created_by', 'updated_by'];

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'authority_menu');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
