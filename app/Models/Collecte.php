<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Collecte extends Model
{
    protected $fillable = ['reference', 'menage_id', 'ramasseur_id', 'type_dechet', 'adresse', 'latitude', 'longitude', 'statut', 'demande_at', 'acceptee_at', 'en_route_at', 'arrivee_at', 'collecte_at', 'terminee_at', 'annulee_at', 'motif_annulation'];

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

    public function taillesPoubelles()
    {
        return $this->belongsToMany(TaillePoubelle::class, 'collecte_taille_poubelle')
            ->withPivot(['qte', 'type_dechet'])
            ->withTimestamps();
    }

    public function affectations()
    {
        return $this->hasMany(CollecteAffectation::class);
    }
}
