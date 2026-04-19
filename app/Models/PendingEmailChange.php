<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingEmailChange extends Model
{
    protected $table = 'pending_email_changes';

    protected $fillable = [
        'user_id',
        'new_email',
        'old_otp',
        'new_otp',
        'expires_at',
    ];

    // Relasi ke User
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}