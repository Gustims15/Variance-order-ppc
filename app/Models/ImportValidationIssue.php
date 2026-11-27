<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportValidationIssue extends Model
{
    protected $table = 'import_validation_issues';

    protected $fillable = [
        'weekly_import_id',
        'imported_order_id',
        'sr_issue_date',
        'source_row',
        'issue_type',
        'expected_value',
        'actual_value',
        'difference',
        'status',
        'resolution_note',
        'reviewed_by',
        'reviewed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'weekly_import_id' => 'integer',
            'imported_order_id' => 'integer',
            'source_row' => 'integer',
            'reviewed_by' => 'integer',
            'sr_issue_date' => 'date',
            'reviewed_at' => 'datetime',
            'expected_value' => 'decimal:4',
            'actual_value' => 'decimal:4',
            'difference' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function weeklyImport(): BelongsTo
    {
        return $this->belongsTo(WeeklyImport::class);
    }

    public function importedOrder(): BelongsTo
    {
        return $this->belongsTo(ImportedOrder::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
