<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collecte_affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collecte_id')->constrained('collectes')->cascadeOnDelete();
            $table->foreignId('ramasseur_id')->constrained('users')->cascadeOnDelete();
            $table->enum('statut', ['en_attente', 'acceptee', 'annulee', 'cloturee', 'terminee'])->default('en_attente');
            $table->timestamp('notifie_at')->nullable();
            $table->timestamp('repondu_at')->nullable();
            $table->timestamps();
            $table->unique(['collecte_id', 'ramasseur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collecte_affectations');
    }
};
