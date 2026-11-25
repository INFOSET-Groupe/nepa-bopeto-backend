<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'nom',
        'email',
        'phone',
        'password',
        'avatar',
        'role',
        'adresse',
        'disponible',
        'flexpaie_code',
        'latitude',
        'longitude',
        'statut',
        'precision_position',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'phone_verifie_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isMenage(): bool
    {
        return $this->role === 'menage';
    }


    public function isRamasseur(): bool
    {
        return $this->role === 'ramasseur';
    }

    public function isSuperviseur(): bool
    {
        return $this->role === 'superviseur';
    }

    public function isActif(): bool
    {
        return $this->statut === 'actif';
    }

    public function collectes()
    {
        return $this->hasMany(Collecte::class, 'menage_id');
    }

    public function garanty()
    {
        return $this->hasOne(Garanty::class, 'ramasseur_id');
    }

    public function affectations()
    {
        return $this->hasMany(CollecteAffectation::class, 'ramasseur_id');
    }

    public function notifications()
    {
        return $this->hasMany(UserNotification::class);
    }
}
