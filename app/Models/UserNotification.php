<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['user_id', 'type', 'titre', 'message', 'donnees', 'lu_at'];

    protected function casts(): array
    {
        return ['donnees' => 'array', 'lu_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
