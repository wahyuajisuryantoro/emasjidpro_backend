<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HutangModel extends Model
{
    protected $table = 'keu_debt';
    protected $primaryKey = 'no';
    public $timestamps = false;
    
    protected $fillable = [
        'username', 'subdomain', 'code', 'user', 'status',
        'account_category', 'account', 'account_related',
        'name', 'link', 'description', 'value',
        'date_deadline', 'date_transaction', 'date', 'publish'
    ];
}
