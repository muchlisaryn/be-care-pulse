<?php

namespace App\Traits;

/**
 * Kolom `disabled` yang MENGIKUTI keadaan hapus: `true` begitu barisnya dihapus,
 * `false` selama belum. Pendamping `HasAuditColumns`, bukan penggantinya.
 *
 * Nilainya DITURUNKAN, tidak pernah diisi tangan. Itu bukan pilihan gaya:
 * `deleted_by` sudah jadi penentu tunggal apakah sebuah baris terhapus (global
 * scope `active` membacanya), jadi kolom kedua yang menjawab pertanyaan sama
 * hanya berguna kalau mustahil berselisih dengannya. Karena itu:
 *
 *  - diisi lewat event `saving`, sehingga JALUR APA PUN yang menyimpan model —
 *    `delete()`, `restore()`, atau `update()` biasa — meninggalkannya konsisten;
 *  - TIDAK dimasukkan `$fillable`, supaya tidak bisa ditumpangi mass assignment
 *    dari request.
 *
 * Batasnya: pembaruan massal lewat query builder (`->update([...])`) tidak
 * memicu event model, jadi jangan menghapus baris dengan cara itu. Di proyek ini
 * penghapusan memang selalu lewat instance model — `HasAuditColumns::delete()`
 * pun begitu, karena hanya di sanalah `deleted_by` terisi.
 */
trait MarksDisabledWhenDeleted
{
    protected static function bootMarksDisabledWhenDeleted(): void
    {
        static::saving(function (self $model) {
            $model->disabled = $model->deleted_by !== null;
        });
    }

    protected function initializeMarksDisabledWhenDeleted(): void
    {
        $this->casts['disabled'] = 'boolean';
    }
}
