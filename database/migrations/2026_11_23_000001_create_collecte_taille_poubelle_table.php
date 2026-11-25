
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collecte_taille_poubelle', function (Blueprint $table) {
            $table->id();

            $table->foreignId('collecte_id')
                ->constrained('collectes')
                ->cascadeOnDelete();

            $table->foreignId('taille_poubelle_id')
                ->constrained('tailles_poubelles')
                ->restrictOnDelete();

            $table->enum('type_dechet', [
                'compostable',
                'recyclable',
                'residuel'
            ])->nullable();

            $table->unsignedInteger('qte');

            $table->timestamps();

            $table->unique(['collecte_id', 'taille_poubelle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collecte_taille_poubelle');
    }
};
