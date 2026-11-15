<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CollecteAffectation extends Model
{
    protected $fillable = ['collecte_id', 'user_id', 'statut', 'notifie_at', 'repondu_at'];

    protected function casts(): array
    {
        return ['notifie_at' => 'datetime', 'repondu_at' => 'datetime'];
    }

    public function collecte()
    {
        return $this->belongsTo(Collecte::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
