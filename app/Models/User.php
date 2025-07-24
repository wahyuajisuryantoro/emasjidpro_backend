<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $table = 'user';
    protected $primaryKey = 'no';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'password',
        'category',
        'replika',
        'referral',
        'name',
        'subdomain',
        'link',
        'number_id',
        'birth',
        'sex',
        'address',
        'city',
        'phone',
        'email',
        'bank_name',
        'bank_branch',
        'bank_account_number',
        'bank_account_name',
        'last_login',
        'last_ipaddress',
        'picture',
        'date',
        'publish'
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

    protected $casts = [
        'last_login' => 'datetime',
        'date' => 'datetime',
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
            // 'password' => 'hashed',
        ];
    }

    public function getAuthIdentifierName()
    {
        return 'username';
    }
}
