<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collectes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('menage_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ramasseur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('taille_poubelle_id')->constrained('tailles_poubelles')->restrictOnDelete();
            $table->string('adresse');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->enum('statut', [
                'en_attente', 'disponible', 'acceptee', 'en_route', 'arrive',
                'en_collecte', 'terminee', 'annulee', 'expiree',
            ])->default('en_attente')->index();
            $table->boolean('dechets_tries')->default(false);
            $table->string('mode_paiement')->nullable();
            $table->string('instructions')->nullable();
            $table->timestamp('planning_at')->nullable();
            $table->timestamp('demande_at')->nullable();
            $table->timestamp('acceptee_at')->nullable();
            $table->timestamp('en_route_at')->nullable();
            $table->timestamp('arrivee_at')->nullable();
            $table->timestamp('collecte_at')->nullable();
            $table->timestamp('terminee_at')->nullable();
            $table->timestamp('annulee_at')->nullable();
            $table->text('motif_annulation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collectes');
    }
};
