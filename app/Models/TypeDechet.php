<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TypeDechet extends Model
{
    protected $table = 'type_dechets';

    protected $fillable = ['nom', 'categorie', 'description', 'statut'];

    public function collectes()
    {
        return $this->belongsToMany(Collecte::class, 'collecte_dechets')->withPivot(['quantite', 'unite'])->withTimestamps();
    }
}
