<?php

namespace Database\Seeders;

use App\Models\Payment;
use App\Models\User;
use App\Models\TaillePoubelle;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $menageUser = User::factory()->create([
            'nom' => 'Menage Test',
            'username' => 'menage',
            'phone' => '243000000000',
            'role' => 'menage',
            'email' => 'menage@test.com',
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'password' => Hash::make('123456'),

        ]);

        $ramasseurUser = User::factory()->create([
            'nom' => 'Ramasseur Test',
            'username' => 'ramsseur',
            'phone' => '243000000001',
            'role' => 'ramasseur',
            'email' => 'ramasseur@test.com',
            'precision_position' => 5,
            'disponible' => true,
            'latitude' => -4.4419,
            'longitude' => 15.2663,
            'precision_position' => 5,
            'flexpaie_code' => fake()->unique()->numerify('#########'),
            'password' => Hash::make('123456'),
        ]);

        $garanty = $ramasseurUser->garanty()->create([
            'montant' => 10000.00,
            'reste' => 10000.00,
            'currency' => 'CDF',
            'ramasseur_id' => $ramasseurUser->id,
        ]);

        Payment::create([
            'user_id' => $ramasseurUser->id,
            'garanty_id' => $garanty->id,
            'type' => 'garanty',
            'montant' => 10000.00,
            'currency' => 'CDF',
            'status' => 'success',
        ]);

        foreach ([['L', 30, 2000.00], ['M', 60, 3500.00], ['XL', 120, 6500.00], ['2XL', 240, 12000.00]] as [$nom, $capacite, $amount]) {
            TaillePoubelle::firstOrCreate(['nom' => $nom], ['capacite' => $capacite, 'unite' => 'L', 'amount' => $amount, 'currency' => 'CDF', 'description' => $nom . ' poubelle de ' . $capacite . ' L']);
        }

    }
}
