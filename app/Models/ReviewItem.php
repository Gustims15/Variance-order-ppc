<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewItem extends Model
{
    protected $table = 'review_items';

    protected $fillable = [
        'variance_report_id',
        'variance_detail_id',
        'reason',
        'status',
        'resolution_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'variance_report_id' => 'integer',
            'variance_detail_id' => 'integer',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function varianceReport(): BelongsTo
    {
        return $this->belongsTo(VarianceReport::class);
    }

    public function varianceDetail(): BelongsTo
    {
        return $this->belongsTo(VarianceDetail::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }

    public function histories(): HasMany
    {
        return $this->hasMany(
            ReviewItemHistory::class,
            'review_item_id'
        )->orderBy('performed_at', 'desc');
    }
}