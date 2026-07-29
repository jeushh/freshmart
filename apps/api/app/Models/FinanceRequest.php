<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceRequest extends Model
{
    protected $table = 'finance_requests';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['amount' => 'decimal:2'];
}
