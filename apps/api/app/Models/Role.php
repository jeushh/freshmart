<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Role extends Model {
    protected $table = 'roles';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = ['permissions'=>'array','is_system'=>'boolean'];
}
