<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VarianceReport extends Model
{
    protected $table = 'variance_reports';

    protected $fillable = [
        'previous_import_id',
        'current_import_id',
        'status',
        'total_products',
        'new_order_count',
        'increased_count',
        'decreased_count',
        'mixed_count',
        'unchanged_count',
        'needs_review_count',
        'total_changes',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_import_id' => 'integer',
            'current_import_id' => 'integer',
            'total_products' => 'integer',
            'new_order_count' => 'integer',
            'increased_count' => 'integer',
            'decreased_count' => 'integer',
            'mixed_count' => 'integer',
            'unchanged_count' => 'integer',
            'needs_review_count' => 'integer',
            'total_changes' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function previousImport(): BelongsTo
    {
        return $this->belongsTo(
            WeeklyImport::class,
            'previous_import_id',
            'id'
        );
    }

    public function currentImport(): BelongsTo
    {
        return $this->belongsTo(
            WeeklyImport::class,
            'current_import_id',
            'id'
        );
    }

    public function details(): HasMany
    {
        return $this->hasMany(
            VarianceDetail::class,
            'variance_report_id',
            'id'
        );
    }

    public function reviewItems(): HasMany
    {
        return $this->hasMany(
            ReviewItem::class,
            'variance_report_id',
            'id'
        );
    }
}