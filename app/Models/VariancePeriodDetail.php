<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariancePeriodDetail extends Model
{
    protected $table = 'variance_period_details';

    protected $fillable = [
        'variance_detail_id',
        'sr_issue_date',
        'forecast_quantity',
        'latest_quantity',
        'variance',
        'previous_cumulative_quantity',
        'current_cumulative_quantity',
        'cumulative_variance',
        'cum_status',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'variance_detail_id' => 'integer',
            'sr_issue_date' => 'date',
            'forecast_quantity' => 'decimal:4',
            'latest_quantity' => 'decimal:4',
            'variance' => 'decimal:4',
            'previous_cumulative_quantity' => 'decimal:4',
            'current_cumulative_quantity' => 'decimal:4',
            'cumulative_variance' => 'decimal:4',
        ];
    }

    public function varianceDetail(): BelongsTo
    {
        return $this->belongsTo(
            VarianceDetail::class,
            'variance_detail_id',
            'id'
        );
    }
}