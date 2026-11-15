<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collecte;
use App\Models\CollecteAffectation;
use App\Models\Ramasseur;
use App\Models\UserNotification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CollecteController extends Controller
{
    public function store(Request $request)
    {
        abort_unless($request->user()->isMenage(), 403);
        $data = $request->validate([
            'taille_poubelle_id' => ['required', 'exists:tailles_poubelles,id'],
            'adresse' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'dechets' => ['required', 'array', 'min:1'],
            'dechets.*.type_dechet_id' => ['required', 'exists:type_dechets,id'],
            'dechets.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'dechets.*.unite' => ['required', 'string', 'max:20']
        ]);
        $collecte = DB::transaction(function () use ($request, $data) {
            $menage = $request->user()->menage;
            $collecte = Collecte::create([
                'reference' => 'TEMP-' . uniqid(),
                'menage_id' => $menage->id,
                'taille_poubelle_id' => $data['taille_poubelle_id'],
                'adresse' => $data['adresse'],
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'statut' => 'en_attente',
                'demande_at' => now()
            ]);

            $collecte->update(['reference' => 'COL-' . now()->format('Y') . '-' . str_pad((string) $collecte->id, 6, '0', STR_PAD_LEFT)]);

            $collecte->typesDechets()->attach(collect($data['dechets'])->mapWithKeys(fn($dechet) => [$dechet['type_dechet_id'] => ['quantite' => $dechet['quantite'], 'unite' => $dechet['unite']]])->all());

            $ramasseurs = User::where('disponible', true)->whereNotNull('latitude')->whereNotNull('longitude')->get()->filter(fn($ramasseur) => $this->distance($data['latitude'], $data['longitude'], $ramasseur->latitude, $ramasseur->longitude) <= (float) config('app.collecte_radius_km', 10));
            foreach ($ramasseurs as $ramasseur) {
                CollecteAffectation::create(['collecte_id' => $collecte->id, 'ramasseur_id' => $ramasseur->id, 'statut' => 'en_attente', 'notifie_at' => now()]);
                UserNotification::create(['user_id' => $ramasseur->id, 'type' => 'nouvelle_collecte', 'titre' => 'Nouvelle demande de collecte', 'message' => 'Une collecte est disponible près de vous.', 'donnees' => ['collecte_id' => $collecte->id]]);
            }
            $collecte->update(['statut' => 'disponible']);
            return $collecte;
        });

        return response()->json($collecte->load(['taillePoubelle', 'typesDechets', 'affectations']), 201);
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isMenage(), 403);
        $query = $request->user()->menage->collectes()->with(['taillePoubelle', 'ramasseur.user']);
        if ($request->filled('statut')) {
            $query->where('statut', $request->string('statut'));
        }
        return response()->json($query->latest()->get());
    }

    public function disponibles(Request $request)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        $ramasseur = $request->user()->ramasseur;
        return response()->json($ramasseur->affectations()->where('statut', 'en_attente')->with('collecte')->get()->pluck('collecte')->filter(fn($collecte) => $collecte->statut === 'disponible')->values());
    }

    public function proches(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180']
        ]);

        $ramasseurs = User::where('disponible', true)->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->map(function ($ramasseur) use ($data) {
                $ramasseur->distance = round($this->distance($data['latitude'], $data['longitude'], $ramasseur->latitude, $ramasseur->longitude), 2);
                return $ramasseur;
            })->filter(fn($ramasseur) => $ramasseur->distance <= (float) config('app.collecte_radius_km', 10))->values();

        return response()->json(['ramasseurs' => $ramasseurs->map(fn($ramasseur) => ['id' => $ramasseur->id, 'prenom' => $ramasseur->user->prenom, 'latitude' => $ramasseur->latitude, 'longitude' => $ramasseur->longitude, 'distance' => $ramasseur->distance])]);
    }

    public function show(Request $request, Collecte $collecte)
    {
        abort_unless($this->canSee($request, $collecte), 403);
        return response()->json($collecte->load(['menage.user', 'ramasseur.user', 'taillePoubelle', 'typesDechets', 'affectations']));
    }

    public function accepter(Request $request, Collecte $collecte)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        $ramasseur = $request->user()->ramasseur;
        DB::transaction(function () use ($collecte, $ramasseur) {
            $collecte = Collecte::whereKey($collecte->id)->lockForUpdate()->firstOrFail();
            abort_unless($collecte->statut === 'disponible', 409, 'Cette demande a déjà été prise en charge.');
            $affectation = $collecte->affectations()->where('ramasseur_id', $ramasseur->id)->where('statut', 'en_attente')->first();
            abort_unless($affectation, 403);
            $collecte->update(['ramasseur_id' => $ramasseur->id, 'statut' => 'acceptee', 'acceptee_at' => now()]);
            $affectation->update(['statut' => 'acceptee', 'repondu_at' => now()]);
            $collecte->affectations()->where('id', '!=', $affectation->id)->where('statut', 'en_attente')->update(['statut' => 'expiree', 'repondu_at' => now()]);
            UserNotification::create(['user_id' => $collecte->menage->user_id, 'type' => 'collecte_acceptee', 'titre' => 'Demande acceptée', 'message' => 'Votre demande a été acceptée.', 'donnees' => ['collecte_id' => $collecte->id]]);
        });
        return $this->show($request, $collecte->fresh());
    }

    public function refuser(Request $request, Collecte $collecte)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        $affectation = $collecte->affectations()->where('ramasseur_id', $request->user()->ramasseur->id)->where('statut', 'en_attente')->firstOrFail();
        $affectation->update(['statut' => 'refusee', 'repondu_at' => now()]);
        return response()->json(['message' => 'Demande refusée.']);
    }

    public function transition(Request $request, Collecte $collecte, string $statut)
    {
        abort_unless($request->user()->isRamasseur() && $collecte->ramasseur_id === $request->user()->ramasseur->id, 403);
        $statut = ['en-route' => 'en_route', 'arrive' => 'arrive', 'demarrer' => 'en_collecte', 'terminer' => 'terminee'][$statut] ?? $statut;
        $transitions = ['en_route' => ['acceptee', 'en_route_at'], 'arrive' => ['en_route', 'arrivee_at'], 'en_collecte' => ['arrive', 'collecte_at'], 'terminee' => ['en_collecte', 'terminee_at']];
        abort_unless(isset($transitions[$statut]) && $collecte->statut === $transitions[$statut][0], 409, 'Transition de statut invalide.');
        $collecte->update(['statut' => $statut, $transitions[$statut][1] => now()]);
        return $this->show($request, $collecte->fresh());
    }

    public function annuler(Request $request, Collecte $collecte)
    {
        abort_unless($this->canSee($request, $collecte), 403);
        abort_unless(in_array($collecte->statut, ['en_attente', 'disponible', 'acceptee', 'en_route'], true), 409, 'Cette collecte ne peut plus être annulée.');
        $data = $request->validate(['motif_annulation' => ['required', 'string']]);
        $collecte->update(['statut' => 'annulee', 'annulee_at' => now(), 'motif_annulation' => $data['motif_annulation']]);
        $collecte->affectations()->where('statut', 'en_attente')->update(['statut' => 'annulee']);
        return $this->show($request, $collecte->fresh());
    }

    private function canSee(Request $request, Collecte $collecte): bool
    {
        $user = $request->user();
        return ($user->isMenage() && $collecte->menage?->user_id === $user->id) || ($user->isRamasseur() && $collecte->ramasseur?->user_id === $user->id);
    }

    private function distance(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
    {
        $earthRadius = 6371;
        $latitude = deg2rad($latitudeB - $latitudeA);
        $longitude = deg2rad($longitudeB - $longitudeA);
        $value = sin($latitude / 2) ** 2 + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($longitude / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($value), sqrt(1 - $value));
    }
}
