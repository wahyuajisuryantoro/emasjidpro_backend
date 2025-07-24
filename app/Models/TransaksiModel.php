<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransaksiModel extends Model
{

    protected $table = 'keu_transaction';
    protected $primaryKey = 'no';
    protected $keyType = 'integer';
    public $incrementing = true;

    protected $fillable = [
        'username',
        'subdomain',
        'code',
        'user',
        'status',
        'account_category',
        'account',
        'account_related',
        'name',
        'description',
        'value',
        'picture',
        'date_transaction',
        'date',
        'publish'
    ];


    protected $casts = [
        'date_transaction' => 'datetime',
        'date' => 'datetime',
        'value' => 'integer',
        'publish' => 'boolean'
    ];


    protected $dates = [
        'date_transaction',
        'date',
        'deleted_at'
    ];


    public function scopeDebit($query)
    {
        return $query->where('status', 'debit');
    }

    public function scopeCredit($query)
    {
        return $query->where('status', 'credit');
    }

    public function scopePublished($query)
    {
        return $query->where('publish', '1');
    }

    public function getFormattedValueAttribute()
    {
        return 'Rp ' . number_format($this->value, 0, ',', '.');
    }

    public function getFormattedDateTransactionAttribute()
    {
        return $this->date_transaction->format('d M Y H:i');
    }
}
