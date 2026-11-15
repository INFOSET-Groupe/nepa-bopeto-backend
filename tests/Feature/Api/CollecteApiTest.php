<?php

use App\Models\Collecte;
use App\Models\Menage;
use App\Models\Ramasseur;
use App\Models\TaillePoubelle;
use App\Models\TypeDechet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('un ménage peut créer son compte', function () {
    $response = $this->postJson('/api/auth/register/user', [
        'role' => 'menage',
        'username' => 'jeandupont',
        'nom' => 'Dupont',
        'phone' => '243811111111',
        'email' => 'jean@example.com',
        'password' => 'password123',
        'adresse' => 'Kinshasa',
        'latitude' => -4.325,
        'longitude' => 15.312,
    ]);

    $response->assertCreated()->assertJsonStructure(['user', 'token']);
    expect(User::where('phone', '243811111111')->first()->menage)->not->toBeNull();
});

test('un ramasseur peut créer son compte', function () {
    $response = $this->postJson('/api/auth/register/user', [
        'role' => 'ramasseur',
        'username' => 'marie',
        'nom' => 'Marie',
        'phone' => '243822222222',
        'password' => 'password123',
    ]);

    $response->assertCreated()->assertJsonStructure(['user', 'token']);
    expect(User::where('phone', '243822222222')->first()->ramasseur)->not->toBeNull();
});

test('la première acceptation invalide les autres affectations', function () {
    $menageUser = User::factory()->create(['role' => 'menage']);
    $menage = Menage::create(['user_id' => $menageUser->id, 'adresse' => 'Kinshasa', 'latitude' => 0, 'longitude' => 0]);
    $ramasseurUser = User::factory()->create(['role' => 'ramasseur']);
    $ramasseur = Ramasseur::create(['user_id' => $ramasseurUser->id, 'disponible' => true, 'latitude' => 0, 'longitude' => 0]);
    TaillePoubelle::create(['nom' => 'Moyenne', 'capacite' => 60, 'unite' => 'L']);
    TypeDechet::create(['nom' => 'Plastique', 'categorie' => 'recyclable']);

    $this->actingAs($menageUser, 'sanctum')->postJson('/api/collectes', [
        'taille_poubelle_id' => 1,
        'adresse' => 'Kinshasa',
        'latitude' => 0,
        'longitude' => 0,
        'dechets' => [['type_dechet_id' => 1, 'quantite' => 2, 'unite' => 'sac']],
    ])->assertCreated();

    $collecte = Collecte::first();
    $this->actingAs($ramasseurUser, 'sanctum')->postJson("/api/collectes/{$collecte->id}/accepter")->assertOk();

    expect($collecte->fresh()->statut)->toBe('acceptee')
        ->and($collecte->fresh()->ramasseur_id)->toBe($ramasseur->id);
});
