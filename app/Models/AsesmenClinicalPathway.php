<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsesmenClinicalPathway extends Model
{
    use HasAuditColumns;

    protected $table = 'clinical_pathway_assessments';

    /** Pilihan jenis kelamin (kolom gender). */
    public const GENDER = ['L', 'P'];

    protected $fillable = [
        'template_id', 'medical_record_no', 'patient_name', 'gender', 'birth_date',
        'admission_diagnosis', 'primary_disease', 'comorbidity', 'complication',
        'procedure', 'weight', 'height', 'admitted_at', 'discharged_at',
        'length_of_stay', 'care_plan', 'room_id', 'ward_class', 'is_referral',
        'doctor_verified_by', 'doctor_verified_at',
        'nurse_verified_by', 'nurse_verified_at',
        'executor_verified_by', 'executor_verified_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'admitted_at' => 'datetime',
        'discharged_at' => 'datetime',
        'weight' => 'decimal:2',
        'height' => 'decimal:2',
        'length_of_stay' => 'integer',
        'is_referral' => 'boolean',
        'doctor_verified_at' => 'datetime',
        'nurse_verified_at' => 'datetime',
        'executor_verified_at' => 'datetime',
    ];

    /** Formulir/template yang dipakai asesmen ini. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateClinicalPathway::class, 'template_id');
    }

    /** Ruang rawat (master ruangan). */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    /** Nilai ceklis per poin. */
    public function points(): HasMany
    {
        return $this->hasMany(AsesmenPointClinicalPathway::class, 'assessment_id');
    }

    /** Catatan varian (penyimpangan) clinical pathway. */
    public function variances(): HasMany
    {
        return $this->hasMany(VarianClinicalPathway::class, 'assessment_id');
    }
}
