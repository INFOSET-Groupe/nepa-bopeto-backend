<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collecte extends Model
{
    protected $fillable = ['reference', 'menage_id', 'ramasseur_id', 'taille_poubelle_id', 'adresse', 'latitude', 'longitude', 'statut', 'demande_at', 'acceptee_at', 'en_route_at', 'arrivee_at', 'collecte_at', 'terminee_at', 'annulee_at', 'motif_annulation'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'demande_at' => 'datetime', 'acceptee_at' => 'datetime', 'en_route_at' => 'datetime', 'arrivee_at' => 'datetime', 'collecte_at' => 'datetime', 'terminee_at' => 'datetime', 'annulee_at' => 'datetime'];
    }

    public function ramasseur()
    {
        return $this->belongsTo(User::class, 'ramasseur_id');
    }

    public function menage()
    {
        return $this->belongsTo(User::class, 'menage_id');
    }

    public function taillePoubelle()
    {
        return $this->belongsTo(TaillePoubelle::class);
    }

    public function typesDechets()
    {
        return $this->belongsToMany(TypeDechet::class, 'collecte_dechets')->withPivot(['quantite', 'unite'])->withTimestamps();
    }

    public function affectations()
    {
        return $this->hasMany(CollecteAffectation::class);
    }
}
