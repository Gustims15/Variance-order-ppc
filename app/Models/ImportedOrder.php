<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportedOrder extends Model
{
    protected $table = 'imported_orders';

    protected $fillable = [
        'weekly_import_id',
        'department',
        'worksheet_name',
        'sr_number',
        'product_code',
        'line_number',
        'car_model',
        'family',
        'jig_type',
        'source_row',
        'identity_hash',
    ];

    protected function casts(): array
    {
        return [
            'weekly_import_id' => 'integer',
            'source_row' => 'integer',
        ];
    }

    public function weeklyImport(): BelongsTo
    {
        return $this->belongsTo(
            WeeklyImport::class,
            'weekly_import_id',
            'id'
        );
    }

    public function periods(): HasMany
    {
        return $this->hasMany(
            OrderPeriod::class,
            'imported_order_id',
            'id'
        );
    }
}