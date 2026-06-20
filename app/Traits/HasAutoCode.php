<?php

namespace App\Traits;

trait HasAutoCode
{
    protected static function bootHasAutoCode(): void
    {
        static::creating(function ($model) {
            if (empty($model->code)) {
                $model->code = static::generateUniqueCode($model);
            }
        });
    }

    protected static function generateUniqueCode($model): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= chr(random_int(65, 90)); // A–Z
            }
        } while (static::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }
}
