<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewItemHistory extends Model
{
    protected $table = 'review_item_histories';

    protected $fillable = [
        'review_item_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'performed_by',
        'performed_at',
    ];

    protected function casts(): array
    {
        return [
            'review_item_id' => 'integer',
            'performed_by' => 'integer',
            'performed_at' => 'datetime',
        ];
    }

    public function reviewItem(): BelongsTo
    {
        return $this->belongsTo(
            ReviewItem::class,
            'review_item_id'
        );
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'performed_by'
        );
    }
}
