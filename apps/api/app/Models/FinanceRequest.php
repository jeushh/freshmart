<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FinanceRequest extends Model {
    protected $table = 'finance_requests';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['basic_salary'=>'decimal:2','hourly_rate'=>'decimal:2','price'=>'decimal:2','cost'=>'decimal:2','amount'=>'decimal:2','net_pay'=>'decimal:2'];
}
