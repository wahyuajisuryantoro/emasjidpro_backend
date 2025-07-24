<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AkunKeuanganModel extends Model
{
    protected $table = 'keu_account';
    protected $primaryKey = 'no';
    public $timestamps = false;
    
    protected $fillable = [
        'username', 'subdomain', 'code', 'code_account_category',
        'name', 'type', 'balance', 'related', 'description',
        'date', 'publish'
    ];
}
