<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Collecte;
use App\Models\TaillePoubelle;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CollecteController extends Controller
{

    public function options()
    {
        try {
            $taillesPoubelles = TaillePoubelle::all();

            return response()->json([
                'success' => true,
                'data' => $taillesPoubelles,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la récupération des options de collecte.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function store(Request $request)
    {
        //abort_unless($request->user()->isMenage(), 403);
        $data = $request->validate([
            'tailles_poubelles' => ['required', 'array', 'min:1'],
            'tailles_poubelles.*.taille_poubelle_id' => ['required', 'integer', 'distinct', 'exists:tailles_poubelles,id'],
            'tailles_poubelles.*.qte' => ['required', 'integer', 'min:1'],
            'tailles_poubelles.*.type_dechet' => ['required', 'in:compostable,recyclable,residuel'],
            'montant' => ['required', 'numeric', 'min:0'],
            'adresse' => ['required', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'dechets_tries' => ['nullable', 'boolean'],
            'mode_paiement' => ['nullable', 'string'],
            'planning_at' => ['nullable', 'date', 'after_or_equal:now'],
            'instructions' => ['nullable', 'string'],
        ]);

        try {
            $collecte = DB::transaction(function () use ($request, $data) {
                $user = $request->user();
                $collecte = Collecte::create([
                    'reference' => 'TEMP-' . uniqid(),
                    'menage_id' => $user->id,
                    'adresse' => $data['adresse'],
                    'montant' => $data['montant'],
                    'latitude' => $data['latitude'],
                    'longitude' => $data['longitude'],
                    'statut' => 'en_attente',
                    'planning_at' => $data['planning_at'] ?? null,
                    'instructions' => $data['instructions'] ?? null,
                    'dechets_tries' => $data['dechets_tries'] ?? false,
                    'mode_paiement' => $data['mode_paiement'] ?? null,
                    'demande_at' => now()
                ]);

                foreach ($data['tailles_poubelles'] as $taille) {
                    $collecte->taillesPoubelles()->attach($taille['taille_poubelle_id'], ['qte' => $taille['qte'], 'type_dechet' => $taille['type_dechet']]);
                }

                $collecte->update(['reference' => 'COL-' . now()->format('Y') . '-' . str_pad((string) $collecte->id, 6, '0', STR_PAD_LEFT)]);

                $ramasseurs = User::where('role', 'ramasseur')->where('disponible', true)->whereNotNull('latitude')->whereNotNull('longitude')->get()->filter(fn($ramasseur) => $this->distance($data['latitude'], $data['longitude'], $ramasseur->latitude, $ramasseur->longitude) <= (float) config('app.collecte_radius_km', 10));
                foreach ($ramasseurs as $ramasseur) {
                    UserNotification::create(['user_id' => $ramasseur->id, 'type' => 'nouvelle_collecte', 'titre' => 'Nouvelle demande de collecte', 'message' => 'Une collecte est disponible près de vous.', 'donnees' => ['collecte_id' => $collecte->id]]);
                }
                return $collecte;
            });

            return response()->json([
                "success" => true,
                'message' => 'Demande de collecte créée avec succès.',
                'collecte' => $collecte->load('taillesPoubelles')
            ], 201);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la création d’une collecte.', [
                'user_id' => $request->user()?->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    // update collecte par ramasseur au cas ou il constate sur le terrain que le menage n'a pas renseigné correctement les tailles de poubelles ou les types de déchets
    public function update(Request $request, Collecte $collecte)
    {
        abort_unless($request->user()->isRamasseur() && $collecte->ramasseur_id === $request->user()->id, 403, 'Seul le ramasseur affecté peut modifier cette collecte.');
        abort_unless(in_array($collecte->statut, ['acceptee'], true), 409, 'Cette collecte ne peut pas être modifiée.');
        $data = $request->validate([
            'tailles_poubelles' => ['required', 'array', 'min:1'],
            'tailles_poubelles.*.taille_poubelle_id' => ['required', 'integer', 'distinct', 'exists:tailles_poubelles,id'],
            'tailles_poubelles.*.qte' => ['required', 'integer', 'min:1'],
            'tailles_poubelles.*.type_dechet' => ['required', 'in:compostable,recyclable,residuel'],
            'montant' => ['required', 'numeric', 'min:0'],
            'dechets_tries' => ['nullable', 'boolean'],
        ]);

        try {
            DB::transaction(function () use ($collecte, $data) {
                $collecte->taillesPoubelles()->detach();
                $collecte->update([
                    'dechets_tries' => $data['dechets_tries'] ?? false,
                ]);
                foreach ($data['tailles_poubelles'] as $taille) {
                    $collecte->taillesPoubelles()->attach($taille['taille_poubelle_id'], ['qte' => $taille['qte'], 'type_dechet' => $taille['type_dechet']]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Collecte mise à jour avec succès.',
                'collecte' => $collecte->load('taillesPoubelles'),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la mise à jour d’une collecte.', [
                'collecte_id' => $collecte->id,
                'user_id' => $request->user()->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function ranningToggle(Request $request, Collecte $collecte)
    {
        // abort_unless($request->user()->isRamasseur() && $collecte->ramasseur_id === $request->user()->id, 403, 'Seul le ramasseur affecté ');

        try {

            $collecte->update(['pluie' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Mode de pluie mis à jour avec succès.',
            ]);
        } catch (\Throwable $exception) {
            Log::error("Erreur lors d'activation mode pulie.", [
                'collecte_id' => $collecte->id,
                'user_id' => $request->user()->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->isMenage(), 403);
        try {
            $query = $request->user()->collectes()->with(['taillesPoubelles', 'ramasseur']);
            if ($request->filled('statut')) {
                $query->where('statut', $request->string('statut'));
            }
            return response()->json([
                'success' => true,
                'collectes' => $query->latest()->get(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la récupération des collectes du ménage.', [
                'user_id' => $request->user()->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function disponibles(Request $request)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        try {
            $collectes = Collecte::where('statut', 'en_attente')->with('taillesPoubelles')->get();
            return response()->json([
                'success' => true,
                'collectes' => $collectes,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la récupération des collectes disponibles.', [
                'user_id' => $request->user()->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function proches(Request $request)
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180']
        ]);

        try {
            $ramasseurs = User::where('role', 'ramasseur')->where('disponible', true)->whereNotNull('latitude')->whereNotNull('longitude')->get()
                ->map(function ($ramasseur) use ($data) {
                    $ramasseur->distance = round($this->distance($data['latitude'], $data['longitude'], $ramasseur->latitude, $ramasseur->longitude), 2);
                    return $ramasseur;
                })->filter(fn($ramasseur) => $ramasseur->distance <= (float) config('app.collecte_radius_km', 10))->values();

            return response()->json([
                'success' => true,
                'ramasseurs' => $ramasseurs->map(fn($ramasseur) => ['id' => $ramasseur->id, 'prenom' => $ramasseur->nom, 'latitude' => $ramasseur->latitude, 'longitude' => $ramasseur->longitude, 'distance' => $ramasseur->distance]),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la recherche des ramasseurs proches.', [
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function show(Request $request, Collecte $collecte)
    {
        $user = $request->user();
        abort_unless(($user->isMenage() && $collecte->menage_id === $user->id) || ($user->isRamasseur() && $collecte->ramasseur_id === $user->id), 403);
        try {
            return response()->json([
                'success' => true,
                'collecte' => $collecte->load(['menage', 'ramasseur', 'taillesPoubelles', 'affectations.ramasseur']),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la récupération d’une collecte.', [
                'collecte_id' => $collecte->id,
                'user_id' => $user->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function statut(Request $request, Collecte $collecte)
    {
        $user = $request->user();
        abort_unless(($user->isMenage() && $collecte->menage_id === $user->id) || ($user->isRamasseur() && $collecte->ramasseur_id === $user->id), 403, 'Vous n’êtes pas autorisé à accéder au statut de cette collecte.');

        return response()->json([
            'success' => true,
            'statut' => $collecte->statut,
        ]);
    }

    public function accepted(Request $request)
    {
        $user = $request->user();
        abort_unless($user->isRamasseur(), 403);
        try {

            $lastAcceptedCollecte = Collecte::where('ramasseur_id', $user->id)->where('statut', 'acceptee')->latest('acceptee_at')->first();
            if (!$lastAcceptedCollecte) {
                return response()->json([
                    'collecte' => null,
                    'message' => 'Aucune collecte acceptée trouvée.',
                ]);
            }
            return response()->json([
                'success' => true,
                'message' => 'Dernière collecte acceptée récupérée avec succès.',
                'collecte' => $lastAcceptedCollecte->load(['menage', 'ramasseur', 'taillesPoubelles', 'affectations']),
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la récupération de la dernière collecte accepté par le ramasseur.', [
                'user_id' => $user->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function accepter(Request $request, Collecte $collecte)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        $ramasseur = $request->user();
        $lastAcceptedCollecte = Collecte::where('ramasseur_id', $ramasseur->id)->where('statut', 'acceptee')->latest('acceptee_at')->first();

        if ($lastAcceptedCollecte) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà une collecte en cours. Terminez la avant d’accepter une nouvelle.',
            ], 409);
        }

        try {
            DB::transaction(function () use ($collecte, $ramasseur) {
                $collecte = Collecte::whereKey($collecte->id)->lockForUpdate()->firstOrFail();
                abort_unless($collecte->statut === 'en_attente', 409, 'Cette demande a déjà été prise en charge.');
                $collecte->affectations()->updateOrCreate(
                    ['ramasseur_id' => $ramasseur->id],
                    ['statut' => 'acceptee', 'repondu_at' => now()]
                );
                $collecte->update([
                    'ramasseur_id' => $ramasseur->id,
                    'statut' => 'acceptee',
                    'acceptee_at' => now(),
                    'terminee_at' => null,
                    'annulee_at' => null,
                    'motif_annulation' => null,
                ]);
                UserNotification::create(['user_id' => $collecte->menage_id, 'type' => 'collecte_acceptee', 'titre' => 'Demande acceptée', 'message' => 'Votre demande a été acceptée.', 'donnees' => ['collecte_id' => $collecte->id]]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Demande acceptée.',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de l’acceptation d’une collecte.', [
                'collecte_id' => $collecte->id,
                'ramasseur_id' => $ramasseur->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    // anulation du menage
    public function annuler(Request $request, Collecte $collecte)
    {
        $menage = $request->user();
        abort_unless($menage->isMenage() && $collecte->menage_id === $menage->id, 403);

        try {
            DB::transaction(function () use ($collecte) {
                $collecte = Collecte::whereKey($collecte->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($collecte->statut, ['en_attente', 'acceptee'], true), 409, 'Cette collecte ne peut plus être annulée.');
                $collecte->update([
                    'statut' => 'annulee',
                    'annulee_at' => now(),
                    'ramasseur_id' => null,
                ]);
                $collecte->affectations()->where('statut', 'acceptee')->update([
                    'statut' => 'annulee'
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Collecte annulée avec succès.',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de l’annulation d’une collecte par le ménage.', [
                'collecte_id' => $collecte->id,
                'menage_id' => $menage->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    // anulation du ramasseur
    public function interrompre(Request $request, Collecte $collecte)
    {
        $ramasseur = $request->user();
        abort_unless($ramasseur->isRamasseur(), 403);
        $data = $request->validate(['motif_annulation' => ['required', 'string']]);
        try {
            DB::transaction(function () use ($collecte, $ramasseur, $data) {
                $collecte = Collecte::whereKey($collecte->id)->lockForUpdate()->firstOrFail();
                abort_unless($collecte->ramasseur_id === $ramasseur->id,409,'Seul le ramasseur affecté peut annuler cette collecte.');
                abort_unless($collecte->statut === 'acceptee',409,'Cette collecte ne peut pas être annulée.');
                $affectation = $collecte->affectations()
                    ->where('ramasseur_id', $ramasseur->id)
                    ->where('statut', 'acceptee')
                    ->firstOrFail();
                $affectation->update(['statut' => 'annulee', 'repondu_at' => now()]);
                $collecte->update([
                    'statut' => 'en_attente',
                    'annulee_at' => now(),
                    'acceptee_at' => null,
                    'ramasseur_id' => null,
                    'motif_annulation' => $data['motif_annulation'],
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Collecte annulée avec succès.',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de l’interruption d’une collecte par le ramasseur.', [
                'collecte_id' => $collecte->id,
                'ramasseur_id' => $ramasseur->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function cloturer(Request $request, Collecte $collecte)
    {
        $ramasseur = $request->user();
        abort_unless($ramasseur->isRamasseur(), 403);
        try {
            DB::transaction(function () use ($collecte, $ramasseur) {
                $collecte = Collecte::whereKey($collecte->id)->lockForUpdate()->firstOrFail();
                abort_unless($collecte->statut === 'acceptee' && $collecte->ramasseur_id === $ramasseur->id, 409,
                    'Seul le ramasseur affecté peut clôturer cette collecte.'
                );
                $affectation = $collecte->affectations()
                    ->where('ramasseur_id', $ramasseur->id)
                    ->where('statut', 'acceptee')
                    ->firstOrFail();
                $affectation->update(['statut' => 'cloturee', 'repondu_at' => now()]);
                $collecte->update(['statut' => 'cloturee', 'cloturee_at' => now()]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Collecte clôturée avec succès.',
            ]);
        } catch (\Throwable $exception) {
            Log::error('Erreur lors de la clôture d’une collecte.', [
                'collecte_id' => $collecte->id,
                'ramasseur_id' => $ramasseur->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
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
