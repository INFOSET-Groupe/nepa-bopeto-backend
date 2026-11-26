<?php

use App\Models\Collecte;
use App\Models\Garanty;
use App\Models\Payment;
use App\Models\User;
use App\Services\FlexpaieService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCollectionForGuaranteeTests(User $menage, ?User $ramasseur = null, float $amount = 10000): Collecte
{
    return Collecte::create([
        'reference' => 'COL-'.uniqid(),
        'menage_id' => $menage->id,
        'ramasseur_id' => $ramasseur?->id,
        'montant' => $amount,
        'adresse' => 'Kinshasa',
        'latitude' => 0,
        'longitude' => 0,
        'statut' => $ramasseur ? 'acceptee' : 'en_attente',
    ]);
}

test('un ramasseur ne peut accepter une collecte sans une garantie couvrant 30 pour cent', function () {
    $menage = User::factory()->create(['role' => 'menage']);
    $ramasseur = User::factory()->create(['role' => 'ramasseur']);
    $collecte = createCollectionForGuaranteeTests($menage);

    Garanty::create([
        'ramasseur_id' => $ramasseur->id,
        'montant' => 2999.99,
        'reste' => 2999.99,
        'currency' => 'CDF',
    ]);

    $this->actingAs($ramasseur, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertStatus(409);

    expect($collecte->fresh()->statut)->toBe('en_attente');

    $ramasseur->garanty()->first()->update([
        'montant' => 3000,
        'reste' => 3000,
    ]);

    $this->actingAs($ramasseur, 'sanctum')
        ->postJson("/api/collectes/{$collecte->id}/accepter")
        ->assertOk();

    expect($collecte->fresh()->statut)->toBe('acceptee');
});

test('le paiement cash termine la collecte et débite 20 pour cent de la garantie une seule fois', function () {
    $menage = User::factory()->create(['role' => 'menage']);
    $ramasseur = User::factory()->create(['role' => 'ramasseur']);
    $collecte = createCollectionForGuaranteeTests($menage, $ramasseur);
    $garanty = Garanty::create([
        'ramasseur_id' => $ramasseur->id,
        'montant' => 5000,
        'reste' => 5000,
        'currency' => 'CDF',
    ]);

    $payload = [
        'collecte_id' => $collecte->id,
        'montant' => 8000,
        'currency' => 'CDF',
        'methode' => 'cash',
    ];

    $this->actingAs($menage, 'sanctum')
        ->postJson('/api/menage/collecte/pay', $payload)
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($collecte->fresh()->statut)->toBe('terminee')
        ->and((float) $garanty->fresh()->reste)->toBe(3400.0)
        ->and(Payment::where('collecte_id', $collecte->id)->where('type', 'collecte')->where('status', 'success')->count())->toBe(1);

    $commission = Payment::where('type', 'commission')->firstOrFail();
    expect((float) $commission->montant)->toBe(1600.0)
        ->and($commission->currency)->toBe('CDF')
        ->and($commission->user_id)->toBe($ramasseur->id)
        ->and($commission->garanty_id)->toBe($garanty->id)
        ->and($commission->collecte_id)->toBe($collecte->id);

    $this->actingAs($menage, 'sanctum')
        ->postJson('/api/menage/collecte/pay', $payload)
        ->assertStatus(409);

    expect((float) $garanty->fresh()->reste)->toBe(3400.0);
});

test('un paiement mobile confirmé débite la garantie une seule fois', function () {
    $menage = User::factory()->create(['role' => 'menage']);
    $ramasseur = User::factory()->create(['role' => 'ramasseur']);
    $collecte = createCollectionForGuaranteeTests($menage, $ramasseur);
    $garanty = Garanty::create([
        'ramasseur_id' => $ramasseur->id,
        'montant' => 5000,
        'reste' => 5000,
        'currency' => 'CDF',
    ]);

    Payment::create([
        'user_id' => $menage->id,
        'collecte_id' => $collecte->id,
        'type' => 'collecte',
        'montant' => 8000,
        'currency' => 'CDF',
        'status' => 'pending',
        'method' => 'mobile',
        'phone' => '243811111111',
        'reference' => 'PCOL-TEST-1',
        'order_number' => 'ORDER-TEST-1',
    ]);

    $this->mock(FlexpaieService::class, function ($mock) {
        $mock->shouldReceive('getPaymentStatus')
            ->twice()
            ->with('ORDER-TEST-1')
            ->andReturn([
                'code' => 0,
                'transaction' => ['status' => 0],
                'message' => 'Paiement confirmé.',
            ]);
    });

    $this->actingAs($menage, 'sanctum')
        ->getJson('/api/menage/collecte/check?order_number=ORDER-TEST-1')
        ->assertOk()
        ->assertJsonPath('payment_status', 'success');

    $this->actingAs($menage, 'sanctum')
        ->getJson('/api/menage/collecte/check?order_number=ORDER-TEST-1')
        ->assertOk()
        ->assertJsonPath('payment_status', 'success');

    expect($collecte->fresh()->statut)->toBe('terminee')
        ->and((float) $garanty->fresh()->reste)->toBe(3400.0)
        ->and(Payment::where('type', 'commission')->where('collecte_id', $collecte->id)->count())->toBe(1);
});
