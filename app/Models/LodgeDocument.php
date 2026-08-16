<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LodgeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'document_type',
        'document_number',
        'file_url',
        'status',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
