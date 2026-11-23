<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPeriod extends Model
{
    protected $table = 'order_periods';

    protected $fillable = [
        'imported_order_id',
        'sr_issue_date',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'imported_order_id' => 'integer',
            'sr_issue_date' => 'date',
            'quantity' => 'decimal:4',
        ];
    }

    public function importedOrder(): BelongsTo
    {
        return $this->belongsTo(
            ImportedOrder::class,
            'imported_order_id',
            'id'
        );
    }
}