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
            $table->decimal('montant', 10, 2)->default(0);
            $table->string('mode_paiement')->nullable();
            $table->string('adresse');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->enum('statut', ['en_attente', 'acceptee', 'annulee', 'cloturee', 'terminee'])
            ->default('en_attente')->index();
            $table->boolean('dechets_tries')->default(false);
            $table->boolean('pluie')->default(false);
            $table->string('temps_attente', 50)->nullable();
            $table->string('instructions')->nullable();
            $table->timestamp('planning_at')->nullable();
            $table->timestamp('demande_at')->nullable();
            $table->timestamp('acceptee_at')->nullable();
            $table->timestamp('cloturee_at')->nullable();
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
