<?php

use App\Models\Collecte;
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
    expect(User::where('phone', '243811111111')->first()->role)->toBe('menage');
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
    expect(User::where('phone', '243822222222')->first()->role)->toBe('ramasseur');
});

test('un ramasseur peut mettre à jour sa position sur son compte utilisateur', function () {
    $ramasseur = User::factory()->create(['role' => 'ramasseur']);

    $this->actingAs($ramasseur, 'sanctum')
        ->postJson('/api/ramasseur/position', [
            'latitude' => -4.325,
            'longitude' => 15.312,
        ])
        ->assertOk();

    expect((float) $ramasseur->fresh()->latitude)->toBe(-4.325)
        ->and((float) $ramasseur->fresh()->longitude)->toBe(15.312);
});

test('la première acceptation invalide les autres affectations', function () {
    $menageUser = User::factory()->create(['role' => 'menage']);
    $ramasseurUser = User::factory()->create(['role' => 'ramasseur']);
    $ramasseurUser->update(['disponible' => true, 'latitude' => 0, 'longitude' => 0]);
    $secondRamasseur = User::factory()->create([
        'role' => 'ramasseur',
        'disponible' => true,
        'latitude' => 0,
        'longitude' => 0,
    ]);
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
    expect($collecte->menage_id)->toBe($menageUser->id)
        ->and($collecte->affectations()->pluck('ramasseur_id')->all())->toEqualCanonicalizing([$ramasseurUser->id, $secondRamasseur->id]);

    $this->actingAs($ramasseurUser, 'sanctum')
        ->getJson('/api/ramasseur/collectes/disponibles')
        ->assertOk()
        ->assertJsonFragment(['id' => $collecte->id]);

    $this->actingAs($menageUser, 'sanctum')
        ->getJson('/api/menage/collectes')
        ->assertOk()
        ->assertJsonFragment(['menage_id' => $menageUser->id]);

    $this->actingAs($ramasseurUser, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertOk()
        ->assertJsonFragment(['id' => $ramasseurUser->id, 'role' => 'ramasseur'])
        ->assertJsonFragment(['id' => $menageUser->id, 'role' => 'menage']);

    expect($collecte->fresh()->statut)->toBe('acceptee')
        ->and($collecte->fresh()->ramasseur_id)->toBe($ramasseurUser->id)
        ->and($collecte->affectations()->where('ramasseur_id', $secondRamasseur->id)->value('statut'))->toBe('expiree');
});
