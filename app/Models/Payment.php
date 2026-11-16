<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'garanty_id',
        'collecte_id',
        'type',
        'phone',
        'montant',
        'currency',
        'status',
        'phone',
        'reference',
        'order_number',
        'description',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function garanty()
    {
        return $this->belongsTo(Garanty::class);
    }
}
