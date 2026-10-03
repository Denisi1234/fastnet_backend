<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginOtpCode extends Model
{
    protected $table = 'login_otp_codes';

    protected $fillable = [
        'user_id',
        'contact',
        'channel',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at'  => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * Hash a plain 6-digit code for storage/comparison.
     */
    public static function hashCode(string $code): string
    {
        return hash('sha256', $code);
    }

    /**
     * Constant-time comparison of a candidate code against the stored hash.
     */
    public function codeMatches(string $candidate): bool
    {
        return hash_equals($this->token_hash, self::hashCode($candidate));
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture()
            && $this->attempts < 5;
    }
}