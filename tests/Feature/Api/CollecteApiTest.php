<?php

use App\Models\Collecte;
use App\Models\Garanty;
use App\Models\TaillePoubelle;
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

test('une collecte n’enregistre que le ramasseur qui accepte', function () {
    $menageUser = User::factory()->create(['role' => 'menage']);
    $ramasseurUser = User::factory()->create(['role' => 'ramasseur']);
    $ramasseurUser->update(['disponible' => true, 'latitude' => 0, 'longitude' => 0]);
    $secondRamasseur = User::factory()->create([
        'role' => 'ramasseur',
        'disponible' => true,
        'latitude' => 0,
        'longitude' => 0,
    ]);
    $tailleMoyenne = TaillePoubelle::create(['nom' => 'Moyenne', 'capacite' => 60, 'unite' => 'L']);
    $tailleGrande = TaillePoubelle::create(['nom' => 'Grande', 'capacite' => 120, 'unite' => 'L']);
    $this->actingAs($menageUser, 'sanctum')->postJson('/api/collectes', [
        'tailles_poubelles' => [
            ['taille_poubelle_id' => $tailleMoyenne->id, 'qte' => 2, 'type_dechet' => 'compostable'],
            ['taille_poubelle_id' => $tailleGrande->id, 'qte' => 1, 'type_dechet' => 'compostable'],
        ],
        'type_dechet' => 'compostable',
        'montant' => 10000,
        'adresse' => 'Kinshasa',
        'latitude' => 0,
        'longitude' => 0,
    ])->assertCreated()
        ->assertJsonPath('tailles_poubelles.0.pivot.qte', 2)
        ->assertJsonPath('tailles_poubelles.1.pivot.qte', 1);

    $collecte = Collecte::first();
    expect($collecte->menage_id)->toBe($menageUser->id)
        ->and($collecte->statut)->toBe('en_attente')
        ->and($collecte->type_dechet)->toBe('compostable')
        ->and($collecte->taillesPoubelles()->orderBy('tailles_poubelles.id')->pluck('collecte_taille_poubelle.qte')->all())->toBe([2, 1])
        ->and($collecte->affectations()->count())->toBe(0);

    $this->actingAs($ramasseurUser, 'sanctum')
        ->getJson('/api/ramasseur/collectes/disponibles')
        ->assertOk()
        ->assertJsonFragment(['id' => $collecte->id]);

    $this->actingAs($menageUser, 'sanctum')
        ->getJson('/api/menage/collectes')
        ->assertOk()
        ->assertJsonFragment(['menage_id' => $menageUser->id]);

    Garanty::create([
        'ramasseur_id' => $ramasseurUser->id,
        'montant' => 10000,
        'reste' => 10000,
        'currency' => 'CDF',
    ]);

    $this->actingAs($ramasseurUser, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Demande acceptée.');

    expect($collecte->fresh()->statut)->toBe('acceptee')
        ->and($collecte->fresh()->ramasseur_id)->toBe($ramasseurUser->id)
        ->and($collecte->affectations()->count())->toBe(1)
        ->and($collecte->affectations()->first()->ramasseur_id)->toBe($ramasseurUser->id)
        ->and($collecte->affectations()->first()->statut)->toBe('acceptee');

    $this->actingAs($menageUser, 'sanctum')
        ->getJson("/api/collectes/{$collecte->id}/statut")
        ->assertOk()
        ->assertExactJson(['statut' => 'acceptee']);

    $this->actingAs($secondRamasseur, 'sanctum')
        ->getJson("/api/collectes/{$collecte->id}/statut")
        ->assertForbidden();

    $this->actingAs($secondRamasseur, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertStatus(409);

    expect($collecte->affectations()->count())->toBe(1);

    $this->actingAs($ramasseurUser, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/terminer")
        ->assertOk();

    expect($collecte->fresh()->statut)->toBe('terminee');
});

test('le ramasseur annule son affectation et le menage peut ensuite annuler la collecte', function () {
    $menageUser = User::factory()->create(['role' => 'menage']);
    $ramasseurUser = User::factory()->create(['role' => 'ramasseur']);
    $secondRamasseur = User::factory()->create(['role' => 'ramasseur']);
    $taille = TaillePoubelle::create(['nom' => 'Moyenne', 'capacite' => 60, 'unite' => 'L']);

    $this->actingAs($menageUser, 'sanctum')->postJson('/api/collectes', [
        'tailles_poubelles' => [
            ['taille_poubelle_id' => $taille->id, 'qte' => 1, 'type_dechet' => 'compostable'],
        ],
        'montant' => 10000,
        'adresse' => 'Kinshasa',
        'latitude' => 0,
        'longitude' => 0,
    ])->assertCreated();

    $collecte = Collecte::firstOrFail();
    Garanty::create([
        'ramasseur_id' => $ramasseurUser->id,
        'montant' => 10000,
        'reste' => 10000,
        'currency' => 'CDF',
    ]);
    Garanty::create([
        'ramasseur_id' => $secondRamasseur->id,
        'montant' => 10000,
        'reste' => 10000,
        'currency' => 'CDF',
    ]);

    $this->actingAs($ramasseurUser, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertOk();

    $this->actingAs($ramasseurUser, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/annuler", ['motif_annulation' => 'Indisponible'])
        ->assertOk();

    expect($collecte->fresh()->statut)->toBe('en_attente')
        ->and($collecte->fresh()->ramasseur_id)->toBeNull()
        ->and($collecte->affectations()->first()->statut)->toBe('annulee');

    $this->actingAs($secondRamasseur, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertOk();

    $this->actingAs($menageUser, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/cancel", ['motif_annulation' => 'Demande annulée'])
        ->assertOk();

    expect($collecte->fresh()->statut)->toBe('annulee')
        ->and($collecte->affectations()->where('ramasseur_id', $secondRamasseur->id)->value('statut'))->toBe('annulee');
});

test('une collecte refuse une catégorie de déchet inconnue', function () {
    $menageUser = User::factory()->create(['role' => 'menage']);
    $taille = TaillePoubelle::create(['nom' => 'Moyenne', 'capacite' => 60, 'unite' => 'L']);

    $this->actingAs($menageUser, 'sanctum')->postJson('/api/collectes', [
        'tailles_poubelles' => [['taille_poubelle_id' => $taille->id, 'qte' => 1]],
        'type_dechet' => 'compostable',
        'adresse' => 'Kinshasa',
        'latitude' => 0,
        'longitude' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors(['type_dechet']);
});
