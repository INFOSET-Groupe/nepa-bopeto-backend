<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RamasseurController extends Controller
{
    public function profil(Request $request)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        return response()->json($request->user());
    }

    public function disponibilite(Request $request)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        $data = $request->validate([
            'disponible' => ['required', 'boolean'],
            'latitude' => ['required_if:disponible,true', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:disponible,true', 'numeric', 'between:-180,180'],
            'precision_position' => ['nullable', 'numeric', 'min:0']
        ]);

        $ramasseur = $request->user();

        $ramasseur->update([
            'disponible' => $data['disponible'],
            ...collect($data)->only([
                'latitude',
                'longitude',
                'precision_position'
            ])->all(),
            'derniere_position_at' => $data['disponible'] ? now() : $ramasseur->derniere_position_at
        ]);


        //$ramasseur->fresh()
        return response()->json([
            'status' => 'success',
            'message' => 'Disponibilité mise à jour avec succès',
            'data' => [
                'disponible' => $ramasseur->disponible,
                'latitude' => $ramasseur->latitude,
                'longitude' => $ramasseur->longitude,
                'precision_position' => $ramasseur->precision_position
            ]
        ]);
    }

    public function position(Request $request)
    {
        abort_unless($request->user()->isRamasseur(), 403);
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'precision_position' => ['nullable', 'numeric', 'min:0'],
            'collecte_id' => ['nullable', 'exists:collectes,id']
        ]);
        $ramasseur = $request->user();
        $ramasseur->update([...collect($data)->only([
            'latitude',
            'longitude',
            'precision_position'
        ])->all(), 'derniere_position_at' => now()]);

        return response()->json($ramasseur->fresh());
    }

}
