<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('email')->nullable()->unique();
            $table->string('username')->nullable()->unique();
            $table->string('phone', 20)->nullable()->unique();
            $table->string('password');
            $table->string('adresse')->nullable();
            $table->boolean('disponible')->default(false);
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('precision_position')->nullable();
            $table->string('flexpaie_code')->nullable()->unique();
            $table->string('avatar')->nullable();
            $table->enum('role', [
                'menage',
                'ramasseur',
                'superviseur',
                'admin',
            ])->default('menage');
            $table->enum('statut', ['actif', 'inactif', 'suspendu'])->default('actif');
            $table->timestamp('phone_verifie_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
