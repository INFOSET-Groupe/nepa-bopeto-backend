<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collecte_taille_poubelle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collecte_id')->constrained('collectes')->cascadeOnDelete();
            $table->foreignId('taille_poubelle_id')->constrained('tailles_poubelles')->restrictOnDelete();
            $table->enum('type_dechet', ['compostable', 'recyclable', 'residuel'])->nullable();
            $table->unsignedInteger('qte');
            $table->timestamps();
            $table->unique(['collecte_id', 'taille_poubelle_id']);
        });

        $now = now();
        $taillesExistantes = DB::table('collectes')
            ->whereNotNull('taille_poubelle_id')
            ->get(['id', 'taille_poubelle_id'])
            ->map(fn ($collecte) => [
                'collecte_id' => $collecte->id,
                'taille_poubelle_id' => $collecte->taille_poubelle_id,
                'qte' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        if ($taillesExistantes !== []) {
            DB::table('collecte_taille_poubelle')->insert($taillesExistantes);
        }

        Schema::table('collectes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taille_poubelle_id');
        });
    }

    public function down(): void
    {
        Schema::table('collectes', function (Blueprint $table) {
            $table->foreignId('taille_poubelle_id')->nullable()->constrained('tailles_poubelles')->restrictOnDelete();
        });

        DB::table('collecte_taille_poubelle')
            ->orderBy('collecte_id')
            ->get(['collecte_id', 'taille_poubelle_id'])
            ->each(function ($taille) {
                DB::table('collectes')
                    ->where('id', $taille->collecte_id)
                    ->update(['taille_poubelle_id' => $taille->taille_poubelle_id]);
            });

        Schema::table('collectes', function (Blueprint $table) {
            $table->unsignedBigInteger('taille_poubelle_id')->nullable(false)->change();
        });

        Schema::dropIfExists('collecte_taille_poubelle');
    }
};
