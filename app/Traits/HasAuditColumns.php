<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasAuditColumns
{
    protected static function bootHasAuditColumns(): void
    {
        static::addGlobalScope('active', function (Builder $query) {
            $query->whereNull('deleted_by');
        });

        static::creating(function (self $model) {
            if ($user = auth()->user()) {
                $model->created_by ??= $user->name;
                $model->updated_by ??= $user->name;
            }
        });

        static::updating(function (self $model) {
            if ($user = auth()->user()) {
                $model->updated_by = $user->name;
            }
        });
    }

    protected function initializeHasAuditColumns(): void
    {
        $this->casts['deleted_at'] = 'datetime';
    }

    /**
     * Soft delete: set deleted_at + deleted_by, never hard-deletes.
     */
    public function delete(): ?bool
    {
        $this->deleted_at = now();
        $this->deleted_by = auth()->user()?->name ?? 'system';

        return $this->save();
    }

    /**
     * Permanently remove the record from the database.
     */
    public function forceDelete(): ?bool
    {
        return parent::delete();
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(): bool
    {
        $this->deleted_at = null;
        $this->deleted_by = null;

        return $this->save();
    }

    public function scopeWithTrashed(Builder $query): Builder
    {
        return $query->withoutGlobalScope('active');
    }

    public function scopeOnlyTrashed(Builder $query): Builder
    {
        return $query->withoutGlobalScope('active')->whereNotNull('deleted_by');
    }
}
