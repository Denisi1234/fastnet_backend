<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'address',
        'city',
        'area',
        'price_per_night',
        'host_id',
        'image_url',
    ];

    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
