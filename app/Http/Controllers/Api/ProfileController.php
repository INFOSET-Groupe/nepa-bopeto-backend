<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        return response()->json($user);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'nom' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'string', 'max:20', 'unique:users,phone,' . $user->id],
            'avatar' => ['sometimes', 'string', 'max:7000000'],
            'adresse' => ['sometimes', 'string'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180']
        ]);

        $oldAvatarPath = null;
        if (isset($data['avatar'])) {
            $oldAvatarPath = $this->storedAvatarPath($user->avatar);
            $avatar = $data['avatar'];
            if (preg_match('#\Adata:image/[a-zA-Z0-9.+-]+;base64,#', $avatar, $matches)) {
                $avatar = substr($avatar, strlen($matches[0]));
            }

            $image = base64_decode($avatar, true);
            $imageInfo = $image === false ? false : getimagesizefromstring($image);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
            ];

            if ($imageInfo === false || ! isset($extensions[$imageInfo['mime']]) || strlen($image) > 5 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'avatar' => 'L’avatar doit être une image JPEG, PNG, GIF ou WebP valide de 5 Mo maximum.',
                ]);
            }

            $path = 'avatars/' . Str::uuid() . '.' . $extensions[$imageInfo['mime']];
            if (! Storage::disk('public')->put($path, $image)) {
                throw new RuntimeException('Impossible d’enregistrer le fichier avatar.');
            }

            $data['avatar'] = Storage::disk('public')->url($path);
        }

        $user->update(collect($data)->only(['nom', 'phone', 'email', 'avatar', 'adresse', 'latitude', 'longitude'])->all());

        if ($oldAvatarPath !== null && ! Storage::disk('public')->delete($oldAvatarPath)) {
            throw new RuntimeException('Impossible de supprimer l’ancien avatar.');
        }

        return $this->show($request);
    }

    private function storedAvatarPath(?string $avatarUrl): ?string
    {
        if ($avatarUrl === null) {
            return null;
        }

        $avatarPrefix = rtrim(Storage::disk('public')->url('avatars'), '/') . '/';
        if (! str_starts_with($avatarUrl, $avatarPrefix)) {
            return null;
        }

        $filename = substr($avatarUrl, strlen($avatarPrefix));
        if (! preg_match('/\A[0-9a-f-]{36}\.(?:jpg|png|gif|webp)\z/i', $filename)) {
            return null;
        }

        return 'avatars/' . $filename;
    }
}
