<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $statuses = ['en_attente', 'acceptee', 'annulee', 'cloturee', 'terminee'];

    private array $legacyStatuses = [
        'en_attente', 'disponible', 'acceptee', 'en_route', 'arrive',
        'en_collecte', 'terminee', 'annulee', 'expiree', 'cloturee',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('collectes', 'cloturee_at')) {
            Schema::table('collectes', function (Blueprint $table) {
                $table->timestamp('cloturee_at')->nullable();
            });
        }

        DB::table('collectes')->whereIn('statut', ['disponible', 'expiree'])
            ->update(['statut' => 'en_attente']);
        DB::table('collectes')->whereIn('statut', ['en_route', 'arrive', 'en_collecte'])
            ->update(['statut' => 'acceptee']);

        Schema::table('collectes', function (Blueprint $table) {
            $table->enum('statut', $this->statuses)->default('en_attente')->change();
        });
    }

    public function down(): void
    {
        Schema::table('collectes', function (Blueprint $table) {
            $table->enum('statut', $this->legacyStatuses)->default('en_attente')->change();
        });
    }
};
