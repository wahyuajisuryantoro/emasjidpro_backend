<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DebtInstallmentModel extends Model
{
    protected $table = 'keu_debt_installment';
    protected $primaryKey = 'no';
    public $timestamps = false;
    
    protected $fillable = [
        'username', 'subdomain', 'code', 'code_debt', 'user',
        'account_category', 'account', 'account_related',
        'name', 'link', 'description', 'value',
        'date_transaction', 'date', 'publish'
    ];
}
