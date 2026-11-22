<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{

    public function notlogin()
    {
        return response()->json(['message' => 'Vous devez être connecté pour accéder à cette ressource.'], 401);
    }

    public function register(Request $request)
    {
        $role = $request->input('role');
        $data = $request->validate($this->registrationRules());
        $role = $data['role'];

        //unset($data['role'], $data['adresse'], $data['latitude'], $data['longitude']);
        $user = User::create([...$data, 'role' => $role]);

        return $this->tokenResponse($user, 201);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string']
        ]);

        $user = User::where('phone', $credentials['username'])->orWhere('username', $credentials['username'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password) || ! $user->isActif()) {
            return response()->json(['message' => 'Identifiants invalides ou compte inactif.'], 422);
        }

        $user->update(['last_seen_at' => now()]);
        return $this->tokenResponse($user);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Déconnexion réussie.']);
    }

    private function tokenResponse(User $user, int $status = 200)
    {
        return response()->json([
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'nom' => $user->nom,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'shortcode' => $user->flexpaie_code,
                'role' => $user->role,
                'statut' => $user->statut,
                'last_seen_at' => $user->last_seen_at,
                'longitude' => $user->longitude ?? null,
                'latitude' => $user->latitude ?? null,
                'precision_position' => $user->precision_position ?? null,
                'adresse' => $user->adresse ?? null,
                'disponible' => $user->disponible ?? null,
            ],
            'token' => $user->createToken('mobile')->plainTextToken],
            $status
        );
    }

    private function registrationRules()
    {
        $rules = [
            'role' => ['required', 'string', 'in:menage,ramasseur'],
            'username' => ['required', 'string', 'max:100'],
            'nom' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'adresse' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180']
        ];

        return $rules;
    }
}
