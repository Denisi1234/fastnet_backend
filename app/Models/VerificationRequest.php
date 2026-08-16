<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'submitted_by',
        'status',
        'reason',
        'admin_notes',
        'reviewer_id',
        'reviewed_at',
    ];

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
