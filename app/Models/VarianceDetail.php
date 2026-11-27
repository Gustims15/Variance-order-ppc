<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VarianceDetail extends Model
{
    protected $table = 'variance_details';

    protected $fillable = [
        'variance_report_id',
        'department',
        'sr_number',
        'product_code',
        'previous_line_number',
        'current_line_number',
        'product_name',
        'previous_source_row',
        'current_source_row',
        'previous_total',
        'current_total',
        'total_variance',
        'status',
        'reason',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'variance_report_id' => 'integer',
            'previous_source_row' => 'integer',
            'current_source_row' => 'integer',
            'previous_total' => 'decimal:4',
            'current_total' => 'decimal:4',
            'total_variance' => 'decimal:4',
            'audit_metadata' => 'array',
        ];
    }

    public function varianceReport(): BelongsTo
    {
        return $this->belongsTo(
            VarianceReport::class,
            'variance_report_id',
            'id'
        );
    }

    public function periods(): HasMany
    {
        return $this->hasMany(
            VariancePeriodDetail::class,
            'variance_detail_id',
            'id'
        );
    }

    public function reviewItems(): HasMany
    {
        return $this->hasMany(
            ReviewItem::class,
            'variance_detail_id',
            'id'
        );
    }
}