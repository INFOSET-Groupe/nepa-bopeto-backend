<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('un utilisateur peut mettre à jour son avatar avec une image base64', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $oldAvatarPath = 'avatars/' . Str::uuid() . '.jpg';
    Storage::disk('public')->put($oldAvatarPath, 'old avatar');
    $user->update(['avatar' => Storage::disk('public')->url($oldAvatarPath)]);

    $response = $this->actingAs($user, 'sanctum')->putJson('/api/menage/profil', [
        'avatar' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j+ioAAAAASUVORK5CYII=',
    ]);

    $response->assertOk();
    $avatarUrl = $response->json('avatar');
    expect($avatarUrl)->toStartWith('http://localhost/storage/avatars/');
    Storage::disk('public')->assertExists(str_replace('http://localhost/storage/', '', $avatarUrl));
    Storage::disk('public')->assertMissing($oldAvatarPath);
    expect($user->fresh()->avatar)->toBe($avatarUrl);
});

test('un avatar qui n’est pas une image est refusé', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')->putJson('/api/menage/profil', [
        'avatar' => base64_encode('not an image'),
    ])->assertUnprocessable()->assertJsonValidationErrors('avatar');

    expect($user->fresh()->avatar)->toBeNull();
    Storage::disk('public')->assertDirectoryEmpty('avatars');
});
