<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $table = 'attendance_logs';

    protected $guarded = [];

    public $timestamps = false;
}
