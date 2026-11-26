<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['garanty', 'collecte', 'commission', 'other'])->nullable()->change();
            $table->decimal('montant', 10, 2)->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereRaw('montant <> ROUND(montant, 0)')->exists()) {
            throw new RuntimeException('Les montants avec des centimes empêchent le retour au type entier.');
        }

        DB::table('payments')->where('type', 'commission')->update(['type' => 'other']);

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('type', ['garanty', 'collecte', 'other'])->nullable()->change();
            $table->integer('montant')->change();
        });
    }
};
