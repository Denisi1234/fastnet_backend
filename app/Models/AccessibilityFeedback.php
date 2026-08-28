<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessibilityFeedback extends Model
{
    protected $table = 'accessibility_feedbacks';

    protected $fillable = [
        'comment',
        'page_url',
        'user_agent',
        'ip_address',
    ];
}
