<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OwnerVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'phone_number',
        'id_number',
        'id_document_url',
        'business_registration_number',
        'business_document_url',
        'payout_bank_name',
        'payout_account_number',
        'payout_account_name',
        'status',
        'admin_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
