<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Garanty extends Model
{
    // Montant: le montant de la garantie du ramasseur
    // Reste: le montant restant de la garantie du ramasseur

    protected $fillable = ['ramasseur_id', 'montant', 'reste', 'currency'];

    public function ramasseur()
    {
        return $this->belongsTo(User::class, 'ramasseur_id');
    }
}
