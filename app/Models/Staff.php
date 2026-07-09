<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Staff extends Model
{
    use HasFactory;

    protected $table = 'staff';

    protected $fillable = [
        'name',
        'role',
        'phone',
        'status',
        'room',
        'host_id',
    ];

    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }
}
