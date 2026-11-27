<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyImport extends Model
{
    protected $table = 'weekly_imports';

    protected $fillable = [
        'original_filename',
        'file_hash',
        'import_type',
        'file_type',
        'status',
        'total_worksheets',
        'total_products',
        'total_periods',
        'uploaded_at',
        'processed_at',
        'snapshot_date',
        'snapshot_week',
        'metadata',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'total_worksheets' => 'integer',
            'total_products' => 'integer',
            'total_periods' => 'integer',
            'uploaded_at' => 'datetime',
            'processed_at' => 'datetime',
            'snapshot_date' => 'date',
            'metadata' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(
            ImportedOrder::class,
            'weekly_import_id',
            'id'
        );
    }

    public function previousReports(): HasMany
    {
        return $this->hasMany(
            VarianceReport::class,
            'previous_import_id',
            'id'
        );
    }

    public function currentReports(): HasMany
    {
        return $this->hasMany(
            VarianceReport::class,
            'current_import_id',
            'id'
        );
    }

    public function validationIssues(): HasMany
    {
        return $this->hasMany(
            ImportValidationIssue::class,
            'weekly_import_id',
            'id'
        );
    }
}
