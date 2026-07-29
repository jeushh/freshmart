<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'employees';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['basic_salary' => 'decimal:2', 'hourly_rate' => 'decimal:2', 'leave_balance' => 'decimal:2'];
}
