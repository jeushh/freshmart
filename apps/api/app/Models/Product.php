<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['price' => 'decimal:2', 'cost_price' => 'decimal:2', 'stock_quantity' => 'integer', 'reorder_level' => 'integer'];
}
