<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collecte_dechets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collecte_id')->constrained('collectes')->cascadeOnDelete();
            $table->foreignId('type_dechet_id')->constrained('type_dechets')->restrictOnDelete();
            $table->decimal('quantite', 10, 2);
            $table->string('unite', 20);
            $table->timestamps();
            $table->unique(['collecte_id', 'type_dechet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collecte_dechets');
    }
};
