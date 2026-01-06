<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function updateProvider(UpdateProfileRequest $request)
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'provider', 403);

        return $this->updateUser($request);
    }

    public function updateClient(UpdateProfileRequest $request)
    {
        $user = $request->user();
        abort_unless($user && $user->role === 'client', 403);

        return $this->updateUser($request);
    }

    protected function updateUser(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        if ($request->hasFile('avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $data['avatar_path'] = $request->file('avatar')->store('avatars', 'public');
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        if ($user->role !== 'provider') {
            unset($data['objetivo']);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'telefone' => $data['telefone'] ?? $user->telefone,
            'objetivo' => $data['objetivo'] ?? ($user->role === 'provider' ? $user->objetivo : null),
        ]);

        if (array_key_exists('avatar_path', $data)) {
            $user->avatar_path = $data['avatar_path'];
        }

        if (!empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        return $user->load('company');
    }
}
