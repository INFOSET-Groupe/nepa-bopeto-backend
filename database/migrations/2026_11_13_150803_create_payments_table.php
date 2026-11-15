
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->integer('montant');
            $table->string('currency')->default('CDF');

            $table->enum('type', [
                'garanty',
                'collecte',
                'other'
            ])->nullable();

            $table->enum('status', [
                'pending',
                'success',
                'failed'
            ])->nullable();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('garanty_id')
                ->nullable()
                ->constrained('garanties')
                ->nullOnDelete();

            $table->foreignId('collecte_id')
                ->nullable()
                ->constrained('collectes')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
