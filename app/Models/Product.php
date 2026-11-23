<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'product_code',
        'product_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
