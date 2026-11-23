<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaillePoubelle extends Model
{
    protected $table = 'tailles_poubelles';

    protected $fillable = ['nom', 'description', 'capacite', 'unite', 'statut'];

    public function collectes()
    {
        return $this->belongsToMany(Collecte::class, 'collecte_taille_poubelle')
            ->withPivot(['qte', 'type_dechet'])
            ->withTimestamps();
    }
}
