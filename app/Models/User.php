<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens; // <--- 1. Pastikan ini di-import

class User extends Authenticatable
{
    use HasApiTokens, Notifiable; // <--- 2. Pastikan ini dipakai di sini

    protected $fillable = [
    'name',
    'email',
    'password',
    'photo_profile', // Tambahin ini
    'role',
];
    
    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
            return [
                'email_verified_at' => 'datetime',
                'password' => 'hashed',
                'deactivated_until' => 'datetime', // Pastikan ini di-cast ke datetime
                
            ];
    }
}
