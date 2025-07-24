<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JurnalKeuanganModel extends Model
{
    protected $table = 'keu_journal';
    protected $primaryKey = 'no';
    public $timestamps = false;
    
    protected $fillable = [
        'username', 'subdomain', 'code', 'user', 'status',
        'account', 'name', 'description', 'value',
        'date_transaction', 'date', 'publish'
    ];
    
    protected $casts = [
        'date_transaction' => 'date',
        'date' => 'datetime',
    ];
}
